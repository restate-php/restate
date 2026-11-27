<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Support\FaultInjectionTestCase;
use Restate\Tests\Support\Faults\Fault;
use Restate\Tests\Support\Faults\FaultOutcome;
use Restate\Tests\Support\Faults\FaultPlan;
use Restate\Tests\Support\Protocol\FrameCodec;

/**
 * The fault-plan middleware end to end, once per kind of cut, on `Chaos/steps` (two sequential runs; in
 * request/response mode each run ends its attempt with the run command, the proposed completion and a suspension).
 * Restate commits every frame it decoded before the response broke off, so a run executes again exactly when its
 * proposed completion did not arrive whole. The full failure-injection scenarios build on this.
 */
#[Group('runtime')]
final class FaultInjectionTest extends FaultInjectionTestCase
{
    public function testAResponseCutAfterTheRunCommandMakesTheRunExecuteAgain(): void
    {
        $key = 'truncate-' . bin2hex(random_bytes(4));
        self::writePlan(FaultPlan::forIdempotencyKey($key)->onAttempt(1, Fault::truncateAfterFrames(1)));

        $id = self::sendInvocation('Chaos/steps', '{"runs":2}', ['idempotency-key' => $key]);

        self::assertSame('{"attempt":4,"steps":[2,1]}', self::attachInvocation($id)->body);
        $first = self::injectedAttempts($id)[0]['response'];
        self::assertNotNull($first);
        self::assertSame('truncate-after-frames:1', $first['fault']);
        self::assertSame(FaultOutcome::TRUNCATED, $first['end']);
        self::assertSame([FrameCodec::RUN_COMMAND, FrameCodec::PROPOSE_RUN_COMPLETION], array_slice($first['frames'] ?? [], 0, 2));
        self::assertSame([1, 2], array_column(self::chaos()->effects($id, 'step-1'), 'attempt'));
    }

    public function testACutInsideTheProposedCompletionDropsIt(): void
    {
        $key = 'half-' . bin2hex(random_bytes(4));
        self::writePlan(FaultPlan::forIdempotencyKey($key)->onAttempt(1, Fault::truncateAfterFrames(1, halfFrame: true)));

        $id = self::sendInvocation('Chaos/steps', '{"runs":2}', ['idempotency-key' => $key]);

        self::assertSame('{"attempt":4,"steps":[2,1]}', self::attachInvocation($id)->body);
        self::assertCount(2, self::chaos()->effects($id, 'step-1'));
    }

    public function testAWorkerKilledAfterTheProposedCompletionRunsItAgainOnlyBehindNginx(): void
    {
        $key = 'kill-after-' . bin2hex(random_bytes(4));
        self::writePlan(FaultPlan::forIdempotencyKey($key)->onAttempt(1, Fault::killAfterFrames(2)));

        $id = self::sendInvocation('Chaos/steps', '{"runs":2}', ['idempotency-key' => $key]);

        // Behind nginx a killed php-fpm worker's output never reaches Restate (killedWorkerLosesItsOutput()).
        $lost = self::killedWorkerLosesItsOutput();
        self::assertSame($lost ? '{"attempt":4,"steps":[2,1]}' : '{"attempt":3,"steps":[1,1]}', self::attachInvocation($id)->body);
        $first = self::injectedAttempts($id)[0]['response'];
        self::assertNotNull($first);
        // A php -S or php-fpm worker is killed; the php -S master (which serves requests too) exits instead, which
        // Restate sees the same way.
        self::assertContains($first['end'], self::isFpm() ? [FaultOutcome::KILLED] : [FaultOutcome::KILLED, FaultOutcome::EXITED]);
        self::assertCount($lost ? 2 : 1, self::chaos()->effects($id, 'step-1'));
    }

    public function testAWorkerKilledBeforeAnyOutputLosesTheWholeAttempt(): void
    {
        $key = 'kill-before-' . bin2hex(random_bytes(4));
        self::writePlan(FaultPlan::forIdempotencyKey($key)->onAttempt(1, Fault::killBeforeOutput())->onAttempt(3, Fault::exit()));

        $id = self::sendInvocation('Chaos/steps', '{"runs":2}', ['idempotency-key' => $key]);

        // Attempt 1 is lost (step-1 runs again in attempt 2), attempt 3 ends in an empty 200 (step-2 runs again).
        self::assertSame('{"attempt":5,"steps":[2,2]}', self::attachInvocation($id)->body);
        self::assertSame([1, 2], array_column(self::chaos()->effects($id, 'step-1'), 'attempt'));
        self::assertSame([3, 4], array_column(self::chaos()->effects($id, 'step-2'), 'attempt'));
        self::assertSame([1, 2, 3, 4, 5], array_column(self::injectedAttempts($id), 'attempt'));
        $last = self::injectedAttempts($id)[4]['response'];
        self::assertNotNull($last);
        self::assertSame([FrameCodec::OUTPUT_COMMAND, FrameCodec::END], $last['frames']);
        self::assertSame(FaultOutcome::COMPLETE, $last['end']);
    }

    public function testAPlanByInvocationIdAppliesFromTheAttemptAfterItIsWritten(): void
    {
        $id = self::sendInvocation('Chaos/slowRun', '{"ms":300,"times":1}');
        self::writePlan(FaultPlan::forInvocation($id)->onAttempt(2, Fault::delay(100)));

        self::assertSame('{"attempt":2,"execution":1}', self::attachInvocation($id)->body);
        $second = self::injectedAttempts($id)[1]['response'] ?? null;
        self::assertNotNull($second);
        self::assertSame('delay:100ms', $second['fault']);
        self::assertSame(FaultOutcome::COMPLETE, $second['end']);
    }
}
