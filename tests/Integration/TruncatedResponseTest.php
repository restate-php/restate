<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Support\FaultInjectionTestCase;
use Restate\Tests\Support\Faults\Fault;
use Restate\Tests\Support\Faults\FaultPlan;
use Restate\Tests\Support\Protocol\FrameCodec;

/**
 * The first attempt's response is cut at and between its frames. `ChaosObject/batch` answers its first
 * attempt with SetState, two OneWayCalls (to `Counter/<key>/add`, 1 and 10), Run, ProposeRunCompletion and
 * Suspension, read from a reference invocation (the cuts are counted in frames the endpoint produced, never bytes).
 *
 * Restate commits every command it decoded whole before the response broke off and drops half a frame. So:
 * - the run executes again (2 executions) unless the ProposeRunCompletion frame arrived whole (1);
 * - the sends are never duplicated: the counter reaches exactly 11, whether the sends were committed by the cut
 *   attempt or emitted again by the next one;
 * - the next attempt replays the committed prefix without a journal mismatch (no attempt answers an Error frame) and
 *   the journal holds one SetState, two OneWayCalls and one Run.
 *
 * On `php -S` a cut-and-return and a cut-and-SIGKILL are the same on the wire (the response ends when the connection
 * closes); the kill variants run behind php-fpm only, where the front door sees the worker die. Behind nginx a killed
 * worker's output is lost in full (FaultInjectionTestCase::killedWorkerLosesItsOutput()). The PR suite cuts at five
 * representative places; the `failure-nightly` group sweeps every frame boundary and every half frame.
 */
#[Group('managed')]
final class TruncatedResponseTest extends FaultInjectionTestCase
{
    /** @var ?list<int> the frame types of batch's first attempt */
    private static ?array $frames = null;

    /** @return iterable<string, array{string, bool}> where to cut (see cut()), and whether half the next frame is written */
    public static function representativeCuts(): iterable
    {
        yield 'no frame' => ['start', false];
        yield 'after the sends' => ['before-run', false];
        yield 'after the run command' => ['after-run', false];
        yield 'inside the proposed completion' => ['after-run', true];
        yield 'after the proposed completion' => ['after-propose', false];
    }

    /** @return iterable<string, array{int, bool}> every frame boundary of batch's first response, whole and half */
    public static function everyCut(): iterable
    {
        // SetState, OneWayCall, OneWayCall, Run, ProposeRunCompletion, Suspension.
        for ($frames = 0; $frames <= 6; $frames++) {
            yield "{$frames} frames" => [$frames, false];
            if ($frames < 6) {
                yield "{$frames} frames and a half" => [$frames, true];
            }
        }
    }

    public function testTheFirstResponseIsTheBatchThisTestCuts(): void
    {
        self::assertSame(
            [FrameCodec::SET_STATE_COMMAND, FrameCodec::ONE_WAY_CALL_COMMAND, FrameCodec::ONE_WAY_CALL_COMMAND, FrameCodec::RUN_COMMAND, FrameCodec::PROPOSE_RUN_COMPLETION, FrameCodec::SUSPENSION],
            self::frames(),
        );
    }

    #[DataProvider('representativeCuts')]
    public function testACutResponseCommitsItsWholeFramesAndNothingIsDuplicated(string $where, bool $halfFrame): void
    {
        self::assertCut(self::cut($where), $halfFrame, kill: false);
    }

    /** Behind php-fpm: the worker is SIGKILLed after writing the prefix, instead of ending the response. */
    #[DataProvider('representativeCuts')]
    public function testAWorkerKilledMidResponseCommitsWhatReachedRestate(string $where, bool $halfFrame): void
    {
        if (!self::isFpm()) {
            self::markTestSkipped('On php -S a kill after output is a truncation on the wire; the kill variant runs behind php-fpm (RESTATE_PHP_TEST_SERVER=nginx-fpm, nginx-fpm-h2c, caddy-fpm).');
        }

        self::assertCut(self::cut($where), $halfFrame, kill: true);
    }

