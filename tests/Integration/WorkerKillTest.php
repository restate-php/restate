<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\FaultInjectionTestCase;
use Restate\Tests\Support\Faults\Fault;
use Restate\Tests\Support\Faults\FaultOutcome;
use Restate\Tests\Support\Faults\FaultPlan;
use Restate\Tests\Support\Protocol\FrameCodec;

/**
 * The PHP worker serving an attempt dies during or after a `ctx.run`, on `Chaos/steps` with three runs
 * (in request/response mode each run ends its attempt: attempt 1 runs step-1, attempt 2 step-2, attempt 3 step-3,
 * attempt 4 returns). Every case kills during attempt 2.
 *
 * A run executes again exactly when its proposed completion was not journaled; the runs before it are replayed, not
 * executed again; an idempotent re-send does not execute anything; and the pool keeps serving.
 *
 * Under php-fpm (`nginx-fpm`, `nginx-fpm-h2c`, `caddy-fpm`) the worker really is SIGKILLed: the master never serves
 * requests and replaces it, and the front door answers 502, a transient failure for Restate. Under `php -S` only a
 * worker kills itself (the master serves requests too, and a dead worker is never replaced: the endpoint is restarted
 * after each test); elsewhere a kill degrades to an `exit` (see ProcessFaults).
 */
