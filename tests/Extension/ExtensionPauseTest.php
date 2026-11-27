<?php

declare(strict_types=1);

namespace Restate\Tests\Extension;

use Restate\Internal\Backend\RunCompletionStatus;
use Restate\OnMaxAttempts;
use Restate\RunRetryPolicy;
use Restate\Tests\Support\Protocol\FrameCodec;

/**
 * Pausing the invocation through the extension against a real VM: the error message a pause writes on protocol v7
 * (Restate 1.8).
 */
final class ExtensionPauseTest extends ExtensionTestCase
{
    public function testHandlerPauseEndsTheAttemptAskingRestateToPause(): void
    {
        $invocation = self::running(contentType: self::V7);

        $invocation->notifyPause("caf\xE9 needs a fix", '#0 handler()');

        self::assertSame(
            bin2hex(FrameCodec::errorFrame(500, "caf\u{FFFD} needs a fix", '#0 handler()', behavior: FrameCodec::ERROR_BEHAVIOR_PAUSE)),
            bin2hex(self::written($invocation)),
        );
        self::assertNull($invocation->takeOutput(), 'the response is complete');
    }

    public function testRunPauseEndsTheAttemptWithTheRunAsRelatedCommand(): void
    {
        $invocation = self::running(contentType: self::V7);
        $handle = $invocation->sysRun('charge');
        $invocation->doAwait($handle);

        $invocation->proposeRunCompletionPause($handle, 'approval needed', 5);

        self::assertSame(
            bin2hex(FrameCodec::runCommandFrame(1, 'charge') . FrameCodec::errorFrame(
                500,
                'approval needed',
                relatedCommandIndex: 1,
                relatedCommandName: 'charge',
                relatedCommandType: FrameCodec::RUN_COMMAND,
                behavior: FrameCodec::ERROR_BEHAVIOR_PAUSE,
            )),
            bin2hex(self::written($invocation)),
        );
    }

    /** A policy that pauses once exhausted retries while attempts are left, then pauses; both end the attempt. */
    public function testRetryPolicyMayPauseOnceExhausted(): void
    {
        $retry = self::running(contentType: self::V7);
        $handle = $retry->sysRun('charge');
        $retry->doAwait($handle);
        self::assertSame(
            RunCompletionStatus::AttemptEnded,
            $retry->proposeRunCompletionRetryable($handle, 'unavailable', 5, RunRetryPolicy::fixedDelay(100, 5, onMaxAttempts: OnMaxAttempts::Pause)),
        );
        self::assertSame(
            bin2hex(FrameCodec::runCommandFrame(1, 'charge') . FrameCodec::errorFrame(500, 'unavailable', relatedCommandIndex: 1, relatedCommandName: 'charge', relatedCommandType: FrameCodec::RUN_COMMAND, nextRetryDelayMillis: 100)),
            bin2hex(self::written($retry)),
        );

        $pause = self::running(contentType: self::V7);
        $handle = $pause->sysRun('charge');
        $pause->doAwait($handle);
        self::assertSame(
            RunCompletionStatus::AttemptEnded,
            $pause->proposeRunCompletionRetryable($handle, 'unavailable', 5, RunRetryPolicy::exponential(maxAttempts: 0, onMaxAttempts: OnMaxAttempts::Pause), code: 503),
        );
        self::assertSame(
            bin2hex(FrameCodec::runCommandFrame(1, 'charge') . FrameCodec::errorFrame(503, 'unavailable', relatedCommandIndex: 1, relatedCommandName: 'charge', relatedCommandType: FrameCodec::RUN_COMMAND, behavior: FrameCodec::ERROR_BEHAVIOR_PAUSE)),
            bin2hex(self::written($pause)),
        );
    }

    /** A success never uses the policy, so a policy that pauses once exhausted does not stop the run completing. */
    public function testRetryPolicyThatPausesLetsARunSucceed(): void
    {
        $invocation = self::running(contentType: self::V7);
        $handle = $invocation->sysRun('charge');
        $invocation->doAwait($handle);

        $invocation->proposeRunCompletionSuccess($handle, '42', RunRetryPolicy::fixedDelay(100, 5, onMaxAttempts: OnMaxAttempts::Pause));

        // Compared without the frame flags: on v7 the proposal asks Restate to acknowledge it.
        self::assertSame(
            FrameCodec::frames(FrameCodec::runCommandFrame(1, 'charge') . FrameCodec::runCompletionSuccessFrame(1, '42')),
            FrameCodec::frames(self::written($invocation)),
        );
    }

    public function testRetryPolicyRejectsAnUnknownOnMaxAttempts(): void
    {
        $vm = self::runningVm();
        $handle = $vm->sysRun('charge');
        $vm->doAwait($handle);

        $this->expectException(\Restate\Extension\ExtensionException::class);
        $this->expectExceptionMessage("unsupported retry policy 'on_max_attempts': kill");

        // @phpstan-ignore argument.type (the shape PHPStan knows forbids it; the extension must refuse it at run time)
        $vm->proposeRunRetryable($handle, 500, 'unavailable', [], 5, ['type' => 'fixed_delay', 'on_max_attempts' => 'kill']);
    }
}
