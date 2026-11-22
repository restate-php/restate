<?php

declare(strict_types=1);

namespace Restate\Tests\Soak;

use PHPUnit\Framework\TestCase;
use Restate\Internal\CoreLogForwarder;
use Restate\Tests\Integration\Support\IntegrationEndpoints;
use Restate\Tests\Integration\Support\WorkerProbe;
use Restate\Tests\Soak\Support\Checkpoints;
use Restate\Tests\Soak\Support\SoakEndpoint;
use Restate\Tests\Soak\Support\SoakRequest;
use Restate\Tests\Soak\Support\SoakRun;
use Restate\Tests\Soak\Support\SoakSettings;
use Restate\Tests\Support\Workload\Workload;
use Restate\Tests\Support\Workload\WorkloadResponse;

/**
 * Many PHP threads of one FrankenPHP process serve requests at once (frame replay, no Restate).
 *
 * - shared-core's log events are captured per thread, by the one process-wide subscriber and the capture on top of
 *   the calling thread's stack (restate_php_core::logs): a journal mismatch's debug record names a token unique to
 *   its request, and must be logged with that request's invocation id, once, with no core record under an invocation
 *   that was not sent.
 * - A Rust panic (the panic probe of the debug build, inside `sysRun()`) fails its own request with a 500 and nothing
 *   else: every other request in flight is answered correctly, and the thread that panicked serves the next request
 *   in the same worker script run.
 * - Throughput with the SDK's logger on and off is reported, not gated.
 */
final class ConcurrencySoakTest extends TestCase
{
    private const string PANIC_PROBE = '__restate_php_panic_probe__';

    public function testCoreLogEventsStayWithTheirInvocationAndAPanicStaysInItsRequest(): void
    {
        $report = SoakSettings::reportDir('concurrency');
        $threads = SoakSettings::threads(8);
        $endpoint = SoakEndpoint::start(SoakSettings::debugImage(), $threads, [
            IntegrationEndpoints::CORE_LOG_LEVEL_VARIABLE => 'debug',
            'RESTATE_PHP_TEST_DISPLAY_ERRORS' => '0',
        ]);
        $run = new SoakRun($endpoint, concurrency: 2 * $threads);

        $mismatches = [];
        $make = static function (int $i) use (&$mismatches): SoakRequest {
            $token = bin2hex(random_bytes(8));
            $id = "inv_1workload{$token}";

            if ($i % 100 === 7) {
                return new SoakRequest(Workload::run(self::PANIC_PROBE, $id), 'none', self::panicked(...), 'panic-probe');
            }
            if ($i % 5 < 2) {
                $mismatches[$token] = 0;

                return new SoakRequest(Workload::journalMismatch($token, $id), 'none');
            }

            return match (true) {
                $i % 5 === 2 => new SoakRequest(Workload::logging($token, $id), 'none'),
                $i % 5 === 3 => new SoakRequest(Workload::replayRuns(10, $id), 'none'),
                default => new SoakRequest(Workload::start(invocationId: $id), 'none'),
            };
        };
        $rate = $run->phase('concurrent', SoakSettings::count(100_000, 4_000), $make, batches: 10);
        SoakSettings::report($report, sprintf('%d threads, %d in flight: %.0f requests/s; responses %s', $threads, 2 * $threads, $rate, json_encode($run->responses())));

        self::assertSame([], $run->unexpected(), 'A request other than a panic probe failed, or a probe did not get its 500.');
        self::assertSame([], array_filter($run->checkpoints->failures(['rust' => null, 'php' => null, 'rss' => null]), static fn (string $failure): bool => str_contains($failure, 'live_vms')), 'VMs left alive after the panics');

        // Every core record of a mismatch names its token; it must carry the invocation of that request.
        $foreign = [];
        $misattributed = [];
        foreach (self::records($endpoint->endpointLogFile()) as $record) {
            $invocationId = $record['context']['restate.invocation.id'] ?? null;
            if (!isset($record['context'][CoreLogForwarder::TARGET])) {
                continue;
            }
            if (!is_string($invocationId) || !str_starts_with($invocationId, 'inv_1workload')) {
                $foreign[] = $record;
            }
            if (preg_match('/mismatch-([0-9a-f]{16})/', $record['message'], $match) === 1) {
                $token = $match[1];
                if ($invocationId !== "inv_1workload{$token}") {
                    $misattributed[] = $record;
                }
                if (isset($mismatches[$token])) {
                    ++$mismatches[$token];
                }
            }
        }
        SoakSettings::report($report, sprintf('%d journal mismatches, %d misattributed core records, %d core records of unknown invocations', count($mismatches), count($misattributed), count($foreign)));

        self::assertSame([], array_slice($misattributed, 0, 5), 'Core records logged under another invocation.');
        self::assertSame([], array_slice($foreign, 0, 5), 'Core records under an invocation that was never sent.');
        self::assertSame([], array_keys(array_filter($mismatches, static fn (int $count): bool => $count !== 1)), 'Mismatches whose core record is missing or duplicated.');

        self::assertTrue($endpoint->isRunning());
        $panics = array_filter(explode("\n", $endpoint->logs()), static fn (string $line): bool => str_contains($line, 'panicked at'));
        self::assertNotEmpty($panics);
        self::assertSame([], array_values(array_filter($panics, static fn (string $line): bool => !str_contains($line, 'probe.rs'))), 'A panic other than the probe.');
    }

