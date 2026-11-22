<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\Endpoint;
use Restate\Exception;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\Invocation;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\Backend\ResponseHead;
use Restate\Internal\Backend\RunCompletionStatus;
use Restate\Internal\BackendErrorCategory;
use Restate\Internal\BackendException;
use Restate\Internal\ClosureErrorMapper;
use Restate\Internal\ExecutionHooks;
use Restate\Internal\SuspensionException;
use Restate\Internal\SuspensionGuard;
use Restate\OnMaxAttempts;
use Restate\PauseError;
use Restate\RetryableError;
use Restate\RunExecution;
use Restate\RunInterceptor;
use Restate\RunRetryPolicy;
use Restate\Service;
use Restate\Suspension;
use Restate\TerminalError;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\FakeBackend;
use Restate\Tests\Support\ScriptedInvocation;

/**
 * Pausing the invocation instead of retrying it: a {@see PauseError} from a handler or a run, one an error mapper
 * returns, and a run retry policy that pauses once exhausted.
 */
final class PauseTest extends TestCase
{
    /** The handle of the test's run: ScriptedInvocation allocates handles from 1. */
    private const int RUN = 1;

    public function testPauseErrorIsAnSdkExceptionButNeitherRetryableNorTerminal(): void
    {
        $previous = new \RuntimeException('ledger offline');
        $pause = new PauseError('waiting for the ledger', $previous);

        self::assertContains(Exception::class, class_implements(PauseError::class));
        self::assertNotContains(RetryableError::class, class_parents(PauseError::class));
        self::assertNotContains(TerminalError::class, class_parents(PauseError::class));
        self::assertSame('waiting for the ledger', $pause->getMessage());
        self::assertSame($previous, $pause->getPrevious());
    }

    public function testAPauseErrorFromARunEndsTheAttemptAskingToPause(): void
    {
        $invocation = ScriptedInvocation::new();
        $guard = new SuspensionGuard($invocation);
        $ctx = Contexts::service($guard);

        try {
            $ctx->run('charge', static fn (): never => throw new PauseError('approval needed'), RunRetryPolicy::fixedDelay(100, 5));
            self::fail('Expected the pause to end the attempt.');
        } catch (SuspensionException $e) {
            self::assertTrue(Suspension::is($e));
            self::assertInstanceOf(PauseError::class, $e->getPrevious());
        }

        $calls = $invocation->calls('proposeRunCompletionPause');
        self::assertCount(1, $calls);
        [$handle, $message, $attemptDurationMillis] = $calls[0];
        self::assertSame([self::RUN, 'approval needed'], [$handle, $message]);
        self::assertGreaterThanOrEqual(0, $attemptDurationMillis);
        self::assertSame([], $invocation->calls('proposeRunCompletionRetryable'));
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
        // The attempt is over: no context operation reaches the VM any more.
        self::assertTrue($guard->isSuspended());
        $this->expectException(SuspensionException::class);
        $ctx->sleep(1000);
    }

    public function testTheErrorMapperMayPauseARun(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation, hooks: ExecutionHooks::of([], ClosureErrorMapper::of(
            static fn (\Throwable $e): ?PauseError => $e instanceof \DomainException ? new PauseError("paused: {$e->getMessage()}", $e) : null,
        )));

        try {
            $ctx->run('charge', static fn (): never => throw new \DomainException('ledger offline'));
            self::fail('Expected the pause to end the attempt.');
        } catch (SuspensionException) {
        }