    #[Group('failure-nightly')]
    #[DataProvider('everyCut')]
    public function testEveryCut(int $frames, bool $halfFrame): void
    {
        self::assertCut($frames, $halfFrame, kill: false);
    }

    #[Group('failure-nightly')]
    #[DataProvider('everyCut')]
    public function testEveryKill(int $frames, bool $halfFrame): void
    {
        if (!self::isFpm()) {
            self::markTestSkipped('The kill variant runs behind php-fpm only.');
        }

        self::assertCut($frames, $halfFrame, kill: true);
    }

    private static function assertCut(int $frames, bool $halfFrame, bool $kill): void
    {
        $types = self::frames();
        $proposeIndex = (int) array_search(FrameCodec::PROPOSE_RUN_COMPLETION, $types, true);
        // The ProposeRunCompletion frame reached Restate whole, so the run's result was journaled.
        $journaled = $frames > $proposeIndex && !($kill && self::killedWorkerLosesItsOutput());

        $key = 'cut-' . bin2hex(random_bytes(6));
        $fault = $kill ? Fault::killAfterFrames($frames, $halfFrame) : Fault::truncateAfterFrames($frames, $halfFrame);
        self::writePlan(FaultPlan::forIdempotencyKey($key)->onAttempt(1, $fault));
        $id = self::sendInvocation("ChaosObject/{$key}/batch", null, ['idempotency-key' => $key]);

        self::assertSame('"v1"', self::attachInvocation($id)->body);
        $executions = $journaled ? 1 : 2;
        self::assertSame($executions === 1 ? [1] : [1, 2], array_column(self::chaos()->effects($id, 'r'), 'attempt'), "Executions of the run after {$fault->describe()}");
        // Attempts: the cut one, one more per lost run execution, then the one that returns.
        self::assertCount($executions + 1, self::chaos()->attempts($id));
        foreach (self::injectedAttempts($id) as $attempt) {
            self::assertNotContains(FrameCodec::ERROR, $attempt['response']['frames'] ?? [], "Attempt {$attempt['attempt']} answered an error (journal mismatch?).");
        }
        self::assertSame(['Command: SetState' => 1, 'Command: OneWayCall' => 2, 'Command: Run r' => 1], array_intersect_key(
            self::journalCounts($id),
            ['Command: SetState' => true, 'Command: OneWayCall' => true, 'Command: Run r' => true],
        ), 'Journal: ' . json_encode(self::journal($id)));

        self::waitForCounter($key, '11');
        self::assertCounterStaysBriefly($key, '11');
    }

    /** The number of whole frames before the cut. */
    private static function cut(string $where): int
    {
        $types = self::frames();
        $run = (int) array_search(FrameCodec::RUN_COMMAND, $types, true);

        return match ($where) {
            'start' => 0,
            'before-run' => $run,
            'after-run' => $run + 1,
            'after-propose' => (int) array_search(FrameCodec::PROPOSE_RUN_COMPLETION, $types, true) + 1,
            default => throw new \InvalidArgumentException($where),
        };
    }

    /** @return list<int> the frame types of batch's first attempt, from an invocation with a plan but no fault */
    private static function frames(): array
    {
        if (self::$frames !== null) {
            return self::$frames;
        }

        $key = 'reference-' . bin2hex(random_bytes(6));
        self::writePlan(FaultPlan::forIdempotencyKey($key));
        $id = self::sendInvocation("ChaosObject/{$key}/batch", null, ['idempotency-key' => $key]);
        self::assertSame('"v1"', self::attachInvocation($id)->body);
        $frames = self::injectedAttempts($id)[0]['response']['frames'] ?? null;
        self::assertNotNull($frames, 'The reference attempt recorded no frames.');

        return self::$frames = $frames;
    }

    /** A duplicated send would show within a second: Restate delivers one-way calls at once. */
    private static function assertCounterStaysBriefly(string $key, string $expected): void
    {
        for ($i = 0; $i < 4; $i++) {
            usleep(250_000);
            self::assertSame($expected, self::invoke("Counter/{$key}/get", null), "Counter/{$key}/get changed from {$expected}");
        }
    }
}