    public function testTheThreadThatPanickedServesTheNextRequest(): void
    {
        $endpoint = SoakEndpoint::start(SoakSettings::debugImage(), 1, [IntegrationEndpoints::LOGGER_VARIABLE => 'off', 'RESTATE_PHP_TEST_DISPLAY_ERRORS' => '0']);
        $run = new SoakRun($endpoint, concurrency: 1);

        $instances = [];
        $sameWorker = static function (WorkloadResponse $response) use (&$instances): void {
            $instances[] = (string) $response->header(WorkerProbe::HEADER_INSTANCE);
        };
        $make = static function (int $i) use ($sameWorker): SoakRequest {
            $id = 'inv_1workload' . bin2hex(random_bytes(8));
            if ($i % 2 === 0) {
                return new SoakRequest(Workload::run(self::PANIC_PROBE, $id), 'none', static function (WorkloadResponse $response) use ($sameWorker): bool {
                    $sameWorker($response);

                    return self::panicked($response);
                }, 'panic-probe');
            }
            $request = Workload::start(invocationId: $id);

            return new SoakRequest($request, 'none', static function (WorkloadResponse $response) use ($sameWorker, $request): bool {
                $sameWorker($response);

                return $response->isExpectedFor($request);
            });
        };
        $run->phase(Checkpoints::WARM_UP, 10, $make, batches: 1);
        $run->phase('alternating', 200, $make, batches: 4);

        self::assertSame([], $run->unexpected());
        self::assertCount(1, array_unique($instances), 'A panic restarted the worker script.');
        self::assertSame(0, $endpoint->stats()['diagnostics']['live_vms'] ?? null);
    }

    public function testThroughputWithTheLoggerOnAndOff(): void
    {
        $report = SoakSettings::reportDir('concurrency-throughput');
        $threads = SoakSettings::threads(8);
        $rates = [];
        foreach (['off' => 'off', 'on' => '/dev/null'] as $logger => $setting) {
            $endpoint = SoakEndpoint::start(SoakSettings::releaseImage(), $threads, [IntegrationEndpoints::LOGGER_VARIABLE => $setting, 'RESTATE_PHP_TEST_DISPLAY_ERRORS' => '0']);
            $run = new SoakRun($endpoint, concurrency: 2 * $threads);
            $make = static fn (int $i): SoakRequest => new SoakRequest(match ($i % 3) {
                0 => Workload::start(invocationId: 'inv_1workload' . bin2hex(random_bytes(8))),
                1 => Workload::journalMismatch(bin2hex(random_bytes(8)), 'inv_1workload' . bin2hex(random_bytes(8))),
                default => Workload::replayRuns(10, 'inv_1workload' . bin2hex(random_bytes(8))),
            }, 'none');
            $run->phase(Checkpoints::WARM_UP, 1_000, $make, batches: 1);
            $rates[$logger] = $run->phase('throughput', SoakSettings::count(60_000, 3_000), $make, batches: 1);
            $endpoint->stop();
            self::assertSame([], $run->unexpected());
        }

        SoakSettings::report($report, sprintf('%d threads: logger off %.0f requests/s, on %.0f requests/s, on/off %.2f (reported, not gated)', $threads, $rates['off'], $rates['on'], $rates['on'] / $rates['off']));
    }

    private static function panicked(WorkloadResponse $response): bool
    {
        return $response->status === 500 && str_contains($response->body, 'Rust panic');
    }

    /** @return \Generator<array{level: string, message: string, context: array<string, mixed>}> */
    private static function records(string $file): \Generator
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return;
        }
        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                /** @var array{level: string, message: string, context: array<string, mixed>} $record */
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                yield $record;
            }
        }
        fclose($handle);
    }
}
