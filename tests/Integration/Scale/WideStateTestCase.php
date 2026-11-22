<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Scale;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Restate\Testing\RestateContainer;
use Restate\Testing\RestateProcess;
use Restate\Tests\Integration\Support\IntegrationRestate;
use Restate\Tests\Integration\Services\ScaleValues;

/**
 * Wide state: thousands of keys on one virtual object (WideState, tests/Integration/Services/scale.php), read
 * back with stateKeys() and get(), and clearAll() followed by reads, under each way Restate sends state with an
 * invocation. One subclass per mode, each with a Restate server of its own when the mode needs a server setting.
 *
 * In request/response mode every read Restate did not send ends the attempt (a lazy state read), so reading K lazy
 * keys with stateKeys() first takes K + 2 attempts.
 */
abstract class WideStateTestCase extends ScaleTestCase
{
    /**
     * The harness's Restate environment ({@see RestateContainer::withEnvironment()} replaces it) plus `$variables`.
     *
     * @param array<string, string> $variables
     */
    protected static function restateWith(array $variables): RestateContainer|RestateProcess
    {
        return IntegrationRestate::withEnvironment([
            'RESTATE_DEFAULT_NUM_PARTITIONS' => '1',
            'RESTATE_LOG_FORMAT' => 'compact',
            'RESTATE_LOG_DISABLE_ANSI_CODES' => 'true',
        ] + $variables);
    }

    /** The mode's name in the report. */
    abstract protected static function mode(): string;

    /**
     * How many attempts a handler that reads `$reads` keys, after listing them with stateKeys() when `$listsKeys`, may
     * take on an object with `$keys` keys: [min, max]. The reads without stateKeys() include a key the object lacks.
     *
     * @return array{int, int}
     */
    abstract protected static function readAttempts(int $keys, int $reads, bool $listsKeys): array;

    /** How many attempts readAll takes on an object whose state was cleared. */
    abstract protected static function readAllClearedAttempts(): int;

    public function testWideState(): void
    {
        $this->assertWideState(static::prKeys(), 16);
    }

    /**
     * Keys and value sizes in the `scale` group: 2000 keys, 10,000 when every read is eager (lazily, each is an attempt
     * replaying the reads before it), and 2.5 MiB of state in 40 keys.
     *
     * @return iterable<string, array{int, int}>
     */
    public static function wideStates(): iterable
    {
        yield '2000 keys' => [2000, 16];
        if (static::prKeys() > 100) {
            yield '10000 keys' => [10_000, 16];
        }
        yield '40 keys of 64 KiB' => [40, 64 << 10];
    }

    #[Group('scale')]
    #[DataProvider('wideStates')]
    public function testVeryWideState(int $keys, int $bytes): void
    {
        $this->assertWideState($keys, $bytes);
    }

    /** Keys in the case that runs with the integration suite. */
    protected static function prKeys(): int
    {
        return 100;
    }

    private function assertWideState(int $keys, int $bytes): void
    {
        $object = 'WideState/' . static::mode() . '-' . bin2hex(random_bytes(4));
        // Lazily, each key read is an attempt replaying the reads before it.
        $timeout = 60.0 + $keys / 2;

        $started = microtime(true);
        $fill = self::handlerResult("{$object}/fill", ['count' => $keys, 'bytes' => $bytes], $timeout);
        $fillSeconds = microtime(true) - $started;
        self::assertSame($keys, $fill['count'] ?? null);
        self::assertAttemptsWithin([1, 1], $fill, 'Setting state never ends the attempt.');

        $started = microtime(true);
        $read = self::handlerResult("{$object}/readAll", null, $timeout);
        $readSeconds = microtime(true) - $started;
        self::assertSame($keys, $read['count'] ?? null);
        self::assertSame(ScaleValues::filledStateHash($keys, $bytes), $read['hash'] ?? null, 'Every key and value read back intact.');
        self::assertAttemptsWithin(static::readAttempts($keys, $keys, listsKeys: true), $read, "readAll over {$keys} keys.");

        $last = ScaleValues::stateKey($keys - 1);
        $some = self::handlerResult("{$object}/readSome", ['keys' => ['k0', $last, 'missing']], $timeout);
        self::assertSame([ScaleValues::value(0, $bytes), ScaleValues::value($keys - 1, $bytes), null], $some['values'] ?? null);
        self::assertAttemptsWithin(static::readAttempts($keys, 3, listsKeys: false), $some, 'readSome of two keys and a missing one.');

        $cleared = self::handlerResult("{$object}/clearAllThenRead", ['keys' => ['k0', $last, 'missing']], $timeout);
        self::assertSame([null, null, null], $cleared['values'] ?? false, 'After clearAll() every read is null.');
        self::assertSame([], $cleared['keys'] ?? false, 'After clearAll() no key is left.');
        self::assertAttemptsWithin([1, 1], $cleared, 'Reads after clearAll() never ask Restate.');

        $empty = self::handlerResult("{$object}/readAll", null, $timeout);
        self::assertSame(0, $empty['count'] ?? null);
        self::assertSame(ScaleValues::stateHash([]), $empty['hash'] ?? null);
        self::assertAttemptsWithin([static::readAllClearedAttempts(), static::readAllClearedAttempts()], $empty, 'readAll of a cleared object.');

        self::report('wide-state', [
            'mode' => static::mode(),
            'keys' => $keys,
            'bytes' => $bytes,
            'fillSeconds' => round($fillSeconds, 2),
            'readAllSeconds' => round($readSeconds, 2),
            'readAllAttempts' => $read['attempt'],
        ]);
    }

    /**
     * Assert the attempt that completed a WideState handler is within [min, max], unless an attempt failed.
     *
     * @param array{int, int} $range
     * @param array<array-key, mixed> $result
     */
    private static function assertAttemptsWithin(array $range, array $result, string $message): void
    {
        self::assertIsInt($result['attempt'] ?? null);
        if ($result['failed'] === true) {
            return;
        }
        self::assertGreaterThanOrEqual($range[0], $result['attempt'], $message);
        self::assertLessThanOrEqual($range[1], $result['attempt'], $message);
    }

    /**
     * Invoke a WideState handler and return its result, with `failed`: whether Restate recorded a transient error for
     * the invocation, in which case its `attempt` counts failed attempts too and is not checked.
     *
     * @param ?array<string, mixed> $request
     * @return array<array-key, mixed>
     */
    private static function handlerResult(string $path, ?array $request, float $timeout): array
    {
        $id = self::sendInvocation($path, $request === null ? null : json_encode($request, JSON_THROW_ON_ERROR));
        $response = self::attachInvocation($id, $timeout);
        self::assertSame(200, $response->status, $response->body);
        $result = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result, $response->body);
        $result['failed'] = self::journalEvents($id, 'TransientError') !== [];

        return $result;
    }
}
