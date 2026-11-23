<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\Backend\RunCompletionStatus;
use Restate\Internal\SuspensionException;
use Restate\RetryableError;
use Restate\RunOptions;
use Restate\RunRetryPolicy;
use Restate\TerminalError;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class ContextRunRetryTest extends TestCase
{
    /** The handle of the test's run: ScriptedInvocation allocates handles from 1. */
    private const int RUN = 1;

    public function testRunSuccessPassesRetryPolicyJsonToBackendCompletion(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);
        $policy = RunRetryPolicy::fixedDelay(100, 3, 1000);

        $result = $ctx->run('calculate-report', static fn (): array => ['status' => 'ok'], $policy);

        self::assertSame(['status' => 'ok'], $result);
        self::assertSame([['calculate-report']], $invocation->calls('sysRun'));
        self::assertSame([self::RUN, self::RUN], $invocation->awaited());
        self::assertSame([self::RUN], $invocation->taken());
        self::assertEquals([[self::RUN, '{"status":"ok"}', $policy]], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSame([], $invocation->calls('proposeRunCompletionRetryable'));
    }

    public function testRunSuccessSuspendsUntilCompletionAcknowledgement(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysRun', AwaitState::ExecuteRun, AwaitState::Suspended);
        $ctx = Contexts::service($invocation);

        try {
            $ctx->run('count', static fn (): int => 1);
            self::fail('Expected run completion acknowledgement suspension.');
        } catch (SuspensionException $e) {
            self::assertSame('Run completion suspended.', $e->getMessage());
        }

        self::assertSame([['count']], $invocation->calls('sysRun'));
        self::assertSame([self::RUN, self::RUN], $invocation->awaited());
        self::assertEquals([[self::RUN, '1', RunRetryPolicy::infinite()]], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSame([], $invocation->calls('proposeRunCompletionRetryable'));
    }

    public function testRunTerminalErrorProposesTerminalFailureAndRethrows(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);
        $policy = RunRetryPolicy::none();
        $failure = new TerminalError('permission denied', 403, ['key1' => 'value1']);

        try {
            $ctx->run('guarded-run', static fn (): never => throw $failure, $policy);
            self::fail('Expected terminal exception.');
        } catch (TerminalError $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([self::RUN, self::RUN], $invocation->awaited());
        self::assertSame([[self::RUN, 403, 'permission denied', $policy, ['key1' => 'value1']]], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSame([], $invocation->calls('proposeRunCompletionRetryable'));
    }

    public function testRunTerminalErrorSuspendsUntilCompletionAcknowledgement(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysRun', AwaitState::ExecuteRun, AwaitState::Suspended);
        $ctx = Contexts::service($invocation);
        $policy = RunRetryPolicy::none();
        $failure = new TerminalError('permission denied', 403, ['key1' => 'value1']);

        try {
            $ctx->run('guarded-run', static fn (): never => throw $failure, $policy);
            self::fail('Expected run completion acknowledgement suspension.');
        } catch (SuspensionException $e) {
            self::assertSame('Run completion suspended.', $e->getMessage());
        }

        self::assertSame([['guarded-run']], $invocation->calls('sysRun'));
        self::assertSame([self::RUN, self::RUN], $invocation->awaited());
        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([[self::RUN, 403, 'permission denied', $policy, ['key1' => 'value1']]], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSame([], $invocation->calls('proposeRunCompletionRetryable'));
    }

    public function testRunRetryableErrorProposesRetryableFailureWithExponentialPolicyAndSuspends(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);
        $policy = RunRetryPolicy::exponential(50, 2.5, 500, 4, 2000);
        $failure = new RetryableError('try again later');

        try {
            $ctx->run('retryable-run', static fn (): never => throw $failure, $policy);
            self::fail('Expected suspension exception.');
        } catch (SuspensionException $e) {
            self::assertSame('Run retry scheduled.', $e->getMessage());
            self::assertSame($failure, $e->getPrevious());
        }

        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSingleRetryableCompletion($invocation, 'try again later', $policy);
    }

    public function testRunRetryableErrorProposesRetryableFailureWithPolicyAndSuspends(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);
        $policy = RunRetryPolicy::fixedDelay(100, 3, 1000);
        $failure = new RetryableError('temporary outage');

        try {
            $ctx->run('retryable-run', static fn (): never => throw $failure, $policy);
            self::fail('Expected suspension exception.');
        } catch (SuspensionException $e) {
            self::assertSame('Run retry scheduled.', $e->getMessage());
            self::assertSame($failure, $e->getPrevious());
        }

        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSingleRetryableCompletion($invocation, 'temporary outage', $policy);
    }

    public function testRunRetryableErrorPassesRetryAfterMillisToRetryableCompletion(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);
        $policy = RunRetryPolicy::fixedDelay(100, 3, 1000);
        $failure = new RetryableError('rate limited', retryAfterMillis: 250);

        try {
            $ctx->run('retryable-run', static fn (): never => throw $failure, $policy);
            self::fail('Expected suspension exception.');
        } catch (SuspensionException $e) {
            self::assertSame('Run retry scheduled.', $e->getMessage());
            self::assertSame($failure, $e->getPrevious());
        }

        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSingleRetryableCompletion($invocation, 'rate limited', $policy, 250);
    }

    public function testRunRetryableErrorWithNoRetryPolicyCompletesAsTerminalFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->retryableRunStatus = RunCompletionStatus::Completed;
        $ctx = Contexts::service($invocation);
        $failure = new RetryableError('retry me');

        try {
            $ctx->run('no-retry-run', static fn (): never => throw $failure, RunRetryPolicy::none());
            self::fail('Expected terminal exception.');
        } catch (TerminalError $e) {
            self::assertSame('retry me', $e->getMessage());
            self::assertSame(500, $e->statusCode());
        }

        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([self::RUN, self::RUN], $invocation->awaited());
        self::assertSame([self::RUN], $invocation->taken());
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSingleRetryableCompletion($invocation, 'retry me', RunRetryPolicy::none());
    }

    /**
     * The run's terminal failure is the one the core recorded when the policy was exhausted: the RetryableError's code
     * and metadata (shared-core 8.3.0+), not a 500 made up on the PHP side.
     */
    public function testRunRetryableErrorCodeAndMetadataBecomeTheTerminalFailureOnceThePolicyIsExhausted(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->retryableRunStatus = RunCompletionStatus::Completed;
        $ctx = Contexts::service($invocation);
        $policy = RunRetryPolicy::fixedDelay(10, maxAttempts: 2);
        $failure = new RetryableError('unavailable', 503, ['retry-reason' => 'maintenance']);

        try {
            $ctx->run('flaky', static fn (): never => throw $failure, $policy);
            self::fail('Expected terminal exception.');
        } catch (TerminalError $e) {
            self::assertSame('unavailable', $e->getMessage());
            self::assertSame(503, $e->statusCode());
            self::assertSame(['retry-reason' => 'maintenance'], $e->metadata());
        }

        self::assertSingleRetryableCompletion($invocation, 'unavailable', $policy, code: 503, metadata: ['retry-reason' => 'maintenance']);
    }

    public function testRunUnexpectedExceptionIsProposedWithCode500AndNoMetadata(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        try {
            $ctx->run('flaky', static fn (): never => throw new \RuntimeException('boom', 503));
            self::fail('Expected suspension exception.');
        } catch (SuspensionException) {
        }

        self::assertSingleRetryableCompletion($invocation, 'boom', RunRetryPolicy::infinite(), code: 500);
    }

    public function testRunRetryableCompletedFailureSuspendsUntilCompletionAcknowledgement(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysRun', AwaitState::ExecuteRun, AwaitState::Suspended);
        $invocation->retryableRunStatus = RunCompletionStatus::Completed;
        $ctx = Contexts::service($invocation);
        $failure = new RetryableError('retry me');

        try {
            $ctx->run('no-retry-run', static fn (): never => throw $failure, RunRetryPolicy::none());
            self::fail('Expected run completion acknowledgement suspension.');
        } catch (SuspensionException $e) {
            self::assertSame('Run completion suspended.', $e->getMessage());
        }

        self::assertSame([['no-retry-run']], $invocation->calls('sysRun'));
        self::assertSame([self::RUN, self::RUN], $invocation->awaited());
        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSingleRetryableCompletion($invocation, 'retry me', RunRetryPolicy::none());
    }

    public function testRunSuspendsWhenFirstAwaitSuspends(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysRun', AwaitState::Suspended);
        $ctx = Contexts::service($invocation);
        $callbackExecuted = false;

        try {
            $ctx->run('suspended-run', function () use (&$callbackExecuted): int {
                $callbackExecuted = true;

                return 1;
            });
            self::fail('Expected run suspension.');
        } catch (SuspensionException $e) {
            self::assertSame('Run suspended.', $e->getMessage());
        }

        self::assertFalse($callbackExecuted);
        self::assertSame([self::RUN], $invocation->awaited());
        self::assertSame([], $invocation->taken());
        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSame([], $invocation->calls('proposeRunCompletionRetryable'));
    }

    public function testRunIsCancelledWhenFirstAwaitReceivesCancelSignal(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysRun', AwaitState::CancelSignalReceived);
        $ctx = Contexts::service($invocation);
        $callbackExecuted = false;

        try {
            $ctx->run('cancelled-run', function () use (&$callbackExecuted): int {
                $callbackExecuted = true;

                return 1;
            });
            self::fail('Expected run cancellation.');
        } catch (TerminalError $e) {
            self::assertSame('Invocation cancelled', $e->getMessage());
            self::assertSame(409, $e->statusCode());
        }

        self::assertFalse($callbackExecuted);
        self::assertSame([self::RUN], $invocation->awaited());
        self::assertSame([], $invocation->taken());
        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSame([], $invocation->calls('proposeRunCompletionRetryable'));
    }

    public function testRunSuccessIsCancelledWhileAwaitingCompletion(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysRun', AwaitState::ExecuteRun, AwaitState::CancelSignalReceived);
        $ctx = Contexts::service($invocation);

        try {
            $ctx->run('count', static fn (): int => 1);
            self::fail('Expected run cancellation.');
        } catch (TerminalError $e) {
            self::assertSame('Invocation cancelled', $e->getMessage());
            self::assertSame(409, $e->statusCode());
        }

        self::assertSame([self::RUN, self::RUN], $invocation->awaited());
        self::assertSame([], $invocation->taken());
        self::assertEquals([[self::RUN, '1', RunRetryPolicy::infinite()]], $invocation->calls('proposeRunCompletionSuccess'));
    }

    /** @return iterable<string, array{\Throwable, ?RunCompletionStatus}> */
    public static function failedRunCallbacks(): iterable
    {
        yield 'terminal failure' => [new TerminalError('permission denied', 403), null];
        yield 'retryable failure with retries exhausted' => [new RetryableError('retry me'), RunCompletionStatus::Completed];
        yield 'unexpected exception with retries exhausted' => [new \RuntimeException('boom'), RunCompletionStatus::Completed];
    }

    #[DataProvider('failedRunCallbacks')]
    public function testRunFailureIsCancelledWhileAwaitingCompletionAcknowledgement(\Throwable $failure, ?RunCompletionStatus $retryableRunStatus): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysRun', AwaitState::ExecuteRun, AwaitState::CancelSignalReceived);
        if ($retryableRunStatus !== null) {
            $invocation->retryableRunStatus = $retryableRunStatus;
        }
        $ctx = Contexts::service($invocation);

        try {
            $ctx->run('failing-run', static fn (): never => throw $failure, RunRetryPolicy::none());
            self::fail('Expected run cancellation.');
        } catch (TerminalError $e) {
            self::assertSame('Invocation cancelled', $e->getMessage());
            self::assertSame(409, $e->statusCode());
        }

        self::assertSame([self::RUN, self::RUN], $invocation->awaited());
        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertCount(1, [...$invocation->calls('proposeRunCompletionFailure'), ...$invocation->calls('proposeRunCompletionRetryable')]);
    }

    public function testRunRetryableErrorRecordsCallbackAttemptDurationMillis(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        try {
            $ctx->run('slow-run', static function (): never {
                usleep(10000);

                throw new RetryableError('slow failure');
            });
            self::fail('Expected suspension exception.');
        } catch (SuspensionException $e) {
            self::assertSame('Run retry scheduled.', $e->getMessage());
        }

        $attemptDurationMillis = self::assertSingleRetryableCompletion($invocation, 'slow failure', RunRetryPolicy::infinite());
        self::assertGreaterThan(0, $attemptDurationMillis);
    }

    public function testRunRuntimeExceptionProposesRetryableFailureWithInfinitePolicyAndSuspends(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);
        $failure = new \RuntimeException('database unavailable');

        try {
            $ctx->run('runtime-run', static fn (): never => throw $failure);
            self::fail('Expected suspension exception.');
        } catch (SuspensionException $e) {
            self::assertSame('Run retry scheduled.', $e->getMessage());
            self::assertSame($failure, $e->getPrevious());
        }

        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSingleRetryableCompletion($invocation, 'database unavailable', RunRetryPolicy::infinite(), code: 500);
    }

    public function testRunSerializationFailurePropagatesWithoutRetryableCompletion(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        $thrown = null;
        try {
            $ctx->run('unserializable-run', static fn (): mixed => $resource);
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            fclose($resource);
        }

        self::assertInstanceOf(\JsonException::class, $thrown);
        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSame([], $invocation->calls('proposeRunCompletionRetryable'));
    }

    public function testRunNestedSuspensionPropagatesWithoutRetryableCompletion(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);
        $nested = new SuspensionException('nested');

        $thrown = null;
        try {
            $ctx->run('nested-suspension-run', static fn (): never => throw $nested);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertSame($nested, $thrown);
        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSame([], $invocation->calls('proposeRunCompletionRetryable'));
    }

    public function testRunReplayCompletedReturnsStoredResultAndDoesNotExecuteCallback(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysRun', NotificationResult::success('{"source":"replay"}'));
        $ctx = Contexts::service($invocation);
        $callbackExecuted = false;

        $result = $ctx->run('replayed-run', function () use (&$callbackExecuted): array {
            $callbackExecuted = true;

            return ['source' => 'callback'];
        }, RunRetryPolicy::none());

        self::assertSame(['source' => 'replay'], $result);
        self::assertFalse($callbackExecuted);
        self::assertSame([['replayed-run']], $invocation->calls('sysRun'));
        self::assertSame([self::RUN], $invocation->awaited());
        self::assertSame([self::RUN], $invocation->taken());
        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSame([], $invocation->calls('proposeRunCompletionRetryable'));
    }

    public function testRunReplayFailureAcceptsNumericMetadataKeysFromDecodedFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        // json_decode(..., true) turns the wire key "404" into the int key 404.
        $invocation->onNext('sysRun', NotificationResult::failure(404, 'missing', [404 => 'not found', 'reason' => 'gone']));
        $ctx = Contexts::service($invocation);

        try {
            $ctx->run('failed-replay-run', static fn (): null => null);
            self::fail('Expected terminal exception.');
        } catch (TerminalError $e) {
            self::assertSame(404, $e->statusCode());
            self::assertSame([404 => 'not found', 'reason' => 'gone'], $e->metadata());
        }
    }

    public function testRunReplayFailureThrowsTerminalErrorAndDoesNotExecuteCallback(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysRun', NotificationResult::failure(503, 'retry exhausted', ['key1' => 'value1']));
        $ctx = Contexts::service($invocation);
        $callbackExecuted = false;

        try {
            $ctx->run('failed-replay-run', function () use (&$callbackExecuted): void {
                $callbackExecuted = true;
            });
            self::fail('Expected terminal exception.');
        } catch (TerminalError $e) {
            self::assertSame('retry exhausted', $e->getMessage());
            self::assertSame(503, $e->statusCode());
            self::assertSame(['key1' => 'value1'], $e->metadata());
        }

        self::assertFalse($callbackExecuted);
        self::assertSame([['failed-replay-run']], $invocation->calls('sysRun'));
        self::assertSame([self::RUN], $invocation->awaited());
        self::assertSame([self::RUN], $invocation->taken());
        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSame([], $invocation->calls('proposeRunCompletionRetryable'));
    }

    public function testRunAcceptsRunOptions(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);
        $policy = RunRetryPolicy::fixedDelay(100, 3, 1000);

        $result = $ctx->run('calculate-report', static fn (): array => ['status' => 'ok'], RunOptions::retryPolicy($policy));

        self::assertSame(['status' => 'ok'], $result);
        self::assertEquals([[self::RUN, '{"status":"ok"}', $policy]], $invocation->calls('proposeRunCompletionSuccess'));
    }

    /**
     * @param int $code what PHP hands over: a RetryableError's status code, 500 for any other exception
     * @param ?RunRetryPolicy $retryPolicy null means infinite
     * @param array<array-key, string> $metadata
     */
    private static function assertSingleRetryableCompletion(ScriptedInvocation $invocation, string $message, ?RunRetryPolicy $retryPolicy, ?int $retryAfterMillis = null, int $code = 500, array $metadata = []): int
    {
        self::assertCount(1, $invocation->calls('proposeRunCompletionRetryable'));
        [$handle, $actualMessage, $attemptDurationMillis, $actualRetryPolicy, $actualRetryAfterMillis, $actualCode, $actualMetadata] = $invocation->calls('proposeRunCompletionRetryable')[0];

        self::assertSame(self::RUN, $handle);
        self::assertSame($message, $actualMessage);
        self::assertGreaterThanOrEqual(0, $attemptDurationMillis);
        self::assertEquals($retryPolicy, $actualRetryPolicy);
        self::assertSame($retryAfterMillis, $actualRetryAfterMillis);
        self::assertSame($code, $actualCode);
        self::assertSame($metadata, $actualMetadata);

        return $attemptDurationMillis;
    }
}
