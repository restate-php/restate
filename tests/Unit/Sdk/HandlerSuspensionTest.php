<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\Endpoint;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\Invocation;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\Backend\ResponseHead;
use Restate\Internal\Backend\RunCompletionStatus;
use Restate\Internal\SuspensionException;
use Restate\ObjectContext;
use Restate\RetryableError;
use Restate\RunRetryPolicy;
use Restate\Service;
use Restate\Target;
use Restate\TerminalError;
use Restate\Tests\Support\FakeBackend;
use Restate\VirtualObject;

/**
 * A suspension ends the attempt: handler code must not be able to swallow it, and nothing it does
 * after a swallowed suspension may reach the journal or the response.
 */
final class HandlerSuspensionTest extends TestCase
{
    public function testCatchingExceptionAroundSleepDoesNotSwallowSuspension(): void
    {
        $invocation = $this->suspendingInvocation();
        $invocation->expects(self::never())->method('writeOutputSuccess');
        $invocation->expects(self::never())->method('writeOutputFailure');
        $invocation->expects(self::never())->method('notifyError');
        $invocation->expects(self::never())->method('end');
        $catchBlockRan = false;

        $this->endpoint($invocation, Service::new('Sleeper')->handler('nap', static function (Context $ctx, mixed $_) use (&$catchBlockRan): string {
            try {
                $ctx->sleep(1000);
            } catch (\Exception) {
                $catchBlockRan = true;

                return 'swallowed';
            }

            return 'slept';
        }))->handleRequest(['REQUEST_URI' => '/invoke/Sleeper/nap', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], '');

        self::assertFalse($catchBlockRan);
    }

    public function testContextOperationsFailFastAfterASwallowedSuspension(): void
    {
        $invocation = $this->suspendingInvocation();
        $invocation->expects(self::once())->method('sysSleep');
        $invocation->expects(self::never())->method('sysCall');
        $invocation->expects(self::never())->method('sysRun');
        $invocation->expects(self::never())->method('writeOutputSuccess');
        $invocation->expects(self::never())->method('notifyError');
        $invocation->expects(self::never())->method('end');
        $afterSuspension = [];

        $this->endpoint($invocation, Service::new('Sleeper')->handler('nap', static function (Context $ctx, mixed $_) use (&$afterSuspension): string {
            try {
                $ctx->sleep(1000);
            } catch (\Throwable) {
            }
            try {
                $ctx->call(Target::service('Greeter', 'greet'), 'Mary');
            } catch (\Throwable $e) {
                $afterSuspension[] = $e;
            }
            try {
                $ctx->run('side-effect', static fn (): int => 1);
            } catch (\Throwable $e) {
                $afterSuspension[] = $e;
            }

            return 'done';
        }))->handleRequest(['REQUEST_URI' => '/invoke/Sleeper/nap', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], '');

        self::assertCount(2, $afterSuspension);
        self::assertContainsOnlyInstancesOf(SuspensionException::class, $afterSuspension);
    }

    public function testTerminalErrorThrownAfterASwallowedSuspensionIsNotWritten(): void
    {
        $invocation = $this->suspendingInvocation();
        $invocation->expects(self::never())->method('writeOutputFailure');
        $invocation->expects(self::never())->method('notifyError');
        $invocation->expects(self::never())->method('end');

        $this->endpoint($invocation, Service::new('Sleeper')->handler('nap', static function (Context $ctx, mixed $_): never {
            try {
                $ctx->sleep(1000);
            } catch (\Throwable) {
            }

            throw new TerminalError('sleep failed', 500);
        }))->handleRequest(['REQUEST_URI' => '/invoke/Sleeper/nap', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], '');
    }

    public function testSuspensionWrappedInAnotherExceptionIsNotReportedAsAnError(): void
    {
        $invocation = $this->suspendingInvocation();
        $invocation->expects(self::never())->method('writeOutputFailure');
        $invocation->expects(self::never())->method('notifyError');
        $invocation->expects(self::never())->method('end');

        $this->endpoint($invocation, Service::new('Sleeper')->handler('nap', static function (Context $ctx, mixed $_): never {
            try {
                $ctx->sleep(1000);
            } catch (\Throwable $e) {
                throw new \RuntimeException('sleep failed', previous: $e);
            }

            throw new \LogicException('unreachable');
        }))->handleRequest(['REQUEST_URI' => '/invoke/Sleeper/nap', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], '');
    }

