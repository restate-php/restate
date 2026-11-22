<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Scale;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Wide fan-out: hundreds of calls, awakeables and sleeps combined with all(), any(), select() and
 * DurableFuturesUnordered (FanOut, tests/Integration/Services/scale.php).
 *
 * Attempt bounds, each plus the attempts that failed (those Restate recorded a transient error for):
 * - all(): one tree, which on protocol v7 Restate resumes only once it can resolve, so 2 attempts. Protocol v6 (Restate
 *   1.7) resumes on any completion: up to n + 1.
 * - select() loops and DurableFuturesUnordered: at most n + 1, exactly n + 1 when each completion arrives on its own.
 *
 * A select() is one extension call (the tree await reports which futures completed), but it still passes
 * the futures left and shared-core reduces their tree, so replaying k selects over n futures is O(n k) per attempt and
 * O(n^3) for an invocation that gets one completion per attempt. Every case asserts a cap on the time one attempt runs
 * in the handler.
 */
final class FanOutTest extends ScaleTestCase
{
    /** A third of php -S's `max_execution_time` (30 s), which ends any attempt that runs longer. */
    private const int ATTEMPT_MILLIS_CAP = 10_000;

    public function testAllOverCalls(): void
    {
        $this->assertAllCalls(100);
    }

    public function testSelectLoopOverCalls(): void
    {
        $this->assertSelectCalls(100);
    }

    public function testUnorderedSleeps(): void
    {
        $this->assertUnorderedSleeps(100);
    }

    public function testAllOverAwakeablesResolvedConcurrently(): void
    {
        $this->assertAllAwakeables(100);
    }

    public function testSelectLoopOverAwakeablesCompletedOneAttemptAtATime(): void
    {
        $this->assertSelectAwakeablesInLockstep(50);
    }

    public function testAnyOverAwakeablesRejectedOneByOne(): void
    {
        $this->assertAnyAwakeablesRejected(50);
    }

    /** @return iterable<string, array{string, int}> */
    public static function wideFanOuts(): iterable
    {
        foreach ([500, 1000] as $n) {
            yield "all over {$n} calls" => ['assertAllCalls', $n];
            yield "select over {$n} calls" => ['assertSelectCalls', $n];
            yield "unordered {$n} sleeps" => ['assertUnorderedSleeps', $n];
            yield "all over {$n} awakeables" => ['assertAllAwakeables', $n];
            yield "any over {$n} rejected awakeables" => ['assertAnyAwakeablesRejected', $n];
        }
        // n + 1 attempts, attempt k replaying k - 1 selects over n futures: the O(n^3) case.
        yield 'select over 200 awakeables in lockstep' => ['assertSelectAwakeablesInLockstep', 200];
        yield 'select over 500 awakeables in lockstep' => ['assertSelectAwakeablesInLockstep', 500];
        yield 'select over 1000 awakeables in lockstep' => ['assertSelectAwakeablesInLockstep', 1000];
    }

    #[Group('scale')]
    #[DataProvider('wideFanOuts')]
    public function testWideFanOut(string $assertion, int $n): void
    {
        match ($assertion) {
            'assertAllCalls' => $this->assertAllCalls($n),
            'assertSelectCalls' => $this->assertSelectCalls($n),
            'assertUnorderedSleeps' => $this->assertUnorderedSleeps($n),
            'assertAllAwakeables' => $this->assertAllAwakeables($n),
            'assertAnyAwakeablesRejected' => $this->assertAnyAwakeablesRejected($n),
            'assertSelectAwakeablesInLockstep' => $this->assertSelectAwakeablesInLockstep($n),
            default => throw new \InvalidArgumentException("Unknown assertion {$assertion}."),
        };
    }

    private function assertAllCalls(int $n): void
    {
        $started = microtime(true);
        $id = self::sendInvocation('FanOut/allCalls', json_encode(['n' => $n], JSON_THROW_ON_ERROR));
        $result = self::completed($id, $n);

        self::assertSame(range(0, $n - 1), $result['values'], 'all() returns the values in key order.');
        $this->assertCalleesExecuted($id, $n);
        $attempts = $this->assertAttempts($id, 'all', $n, self::protocolVersion($id) >= 7 ? 2 : null);
        self::reportFanOut('all-calls', $id, $n, $attempts, $started);
    }

