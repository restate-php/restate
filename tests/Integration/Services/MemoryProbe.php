<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Services;

use Restate\Tests\Integration\Support\WorkerProbe;

/**
 * Per-attempt memory of the endpoint process, for the large-body services (large_bodies.php): the resident set size
 * from /proc/self/status, which counts the Rust copies (request body, journal, output) that memory_get_usage() cannot
 * see, next to PHP's own peak. Records go to a JSON-lines file per invocation id under RESTATE_PHP_TEST_STATE_DIR.
 *
 * The peak RSS (`VmHWM`) is reset (`/proc/self/clear_refs`) after each recorded request, so a worker's next probed
 * request starts from what it retains; requests in between that are not probed are small and hardly move it. On
 * anything but Linux the RSS fields are null.
 */
final readonly class MemoryProbe
{
    public function __construct(public string $stateDir)
    {
    }

    public static function fromEnvironment(): self
    {
        return new self(ChaosRecorder::fromEnvironment()->stateDir);
    }

    /**
     * Record the attempt's memory once the request is over (in a shutdown function, so after the response was built
     * and sent), with the RSS at this point of the handler for comparison. A FrankenPHP worker script runs its shutdown
     * functions only when it ends, after many requests, so there the worker loop runs it after the request
     * ({@see WorkerProbe::atRequestEnd()}) and PHP's peak is reset for the next one.
     */
    public function recordAtShutdown(string $invocationId, int $bytes): void
    {
        $rssAtHandler = self::status('VmRSS');
        $worker = WorkerProbe::current();
        $record = function () use ($invocationId, $bytes, $rssAtHandler, $worker): void {
            $record = [
                'pid' => getmypid(),
                'bytes' => $bytes,
                'rssAtHandlerKiB' => $rssAtHandler,
                'peakRssKiB' => self::status('VmHWM'),
                'phpPeakBytes' => memory_get_peak_usage(true),
            ];
            @file_put_contents($this->file($invocationId), json_encode($record, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
            if (is_writable('/proc/self/clear_refs')) {
                @file_put_contents('/proc/self/clear_refs', '5');
            }
            if ($worker !== null) {
                memory_reset_peak_usage();
            }
        };
        $worker === null ? register_shutdown_function($record) : $worker->atRequestEnd($record);
    }

    /**
     * What the attempts of an invocation recorded, in order.
     *
     * @return list<array{pid: int, bytes: int, rssAtHandlerKiB: ?int, peakRssKiB: ?int, phpPeakBytes: int}>
     */
    public function records(string $invocationId): array
    {
        $lines = is_file($this->file($invocationId)) ? file($this->file($invocationId), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;

        /** @var list<array{pid: int, bytes: int, rssAtHandlerKiB: ?int, peakRssKiB: ?int, phpPeakBytes: int}> */
        return array_map(static fn (string $line): array => (array) json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines === false ? [] : $lines);
    }

    private function file(string $invocationId): string
    {
        return $this->stateDir . '/memory-' . hash('sha256', $invocationId) . '.jsonl';
    }

    /** A `/proc/self/status` field in KiB, or null where there is none. */
    private static function status(string $field): ?int
    {
        $status = @file_get_contents('/proc/self/status');

        return is_string($status) && preg_match('/^' . preg_quote($field, '/') . ':\s+(\d+) kB$/m', $status, $match) === 1 ? (int) $match[1] : null;
    }
}