final class WorkerKillTest extends FaultInjectionTestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        self::replaceKilledWorkers();
        parent::tearDown();
    }

    public function testAWorkerKilledBeforeAnyOutputRunsTheLostStepAgain(): void
    {
        [$id, $key] = self::start(FaultPlan::forIdempotencyKey(self::key())->onAttempt(2, Fault::killBeforeOutput()));

        self::assertSame('{"attempt":5,"steps":[1,2,1]}', self::attachInvocation($id)->body);
        self::assertExecutions($id, ['step-1' => [1], 'step-2' => [2, 3], 'step-3' => [4]]);
        self::assertKilled($id, 2, written: false);
        self::assertOneRunPerStep($id);
        self::assertIdempotentResendExecutesNothing($id, $key, '{"attempt":5,"steps":[1,2,1]}');
        self::assertEndpointServes();
    }

    public function testAWorkerKilledInsideTheRunRunsItAgain(): void
    {
        $key = self::key();
        $id = self::sendInvocation('Chaos/steps', '{"runs":3,"killInStep":2,"times":1}', ['idempotency-key' => $key]);

        // The run body recorded its effect, then its worker died: nothing of attempt 2 reached Restate.
        self::assertSame('{"attempt":5,"steps":[1,2,1]}', self::attachInvocation($id)->body);
        self::assertExecutions($id, ['step-1' => [1], 'step-2' => [2, 3], 'step-3' => [4]]);
        self::assertOneRunPerStep($id);
        self::assertIdempotentResendExecutesNothing($id, $key, '{"attempt":5,"steps":[1,2,1]}');
        self::assertEndpointServes();
    }

    public function testAWorkerKilledAfterTheProposedCompletionDoesNotRunTheStepAgain(): void
    {
        [$id, $key] = self::start(FaultPlan::forIdempotencyKey(self::key())->onAttempt(2, Fault::killAfterFrames(2)));

        // Behind nginx the dead worker's frames never reach Restate (see killedWorkerLosesItsOutput()): the attempt is
        // lost as if killed before output, and the run executes again.
        $lost = self::killedWorkerLosesItsOutput();
        $result = $lost ? '{"attempt":5,"steps":[1,2,1]}' : '{"attempt":4,"steps":[1,1,1]}';
        self::assertSame($result, self::attachInvocation($id)->body);
        $second = self::injectedAttempts($id)[1]['response'] ?? null;
        self::assertNotNull($second);
        // The cut came right after the run's proposed completion, counted in the frames the attempt produced.
        self::assertSame([FrameCodec::RUN_COMMAND, FrameCodec::PROPOSE_RUN_COMPLETION, FrameCodec::SUSPENSION], $second['frames']);
        self::assertKilled($id, 2, written: true);
        self::assertExecutions($id, $lost
            ? ['step-1' => [1], 'step-2' => [2, 3], 'step-3' => [4]]
            : ['step-1' => [1], 'step-2' => [2], 'step-3' => [3]]);
        self::assertOneRunPerStep($id);
        self::assertIdempotentResendExecutesNothing($id, $key, $result);
        self::assertEndpointServes();
    }

    public function testAWorkerKilledAfterItsWholeResponseLosesNothingButBehindNginx(): void
    {
        [$id, $key] = self::start(FaultPlan::forIdempotencyKey(self::key())
            ->onAttempt(2, Fault::killAfterResponse())
            // The last attempt where nothing is lost: its output and end frame were written before the worker died.
            ->onAttempt(4, Fault::killAfterResponse()));

        if (self::killedWorkerLosesItsOutput()) {
            // Attempt 2 (step-2) and attempt 4 (step-3, after step-2 ran again in attempt 3) are lost in full.
            self::assertSame('{"attempt":6,"steps":[1,2,2]}', self::attachInvocation($id)->body);
            self::assertKilled($id, 2, written: true);
            self::assertKilled($id, 4, written: true);
            self::assertExecutions($id, ['step-1' => [1], 'step-2' => [2, 3], 'step-3' => [4, 5]]);
            self::assertOneRunPerStep($id);
            self::assertIdempotentResendExecutesNothing($id, $key, '{"attempt":6,"steps":[1,2,2]}');
            self::assertEndpointServes();

            return;
        }

        self::assertSame('{"attempt":4,"steps":[1,1,1]}', self::attachInvocation($id)->body);
        self::assertKilled($id, 2, written: true);
        self::assertKilled($id, 4, written: true);
        self::assertSame([FrameCodec::OUTPUT_COMMAND, FrameCodec::END], self::injectedAttempts($id)[3]['response']['frames'] ?? null);
        self::assertCount(4, self::injectedAttempts($id));
        self::assertExecutions($id, ['step-1' => [1], 'step-2' => [2], 'step-3' => [3]]);
        self::assertOneRunPerStep($id);
        self::assertIdempotentResendExecutesNothing($id, $key, '{"attempt":4,"steps":[1,1,1]}');
        self::assertEndpointServes();
    }

    /** @return array{string, string} the invocation id and its idempotency key */
    private static function start(FaultPlan $plan): array
    {
        self::writePlan($plan);

        return [self::sendInvocation('Chaos/steps', '{"runs":3}', ['idempotency-key' => $plan->key]), $plan->key];
    }

    private static function key(): string
    {
        return 'kill-' . bin2hex(random_bytes(6));
    }

    /** @param array<string, list<int>> $attemptsByStep the attempt of each execution of each step */
    private static function assertExecutions(string $id, array $attemptsByStep): void
    {
        foreach ($attemptsByStep as $step => $attempts) {
            self::assertSame($attempts, array_column(self::chaos()->effects($id, $step), 'attempt'), "Executions of {$step}");
        }
    }

    /** How the attempt ended: a real SIGKILL under php-fpm, a SIGKILL or (on the php -S master) an exit otherwise. */
    private static function assertKilled(string $id, int $attempt, bool $written): void
    {
        // The injector records the response before it writes it, so a killed attempt has one.
        $response = self::injectedAttempts($id)[$attempt - 1]['response'] ?? null;
        self::assertNotNull($response, "The fault injector recorded no response for attempt {$attempt}.");
        self::assertContains($response['end'], self::isFpm() ? [FaultOutcome::KILLED] : [FaultOutcome::KILLED, FaultOutcome::EXITED]);
        self::assertSame($written, $response['written'] > 0);
    }

    /** Restate journaled each step's run once, whatever was executed more than once. */
    private static function assertOneRunPerStep(string $id): void
    {
        $counts = self::journalCounts($id);
        foreach (['step-1', 'step-2', 'step-3'] as $step) {
            self::assertSame(1, $counts["Command: Run {$step}"] ?? 0, 'Journal: ' . json_encode($counts));
        }
    }

    private static function assertIdempotentResendExecutesNothing(string $id, string $key, string $result): void
    {
        $attempts = count(self::chaos()->attempts($id));
        $effects = array_map(static fn (string $step): int => count(self::chaos()->effects($id, $step)), ['step-1', 'step-2', 'step-3']);

        self::assertSame($id, self::sendInvocation('Chaos/steps', '{"runs":3}', ['idempotency-key' => $key]));
        self::assertSame($result, self::attachInvocation($id)->body);
        self::assertCount($attempts, self::chaos()->attempts($id));
        self::assertSame($effects, array_map(static fn (string $step): int => count(self::chaos()->effects($id, $step)), ['step-1', 'step-2', 'step-3']));
    }
}