    private function assertSelectCalls(int $n): void
    {
        $started = microtime(true);
        $id = self::sendInvocation('FanOut/selectCalls', json_encode(['n' => $n], JSON_THROW_ON_ERROR));
        $result = self::completed($id, $n);

        self::assertIsArray($result['order']);
        self::assertEqualsCanonicalizing(range(0, $n - 1), $result['order'], 'select() returned every key once.');
        $this->assertCalleesExecuted($id, $n);
        $attempts = $this->assertAttempts($id, 'select', $n, null);
        self::reportFanOut('select-calls', $id, $n, $attempts, $started);
    }

    private function assertUnorderedSleeps(int $n): void
    {
        $started = microtime(true);
        $id = self::sendInvocation('FanOut/unorderedSleeps', json_encode(['n' => $n], JSON_THROW_ON_ERROR));
        $result = self::completed($id, $n);

        self::assertIsArray($result['order']);
        self::assertEqualsCanonicalizing(range(0, $n - 1), $result['order'], 'Every sleep completed once.');
        $attempts = $this->assertAttempts($id, 'unordered', $n, null);
        self::reportFanOut('unordered-sleeps', $id, $n, $attempts, $started);
    }

    /** The test resolves the awakeables in a random order, 32 at a time. */
    private function assertAllAwakeables(int $n): void
    {
        $started = microtime(true);
        $id = self::sendInvocation('FanOut/allAwakeables', json_encode(['n' => $n], JSON_THROW_ON_ERROR));
        $awakeables = self::awakeableIds($id, $n);
        $order = range(0, $n - 1);
        shuffle($order);
        $requests = [];
        foreach ($order as $i) {
            $requests[$i] = ['path' => "restate/awakeables/{$awakeables[$i]}/resolve", 'body' => json_encode("value-{$i}", JSON_THROW_ON_ERROR)];
        }
        foreach (self::postConcurrently($requests, 32, 30.0) as $i => $response) {
            self::assertTrue($response->isSuccessful(), "Resolving awakeable {$i}: HTTP {$response->status} {$response->body}");
        }
        $result = self::completed($id, $n);

        self::assertSame(array_map(static fn (int $i): string => "value-{$i}", range(0, $n - 1)), $result['values']);
        $attempts = $this->assertAttempts($id, 'all', $n, self::protocolVersion($id) >= 7 ? 2 : null);
        self::reportFanOut('all-awakeables', $id, $n, $attempts, $started);
    }

    /**
     * The test resolves one awakeable, in a random order, and waits for the attempt it causes to end before the next:
     * each attempt then sees exactly one new completion. select() must return the keys in completion order, and the
     * invocation takes exactly n + 1 attempts.
     */
    private function assertSelectAwakeablesInLockstep(int $n): void
    {
        $started = microtime(true);
        $id = self::sendInvocation('FanOut/selectAwakeables', json_encode(['n' => $n], JSON_THROW_ON_ERROR));
        $awakeables = self::awakeableIds($id, $n);
        $order = range(0, $n - 1);
        shuffle($order);
        self::waitForAttemptEnds($id, 1);
        foreach ($order as $resolved => $i) {
            self::resolveAwakeable($awakeables[$i], $i);
            self::waitForAttemptEnds($id, $resolved + 2, 120.0);
        }
        $result = self::completed($id, $n);

        self::assertSame($order, $result['order'], 'select() returns the keys in the order they completed.');
        $attempts = $this->assertAttempts($id, 'select', $n, $n + 1);
        self::reportFanOut('select-awakeables-lockstep', $id, $n, $attempts, $started);
    }

