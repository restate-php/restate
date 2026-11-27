<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Support\FaultInjectionTestCase;

/**
 * An attempt that outlives its handler's abort timeout, in request/response mode.
 *
 * Only the abort timeout guards an attempt in this mode (the inactivity timeout applies to bidirectional streams);
 * `ChaosObject/abortable` sets it to 1 s per handler, which Restate 1.8 honours, and its run sleeps 4 s in its first
 * execution. The endpoint sends nothing, not even the status line, until the attempt is over.
 *
 * Measured with Restate 1.8.1, the same under `php -S`, nginx (HTTP/1.1 and h2c) and Caddy (h2c): the timeout does
 * **not** cut the attempt off after 1 s. Restate reports it (RT0001, "the invocation stream was closed after the
 * 'abort timeout' (1s) fired") only when the slow attempt's response arrives, about 4 s in, and then discards that
 * complete response: the run's result is not journaled although it was computed, and the retry, which starts only
 * then, executes the run again. So the stale attempt and its retry never overlap, but every attempt slower than the
 * abort timeout is wasted, its side effects done again: a handler that is always slower than its abort timeout never
 * completes (`failure-nightly`: paused by its retry policy). Orderings of recorded events are asserted, never
 * durations (4 s against 1 s leaves a margin of 4x).
 */
#[Group('runtime')]
final class AbortTimeoutTest extends FaultInjectionTestCase
{
    public function testAnAttemptSlowerThanItsAbortTimeoutRunsToTheEndAndIsDiscarded(): void
    {
        $key = 'abort-' . bin2hex(random_bytes(6));
        $id = self::sendInvocation("ChaosObject/{$key}/abortable", '{"ms":4000,"times":1}');
        // Queued behind the first invocation's lock.
        $next = self::sendInvocation("ChaosObject/{$key}/add", '{}');

        // The retry's execution is the journaled one: the first, complete but late, was thrown away.
        self::assertSame('2', self::attachInvocation($id)->body);
        self::assertSame('1', self::attachInvocation($next)->body);
        self::assertSame(1, self::journalCounts($id)['Command: Run abortable'] ?? 0);

        // The first attempt's failure, from the journal: `last_failure` shows it only until the retry, which can
        // complete a few milliseconds later, before a poll sees it.
        $errors = self::journalEvents($id, 'TransientError');
        self::assertCount(1, $errors, (string) json_encode($errors, JSON_UNESCAPED_SLASHES));
        $failure = json_decode((string) $errors[0]['event_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($failure);
        self::assertStringContainsString("the 'abort timeout' (1s) fired", (string) ($failure['error_message'] ?? ''), (string) $errors[0]['event_json']);
        self::assertSame('RT0001', $failure['restate_doc_error_code'] ?? null, (string) $errors[0]['event_json']);

        $starts = self::chaos()->effects($id, 'abortable-start');
        $ends = self::chaos()->effects($id, 'abortable-end');
        self::assertSame([1, 2], array_column($starts, 'attempt'));
        self::assertSame([1, 2], array_column($ends, 'attempt'));
        // The first execution ran to its end; the retry started only after it (no overlap), and the next invocation
        // on the key only after the retry.
        self::assertGreaterThanOrEqual($ends[0]['at'], $starts[1]['at']);
        $nextRun = self::chaos()->effects($next, 'add');
        self::assertCount(1, $nextRun);
        self::assertGreaterThanOrEqual($ends[1]['at'], $nextRun[0]['at']);

        self::assertEndpointServes(3);
    }

    /** Without an abort timeout of its own a 2 s run completes in its first execution: the default is 10 minutes. */
    public function testWithoutAShortAbortTimeoutASlowRunExecutesOnce(): void
    {
        $id = self::sendInvocation('Chaos/slowRun', '{"ms":2000}');

        self::assertSame('{"attempt":2,"execution":1}', self::attachInvocation($id)->body);
        self::assertCount(1, self::chaos()->effects($id, 'slow'));
    }

    /**
     * A run always slower than its handler's abort timeout never gets its result journaled: every attempt completes,
     * is discarded, and the run executes again, until the invocation retry policy pauses the invocation after 5.
     */
    #[Group('failure-nightly')]
    public function testARunAlwaysSlowerThanTheAbortTimeoutNeverCompletes(): void
    {
        $key = 'always-slow-' . bin2hex(random_bytes(6));
        $id = self::sendInvocation("ChaosObject/{$key}/abortable", '{"ms":1500}');

        self::restate()->waitForInvocationStatus($id, 'paused', 30.0);
        self::assertSame([1, 2, 3, 4, 5], array_column(self::chaos()->effects($id, 'abortable-end'), 'attempt'));
        self::assertArrayNotHasKey('Command: Run abortable', self::journalCounts($id));

        self::chaos()->disarm($id);
        self::resume($id);
        self::assertSame('6', self::attachInvocation($id, 30.0)->body);
    }
}
