<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Scale;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Services\ScaleValues;
use Restate\Tests\Integration\Support\HttpClient;

/**
 * Long journals: in request/response mode every `ctx->run` ends the HTTP attempt, so n sequential runs
 * take n + 1 attempts, and attempt k replays the k - 1 runs before it. LongJournal/runs and LongJournal/slowReplay
 * (tests/Integration/Services/scale.php).
 *
 * Attempts are exactly n + 1 plus the attempts that failed. Restate keeps no retry count once an invocation completed,
 * so the failed attempts are those beyond n + 1, and Restate must have recorded a transient error for any of them. A
 * run's side effect executes at least once, more often only in attempts that failed.
 */
final class LongJournalTest extends ScaleTestCase
{
    /** The CPU each step of LongJournal/slowReplay spins for: no attempt ends within 40 ms of the 1 s abort timeout. */
    private const int SPIN_MS = 130;

    public function testFiftyRunsTakeFiftyOneAttempts(): void
    {
        $this->assertLongJournal(50, 16, timeout: 60.0);
    }

    /**
     * Ten 1 MiB run results: the last attempt's request body carries more than 10 MiB of journal, over PHP's default
     * `post_max_size` (8M). The endpoint's `enable_post_data_reading=0` (a PhpCliServer default) keeps PHP from
     * warning about it, which with `display_errors` on would corrupt the response (docs/decisions/0032-stray-output.md).
     */
    #[Group('runtime')]
    public function testAJournalOverEightMebibytesReplays(): void
    {
        $this->assertLongJournal(10, 1 << 20, timeout: 120.0);
    }

    /** @return iterable<string, array{int, int, float}> */
    public static function longJournals(): iterable
    {
        yield '500 runs' => [500, 16, 300.0];
        yield '1000 runs' => [1000, 16, 600.0];
        yield '3000 runs' => [3000, 16, 1800.0];
        // About 24 MiB in the last attempt's body, about 1.2 GB through the endpoint in all.
        yield '100 runs of 256 KiB' => [100, 256 << 10, 600.0];
    }

    #[Group('scale')]
    #[DataProvider('longJournals')]
    public function testLongJournalsReplayInFull(int $n, int $valueBytes, float $timeout): void
    {
        $this->assertLongJournal($n, $valueBytes, $timeout);
    }

    /**
     * The abort-timeout ceiling: Restate's abort timeout bounds a whole request/response attempt, replay included.
     * Every step of LongJournal/slowReplay costs 130 ms of CPU, replayed or not, and its abort timeout is 1 s, so once seven steps
     * are journaled no attempt can finish: Restate aborts each one (RT0001), the endpoint still runs it to the end (the
     * step after the ceiling executes again in every attempt), and the invocation never completes. Its retry policy
     * pauses it after three failed attempts in a row, which bounds the test.
     */
    public function testAReplayLongerThanTheAbortTimeoutNeverCompletes(): void
    {
        $n = 40;
        $id = self::sendInvocation('LongJournal/slowReplay', json_encode(['n' => $n, 'spinMs' => self::SPIN_MS], JSON_THROW_ON_ERROR));

        $row = self::restate()->waitForInvocationStatus($id, 'paused', 60.0);
        $recorder = self::recorder();
        $attempts = count($recorder->attempts($id));
        // The endpoint runs the last aborted attempt to its end after Restate gave up on it.
        self::waitForAttemptEnds($id, $attempts, 10.0);
        $executions = [];
        for ($step = 1; $step <= $n; $step++) {
            $executions[$step] = count($recorder->effects($id, "step-{$step}"));
        }
        $ceiling = max([0, ...array_keys(array_filter($executions, static fn (int $count): bool => $count > 0))]);
        $dump = json_encode(['row' => $row, 'executions' => $executions, 'attemptMillis' => self::attemptMillis($id)], JSON_THROW_ON_ERROR);

        // Attempt k spins k x 130 ms before its new step: attempt 7 needs 0.91 s, attempt 8 1.04 s. With 100 ms, attempt 10
        // would land on the abort timeout itself, and whether its step got journaled was a race (it did on macOS).
        self::assertGreaterThanOrEqual(5, $ceiling, $dump);
        self::assertLessThanOrEqual(10, $ceiling, $dump);
        foreach ($executions as $step => $count) {
            if ($step < $ceiling) {
                self::assertSame(1, $count, "step-{$step} ran in a successful attempt only. {$dump}");
            } elseif ($step > $ceiling) {
                self::assertSame(0, $count, "No attempt got past step-{$ceiling}. {$dump}");
            }
        }
        // Every attempt from the ceiling on replays to it and runs it again; the endpoint never learns of the abort.
        self::assertSame($attempts - $ceiling + 1, $executions[$ceiling], $dump);
        self::assertGreaterThanOrEqual(3, $executions[$ceiling], $dump);
        foreach (array_slice(self::attemptMillis($id), $ceiling - 1) as $millis) {
            self::assertGreaterThanOrEqual($ceiling * 100, $millis, "An aborted attempt still runs to its end. {$dump}");
        }

        $errors = array_column(self::journalEvents($id, 'TransientError'), 'event_json');
        self::assertNotSame([], $errors, $dump);
        foreach ($errors as $error) {
            self::assertIsString($error);
            self::assertStringContainsString("the 'abort timeout' (1s) fired", $error, $dump);
            self::assertStringContainsString('RT0001', $error, $dump);
        }
        self::assertCount(1, self::journalEvents($id, 'Paused'), $dump);

        self::report('long-journal-abort-timeout', ['n' => $n, 'spinMs' => self::SPIN_MS, 'abortTimeoutMs' => 1000, 'ceiling' => $ceiling, 'attempts' => $attempts, 'attemptMillis' => self::attemptMillis($id)]);
        HttpClient::patch(self::restate()->adminUrl() . "/invocations/{$id}/kill");
    }