    public function testStateOperationsFailFastAfterABackendSuspension(): void
    {
        $invocation = $this->invocation();
        $invocation->method('sysGetState')->willThrowException(new SuspensionException('State get suspended.'));
        $invocation->expects(self::never())->method('setState');
        $invocation->expects(self::never())->method('writeOutputSuccess');
        $invocation->expects(self::never())->method('end');
        $afterSuspension = null;

        $this->endpoint($invocation, VirtualObject::new('Counter')->handler('add', static function (ObjectContext $ctx, mixed $_) use (&$afterSuspension): string {
            try {
                $ctx->get('count');
            } catch (\Throwable) {
            }
            try {
                $ctx->set('count', 1);
            } catch (\Throwable $e) {
                $afterSuspension = $e;
            }

            return 'done';
        }))->handleRequest(['REQUEST_URI' => '/invoke/Counter/alice/add', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], '');

        self::assertInstanceOf(SuspensionException::class, $afterSuspension);
    }

    public function testContextOperationsFailFastAfterARunRetryIsScheduled(): void
    {
        $invocation = $this->invocation();
        $invocation->method('sysRun')->willReturn(7);
        $invocation->method('doAwait')->willReturn(AwaitResult::executeRun(7));
        $invocation->method('proposeRunCompletionRetryable')->willReturn(RunCompletionStatus::AttemptEnded);
        $invocation->expects(self::never())->method('sysSleep');
        $invocation->expects(self::never())->method('writeOutputSuccess');
        $invocation->expects(self::never())->method('notifyError');
        $invocation->expects(self::never())->method('end');
        $caught = [];

        $this->endpoint($invocation, Service::new('Charger')->handler('charge', static function (Context $ctx, mixed $_) use (&$caught): string {
            try {
                $ctx->run('charge', static fn (): never => throw new RetryableError('rate limited'), RunRetryPolicy::fixedDelay(100));
            } catch (\Throwable $e) {
                $caught[] = $e;
            }
            try {
                $ctx->sleep(1000);
            } catch (\Throwable $e) {
                $caught[] = $e;
            }

            return 'charged';
        }))->handleRequest(['REQUEST_URI' => '/invoke/Charger/charge', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], '');

        self::assertCount(2, $caught);
        self::assertContainsOnlyInstancesOf(SuspensionException::class, $caught);
    }

    public function testRunCallbackThatSwallowsASuspensionCannotCompleteTheRun(): void
    {
        $invocation = $this->invocation();
        $invocation->method('sysRun')->willReturn(7);
        $invocation->method('sysSleep')->willReturn(8);
        $invocation->method('doAwait')->willReturnCallback(static fn (int $handle): AwaitResult => $handle === 7 ? AwaitResult::executeRun(7) : AwaitResult::suspended());
        $invocation->expects(self::never())->method('proposeRunCompletionSuccess');
        $invocation->expects(self::never())->method('proposeRunCompletionRetryable');
        $invocation->expects(self::never())->method('writeOutputSuccess');
        $invocation->expects(self::never())->method('notifyError');
        $invocation->expects(self::never())->method('end');

        $this->endpoint($invocation, Service::new('Sleeper')->handler('nap', static function (Context $ctx, mixed $_): mixed {
            return $ctx->run('nap', static function () use ($ctx): int {
                try {
                    $ctx->sleep(1000);
                } catch (\Throwable) {
                }

                return 1;
            });
        }))->handleRequest(['REQUEST_URI' => '/invoke/Sleeper/nap', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], '');
    }

    private function endpoint(Invocation $invocation, Service|VirtualObject $service): Endpoint
    {
        $backend = new FakeBackend($invocation);

        return Endpoint::create($backend, [$service]);
    }

    /** An invocation whose every await reports that the invocation suspended. */
    private function suspendingInvocation(): Invocation&MockObject
    {
        $invocation = $this->invocation();
        $invocation->method('doAwait')->willReturn(AwaitResult::suspended());
        $invocation->method('doAwaitTree')->willReturn(AwaitResult::suspended());

        return $invocation;
    }

    private function invocation(): Invocation&MockObject
    {
        $invocation = $this->createMock(Invocation::class);
        $invocation->method('responseHead')->willReturn(new ResponseHead(200, []));
        $invocation->method('isReadyToExecute')->willReturn(true);
        $invocation->method('input')->willReturn(new InvocationInput('inv-1', 0, 'alice', [], '"Mary"'));
        $invocation->method('takeOutput')->willReturn(null);

        return $invocation;
    }
}