        self::assertSame('paused: ledger offline', $invocation->calls('proposeRunCompletionPause')[0][1]);
        self::assertSame([], $invocation->calls('proposeRunCompletionRetryable'));
    }

    public function testTheErrorMapperIsNotAskedAboutAPauseError(): void
    {
        $mapped = [];
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation, hooks: ExecutionHooks::of([], ClosureErrorMapper::of(static function (\Throwable $e) use (&$mapped): TerminalError {
            $mapped[] = $e;

            return new TerminalError('mapped', 400);
        })));

        try {
            $ctx->run('charge', static fn (): never => throw new PauseError('approval needed'));
            self::fail('Expected the pause to end the attempt.');
        } catch (SuspensionException) {
        }

        self::assertSame([], $mapped);
        self::assertCount(1, $invocation->calls('proposeRunCompletionPause'));
    }

    public function testARunInterceptorSeesThePauseError(): void
    {
        /** @var \ArrayObject<int, \Throwable> $seen */
        $seen = new \ArrayObject();
        $interceptor = new class ($seen) implements RunInterceptor {
            /** @param \ArrayObject<int, \Throwable> $seen */
            public function __construct(private readonly \ArrayObject $seen)
            {
            }

            public function interceptRun(RunExecution $execution, \Closure $next): void
            {
                try {
                    $next();
                } catch (\Throwable $e) {
                    $this->seen->append($e);

                    throw $e;
                }
            }
        };
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation, hooks: ExecutionHooks::of([$interceptor]));

        try {
            $ctx->run('charge', static fn (): never => throw new PauseError('approval needed'));
            self::fail('Expected the pause to end the attempt.');
        } catch (SuspensionException) {
        }

        self::assertCount(1, $seen);
        self::assertInstanceOf(PauseError::class, $seen[0]);
    }

    /** Below protocol v7 the VM refuses the pause and has already failed the attempt; the run throws that failure. */
    public function testARefusedRunPauseFailsTheRunWithTheBackendError(): void
    {
        $refused = new BackendException('Pausing the invocation needs service protocol v7', 573, category: BackendErrorCategory::Protocol);
        $invocation = ScriptedInvocation::new();
        $invocation->runPauseFailure = $refused;
        $ctx = Contexts::service($invocation);

        try {
            $ctx->run('charge', static fn (): never => throw new PauseError('approval needed'));
            self::fail('Expected the refused pause to fail the run.');
        } catch (BackendException $e) {
            self::assertSame($refused, $e);
        }
    }

    public function testARetryPolicyMayPauseOnceExhausted(): void
    {
        self::assertSame(
            ['type' => 'fixed_delay', 'interval_millis' => 100, 'max_attempts' => 3, 'max_duration_millis' => null, 'on_max_attempts' => 'pause'],
            RunRetryPolicy::fixedDelay(100, 3, onMaxAttempts: OnMaxAttempts::Pause)->toArray(),
        );
        self::assertSame(
            ['type' => 'exponential', 'initial_interval_millis' => 100, 'factor' => 2.0, 'max_interval_millis' => 2000, 'max_attempts' => 3, 'max_duration_millis' => 50000, 'on_max_attempts' => 'pause'],
            RunRetryPolicy::exponential(maxAttempts: 3, onMaxAttempts: OnMaxAttempts::Pause)->toArray(),
        );
        self::assertSame('fail', RunRetryPolicy::fixedDelay(100, 3)->toArray()['on_max_attempts'] ?? null);
        self::assertSame('fail', RunRetryPolicy::exponential()->toArray()['on_max_attempts'] ?? null);
    }

    /**
     * Under a pausing policy the VM cannot tell a scheduled retry from a pause: either way the attempt ends as after a
     * suspension, with the policy handed over unchanged.
     */
    public function testAnAttemptEndedUnderAPausingPolicyEndsTheAttempt(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->retryableRunStatus = RunCompletionStatus::AttemptEnded;
        $policy = RunRetryPolicy::fixedDelay(100, 3, onMaxAttempts: OnMaxAttempts::Pause);
        $ctx = Contexts::service($invocation);

        try {
            $ctx->run('charge', static fn (): never => throw new RetryableError('unavailable', code: 503), $policy);
            self::fail('Expected the attempt to end.');
        } catch (SuspensionException $e) {
            self::assertSame('Run retry scheduled, or the invocation paused because the run retry policy is exhausted.', $e->getMessage());
        }

        self::assertSame($policy, $invocation->calls('proposeRunCompletionRetryable')[0][3]);
        self::assertSame([], $invocation->calls('proposeRunCompletionPause'));
    }

    public function testAnAttemptEndedUnderAPolicyThatDoesNotPauseIsAScheduledRetry(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        try {
            $ctx->run('charge', static fn (): never => throw new RetryableError('unavailable'), RunRetryPolicy::fixedDelay(100, 3));
            self::fail('Expected the attempt to end.');
        } catch (SuspensionException $e) {
            self::assertSame('Run retry scheduled.', $e->getMessage());
        }
    }

    public function testAPauseErrorFromAHandlerAsksRestateToPause(): void
    {
        $paused = [];
        $invocation = $this->invocation();
        $invocation->method('notifyPause')->willReturnCallback(static function (string $message, string $stacktrace = '') use (&$paused): void {
            $paused[] = [$message, $stacktrace !== ''];
        });
        $invocation->expects(self::never())->method('notifyError');
        $invocation->expects(self::never())->method('writeOutputFailure');
        $invocation->expects(self::never())->method('end');

        $this->endpoint($invocation, Service::new('Approvals')->handler('review', static fn (Context $ctx, mixed $_): never => throw new PauseError('needs a human')))
            ->handleRequest(['REQUEST_URI' => '/invoke/Approvals/review', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v7'], '');

        self::assertSame([['needs a human', true]], $paused);
    }

    public function testTheErrorMapperMayPauseAHandler(): void
    {
        $paused = [];
        $invocation = $this->invocation();
        $invocation->method('notifyPause')->willReturnCallback(static function (string $message) use (&$paused): void {
            $paused[] = $message;
        });
        $invocation->expects(self::never())->method('notifyError');

        $service = Service::new('Approvals')
            ->handler('review', static fn (Context $ctx, mixed $_): never => throw new \LogicException('bug'))
            ->errorMapper(static fn (\Throwable $e): ?PauseError => $e instanceof \LogicException ? new PauseError("paused on a bug: {$e->getMessage()}", $e) : null);
        $this->endpoint($invocation, $service)
            ->handleRequest(['REQUEST_URI' => '/invoke/Approvals/review', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v7'], '');

        self::assertSame(['paused on a bug: bug'], $paused);
    }

    /** A run's pause ends the attempt; the endpoint reports nothing more, whatever the handler does next. */
    public function testARunPauseEndsTheHandlersAttempt(): void
    {
        $invocation = $this->invocation();
        $invocation->method('sysRun')->willReturn(7);
        $invocation->method('doAwait')->willReturn(AwaitResult::executeRun(7));
        $invocation->expects(self::once())->method('proposeRunCompletionPause')->with(7, 'approval needed');
        $invocation->expects(self::never())->method('notifyPause');
        $invocation->expects(self::never())->method('notifyError');
        $invocation->expects(self::never())->method('writeOutputSuccess');
        $invocation->expects(self::never())->method('end');

        $this->endpoint($invocation, Service::new('Approvals')->handler('review', static function (Context $ctx, mixed $_): string {
            try {
                $ctx->run('approve', static fn (): never => throw new PauseError('approval needed'));
            } catch (\Throwable) {
            }

            return 'approved';
        }))->handleRequest(['REQUEST_URI' => '/invoke/Approvals/review', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v7'], '');
    }

    private function endpoint(Invocation $invocation, Service $service): Endpoint
    {
        return Endpoint::create(new FakeBackend($invocation), [$service]);
    }

    private function invocation(): Invocation&MockObject
    {
        $invocation = $this->createMock(Invocation::class);
        $invocation->method('responseHead')->willReturn(new ResponseHead(200, []));
        $invocation->method('isReadyToExecute')->willReturn(true);
        $invocation->method('input')->willReturn(new InvocationInput('inv-1', 0, '', [], '"Mary"'));
        $invocation->method('takeOutput')->willReturn(null);

        return $invocation;
    }
}