    /**
     * The test rejects the awakeables one at a time, in a random order, each after Restate accepted the one before, so
     * Restate appends the failures in that order. any() lists the failures in the order they completed.
     */
    private function assertAnyAwakeablesRejected(int $n): void
    {
        $started = microtime(true);
        $id = self::sendInvocation('FanOut/anyAwakeables', json_encode(['n' => $n], JSON_THROW_ON_ERROR));
        $awakeables = self::awakeableIds($id, $n);
        $order = range(0, $n - 1);
        shuffle($order);
        foreach ($order as $i) {
            self::rejectAwakeable($awakeables[$i], "failure-{$i}");
        }
        $result = self::completed($id, $n);

        self::assertIsArray($result['failures'] ?? null, 'Every awakeable failed, so any() threw the aggregate.');
        self::assertSame(
            array_map(static fn (int $i): array => ['key' => $i, 'message' => "failure-{$i}"], $order),
            $result['failures'],
            'The aggregate lists the failures in the order Restate completed them.',
        );
        $attempts = $this->assertAttempts($id, 'any', $n, null);
        self::reportFanOut('any-awakeables-rejected', $id, $n, $attempts, $started);
    }

    /** @return array<array-key, mixed> the handler's result */
    private static function completed(string $id, int $n): array
    {
        $response = self::attachInvocation($id, 120.0 + $n);
        self::assertSame(200, $response->status, $response->body);
        $result = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame(count(self::recorder()->attempts($id)), $result['attempt'] ?? null, 'The attempt that completed is the last one recorded.');

        return $result;
    }

    /**
     * Assert the attempt count, `$exactly` (or at most n + 1 when null) plus the attempts that failed, and the
     * per-attempt time cap; returns the attempts.
     */
    private function assertAttempts(string $id, string $combinator, int $n, ?int $exactly): int
    {
        $attempts = count(self::recorder()->attempts($id));
        $failed = count(self::journalEvents($id, 'TransientError')) > 0;
        $millis = self::attemptMillis($id);
        $dump = json_encode(['attempts' => $attempts, 'attemptMillis' => $millis, 'failedAttempts' => $failed], JSON_THROW_ON_ERROR);

        if ($exactly !== null && !$failed) {
            self::assertSame($exactly, $attempts, "{$combinator}() over {$n} futures. {$dump}");
        } elseif ($exactly !== null) {
            self::assertGreaterThan($exactly, $attempts, "Restate recorded a failed attempt. {$dump}");
        } elseif (!$failed) {
            self::assertLessThanOrEqual($n + 1, $attempts, "{$combinator}() over {$n} futures. {$dump}");
        }
        self::assertLessThan(self::ATTEMPT_MILLIS_CAP, max([0, ...$millis]), "No attempt of {$combinator}() over {$n} futures may run that long. {$dump}");

        return $attempts;
    }

    /**
     * Each callee executed at least once; more than once only if Restate recorded a failed attempt of one.
     */
    private function assertCalleesExecuted(string $caller, int $n): void
    {
        $executions = array_count_values(array_column(self::recorder()->effects($caller, 'callee'), 'attempt'));
        ksort($executions);
        self::assertSame(range(0, $n - 1), array_keys($executions), 'Every callee executed.');

        $callees = array_column(self::restate()->query("SELECT id FROM sys_invocation WHERE invoked_by_id = '{$caller}'"), 'id');
        $calleeFailures = $callees === [] ? [] : self::restate()->query(sprintf(
            "SELECT id FROM sys_journal_events WHERE event_type = 'TransientError' AND id IN ('%s')",
            implode("', '", array_map(strval(...), $callees)),
        ));
        if ($calleeFailures === []) {
            self::assertSame(array_fill(0, $n, 1), array_values($executions), 'No callee attempt failed, so each executed once.');
        }
    }

    private static function protocolVersion(string $id): int
    {
        $version = self::invocationRow($id, 'pinned_service_protocol_version')['pinned_service_protocol_version'] ?? null;
        self::assertIsInt($version);

        return $version;
    }

    private static function reportFanOut(string $scenario, string $id, int $n, int $attempts, float $started): void
    {
        $millis = self::attemptMillis($id);
        self::report("fan-out-{$scenario}", [
            'n' => $n,
            'attempts' => $attempts,
            'protocol' => self::protocolVersion($id),
            'wallSeconds' => round(microtime(true) - $started, 2),
            'maxAttemptMillis' => max([0, ...$millis]),
            'handlerMillisByAttempt' => self::sampled($millis),
            'handlerMillisTotal' => array_sum($millis),
        ]);
    }
}
