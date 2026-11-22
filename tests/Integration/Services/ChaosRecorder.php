<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Services;

use Restate\Context;

/**
 * What the `Chaos` and `ChaosObject` services (chaos.php) record, in files under RESTATE_PHP_TEST_STATE_DIR that the
 * endpoint and the tests share, keyed by invocation id (the `x-restate-invocation-id` header Restate sends on every
 * attempt):
 * - **attempts**: one line per handler attempt that started, with its pid and time;
 * - **effects**: one line per execution of a named side effect (a run body), with the attempt it ran in;
 * - **values**: named strings the handler or the test keeps for the invocation ({@see put()}).
 *
 * A fault in a chaos handler is **armed** while its execution number is at most the request's `failures` (null: no
 * limit) and the test has not {@see disarm()}ed the invocation.
 */
final readonly class ChaosRecorder
{
    public function __construct(public string $stateDir)
    {
    }

    /** The recorder of the endpoint process (RESTATE_PHP_TEST_STATE_DIR, else the repository's var/). */
    public static function fromEnvironment(): self
    {
        $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR');

        return new self(is_string($stateDir) && $stateDir !== '' ? $stateDir : dirname(__DIR__, 3) . '/var');
    }

    /** The id of the invocation this attempt belongs to, as Restate sent it in the attempt's headers. */
    public static function invocationId(Context $ctx): string
    {
        return $ctx->request()->attemptHeaders['x-restate-invocation-id'] ?? $ctx->request()->id;
    }

    /** Record that an attempt of the invocation started; returns its number (1 for the first). */
    public function attempt(string $invocationId): int
    {
        return $this->append($this->file($invocationId, 'attempts'), [self::pid(), self::now()]);
    }

    /**
     * Record one execution of the side effect `$name` during attempt `$attempt`; returns how many executions it has
     * had, this one included.
     */
    public function effect(string $invocationId, string $name, int $attempt): int
    {
        return $this->append($this->file($invocationId, 'effect-' . self::slug($name)), [$attempt, self::pid(), self::now()]);
    }

    /**
     * The attempts that started, in order.
     *
     * @return list<array{pid: int, at: int}> `at` in milliseconds since the epoch
     */
    public function attempts(string $invocationId): array
    {
        return array_map(
            static fn (array $fields): array => ['pid' => $fields[0], 'at' => $fields[1]],
            $this->read($this->file($invocationId, 'attempts'), 2),
        );
    }

    /**
     * The executions of a side effect, in order.
     *
     * @return list<array{attempt: int, pid: int, at: int}>
     */
    public function effects(string $invocationId, string $name): array
    {
        return array_map(
            static fn (array $fields): array => ['attempt' => $fields[0], 'pid' => $fields[1], 'at' => $fields[2]],
            $this->read($this->file($invocationId, 'effect-' . self::slug($name)), 3),
        );
    }

    /**
     * Keep a value for the invocation under `$name`, replacing the last one: for example the awakeable id a handler
     * waits on, or the code version a test switches a handler to.
     */
    public function put(string $invocationId, string $name, string $value): void
    {
        if (!is_dir($this->stateDir)) {
            @mkdir($this->stateDir, 0o777, true);
        }
        $file = $this->file($invocationId, 'value-' . self::slug($name));
        $temporary = $file . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $value) === false || !rename($temporary, $file)) {
            throw new \RuntimeException("Cannot write {$file}.");
        }
    }

    /** The value last {@see put()} for the invocation under `$name`, or null. */
    public function value(string $invocationId, string $name): ?string
    {
        $file = $this->file($invocationId, 'value-' . self::slug($name));
        clearstatcache(true, $file);
        $value = is_file($file) ? @file_get_contents($file) : false;

        return $value === false ? null : $value;
    }

    /** Stop the invocation's faults from firing again, whatever its `failures` allow. */
    public function disarm(string $invocationId): void
    {
        touch($this->file($invocationId, 'disarmed'));
    }

    /** Whether a fault fires for execution `$execution` (1-based) of the invocation. */
    public function armed(string $invocationId, ?int $failures, int $execution): bool
    {
        clearstatcache(true, $this->file($invocationId, 'disarmed'));

        return ($failures === null || $execution <= $failures) && !is_file($this->file($invocationId, 'disarmed'));
    }

    /** @param list<int> $fields */
    private function append(string $file, array $fields): int
    {
        if (!is_dir($this->stateDir)) {
            @mkdir($this->stateDir, 0o777, true);
        }
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open {$file}.");
        }

        try {
            flock($handle, LOCK_EX);
            $lines = substr_count((string) stream_get_contents($handle), "\n");
            fseek($handle, 0, SEEK_END);
            fwrite($handle, implode(' ', $fields) . "\n");
            fflush($handle);

            return $lines + 1;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return list<list<int>> */
    private function read(string $file, int $width): array
    {
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;

        return array_map(
            static fn (string $line): array => array_pad(array_map(intval(...), explode(' ', $line)), $width, 0),
            $lines === false ? [] : $lines,
        );
    }

    private function file(string $invocationId, string $kind): string
    {
        return $this->stateDir . '/chaos-' . hash('sha256', $invocationId) . '.' . $kind;
    }

    private static function slug(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
    }

    private static function pid(): int
    {
        $pid = getmypid();

        return $pid === false ? 0 : $pid;
    }

    private static function now(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