    private function assertLongJournal(int $n, int $valueBytes, float $timeout): void
    {
        $started = microtime(true);
        $id = self::sendInvocation('LongJournal/runs', json_encode(['n' => $n, 'valueBytes' => $valueBytes], JSON_THROW_ON_ERROR));
        $response = self::attachInvocation($id, $timeout);
        $wall = microtime(true) - $started;

        self::assertSame(200, $response->status, $response->body);
        $result = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame($n, $result['n'] ?? null, $response->body);
        self::assertSame(ScaleValues::stepsHash($n, $valueBytes), $result['hash'] ?? null, 'Every run result replayed intact and in order.');

        $recorder = self::recorder();
        $attempts = count($recorder->attempts($id));
        self::assertSame($attempts, $result['attempt'] ?? null, 'The attempt that completed is the last one recorded.');
        $duplicates = 0;
        for ($step = 1; $step <= $n; $step++) {
            $executions = count($recorder->effects($id, "step-{$step}"));
            self::assertGreaterThanOrEqual(1, $executions, "step-{$step} never executed.");
            $duplicates += $executions - 1;
        }

        $failed = $attempts - ($n + 1);
        $transientErrors = count(self::journalEvents($id, 'TransientError'));
        self::assertGreaterThanOrEqual(0, $failed, "{$n} runs take at least {$n} + 1 attempts, got {$attempts}.");
        self::assertLessThanOrEqual($failed, $duplicates, 'A run executes again only in an attempt that failed.');
        if ($transientErrors === 0) {
            self::assertSame($n + 1, $attempts, 'Without a failed attempt, n runs take exactly n + 1 attempts.');
        } else {
            self::assertGreaterThan(0, $failed, 'Restate recorded a failed attempt, so there are more than n + 1.');
        }

        $millis = self::attemptMillis($id);
        self::report('long-journal', [
            'n' => $n,
            'valueBytes' => $valueBytes,
            'attempts' => $attempts,
            'failedAttempts' => $failed,
            'duplicateEffects' => $duplicates,
            'wallSeconds' => round($wall, 2),
            'handlerMillisByAttempt' => self::sampled($millis),
            'handlerMillisTotal' => array_sum($millis),
            'cycleMillisByAttempt' => self::sampled(self::attemptCycleMillis($id)),
        ]);
    }
}
