<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Internal\Context\DurableFutureImpl;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\SuspensionException;
use Restate\TerminalError;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;
use Restate\WorkflowContext;

final class DurablePromiseTest extends TestCase
{
    public function testWorkflowContextReturnsWorkflowKey(): void
    {
        $ctx = Contexts::workflow(ScriptedInvocation::new(), 'checkout-1');

        self::assertSame('checkout-1', $ctx->key());
    }

    public function testPromiseValueAwaitsThePromiseHandleAndDecodesItsJson(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysGetPromise', NotificationResult::success('{"status":"approved","reviewers":["ops"]}'));
        $ctx = Contexts::workflow($invocation, 'checkout-1');

        self::assertSame(['status' => 'approved', 'reviewers' => ['ops']], $ctx->promise('approval')->value());
        self::assertSame([['approval']], $invocation->calls('sysGetPromise'));
        self::assertSame([1], $invocation->awaited());
        self::assertSame([1], $invocation->taken());
    }

    public function testPromiseValueThrowsJsonExceptionForMalformedBackendJson(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysGetPromise', NotificationResult::success('{bad json'));
        $ctx = Contexts::workflow($invocation, 'checkout-1');

        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('Syntax error');

        $ctx->promise('approval')->value();
    }

    /** @param array<array-key, string> $metadata */
    #[DataProvider('failureMetadataProvider')]
    public function testPromiseValueThrowsTerminalErrorForRejectedPromiseWithMetadata(array $metadata): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysGetPromise', NotificationResult::failure(409, 'denied', $metadata));
        $ctx = Contexts::workflow($invocation, 'checkout-1');

        try {
            $ctx->promise('approval')->value();
            self::fail('Expected rejected durable promise value to throw.');
        } catch (TerminalError $exception) {
            self::assertSame('denied', $exception->getMessage());
            self::assertSame(409, $exception->statusCode());
            self::assertSame($metadata, $exception->metadata());
        }
    }

    /** @return iterable<string, array{array<array-key, string>}> */
    public static function failureMetadataProvider(): iterable
    {
        yield 'string keys' => [['reason' => 'fraud']];
        yield 'numeric keys' => [[404 => 'not found', 'reason' => 'gone']];
    }

    public function testPromiseValueSuspendsWithoutTakingTheResult(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysGetPromise', AwaitState::Suspended);
        $ctx = Contexts::workflow($invocation, 'checkout-1');

        try {
            $ctx->promise('approval')->value();
            self::fail('Expected the promise get to suspend.');
        } catch (SuspensionException $exception) {
            self::assertSame('Promise get suspended.', $exception->getMessage());
        }
        self::assertSame([], $invocation->taken());
    }

    public function testPromiseFutureReturnsDurableFutureFromBackendPromiseHandle(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysGetPromise', NotificationResult::success('{"status":"approved"}'));
        $promise = Contexts::workflow($invocation, 'checkout-1')->promise('approval');

        $future = $promise->future();

        self::assertInstanceOf(DurableFutureImpl::class, $future);
        self::assertSame([$future->handle], $invocation->handles('sysGetPromise'));
        self::assertSame(['status' => 'approved'], $future->awaitResult());
        self::assertSame([['approval']], $invocation->calls('sysGetPromise'));
    }

    public function testWorkflowContextPromiseFutureReturnsDurableFuture(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysGetPromise', NotificationResult::success('{"status":"approved"}'));
        $ctx = Contexts::workflow($invocation, 'checkout-1');

        $future = $ctx->promiseFuture('approval');

        self::assertSame(['status' => 'approved'], $future->awaitResult());
        self::assertSame([['approval']], $invocation->calls('sysGetPromise'));
    }

    public function testPromisePeekAwaitsThePeekHandleAndDecodesItsJson(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysPeekPromise', NotificationResult::success('{"status":"approved"}'));
        $ctx = Contexts::workflow($invocation, 'checkout-1');

        self::assertSame(['status' => 'approved'], $ctx->promise('approval')->peek());
        self::assertSame([['approval']], $invocation->calls('sysPeekPromise'));
        self::assertSame([1], $invocation->awaited());
        self::assertSame([1], $invocation->taken());
    }

    public function testPromisePeekReturnsNullWhileThePromiseIsNotCompleted(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::workflow($invocation, 'checkout-1');

        self::assertNull($ctx->promise('missing')->peek());
        self::assertSame([['missing']], $invocation->calls('sysPeekPromise'));
    }

    public function testPromisePeekThrowsTerminalErrorForFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysPeekPromise', NotificationResult::failure(409, 'denied'));
        $ctx = Contexts::workflow($invocation, 'checkout-1');

        $this->expectException(TerminalError::class);
        $this->expectExceptionMessage('denied');

        $ctx->promise('approval')->peek();
    }

    public function testPromisePeekSuspends(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysPeekPromise', AwaitState::Suspended);
        $ctx = Contexts::workflow($invocation, 'checkout-1');

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('Promise peek suspended.');

        $ctx->promise('approval')->peek();
    }

    public function testSharedPromiseResolveJsonEncodesValueAndAwaitsTheCompletion(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::workflowShared($invocation, 'checkout-1');

        $ctx->promise('approval')->resolve(['status' => 'approved']);

        self::assertSame([['approval', '{"status":"approved"}']], $invocation->calls('sysResolvePromise'));
        self::assertSame([1], $invocation->awaited());
        self::assertSame([1], $invocation->taken());
    }

    public function testSharedPromiseRejectDelegatesTerminalErrorToBackendAndAwaitsTheCompletion(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::workflowShared($invocation, 'checkout-1');

        $ctx->promise('approval')->reject(new TerminalError('denied', 409, ['reason' => 'fraud']));

        self::assertSame([
            ['approval', 409, 'denied', ['reason' => 'fraud']],
        ], $invocation->calls('sysRejectPromise'));
        self::assertSame([1], $invocation->awaited());
        self::assertSame([1], $invocation->taken());
    }

    public function testCompletingAnAlreadyCompletedPromiseThrowsItsTerminalFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysResolvePromise', NotificationResult::failure(409, 'promise already completed'));
        $ctx = Contexts::workflowShared($invocation, 'checkout-1');

        $this->expectException(TerminalError::class);
        $this->expectExceptionMessage('promise already completed');

        $ctx->promise('approval')->resolve(['status' => 'approved']);
    }

    /** @return iterable<string, array{0: \Closure(WorkflowContext): mixed, 1: string}> */
    public static function promiseOperationProvider(): iterable
    {
        yield 'value' => [static fn (WorkflowContext $ctx): mixed => $ctx->promise('approval')->value(), 'sysGetPromise'];
        yield 'peek' => [static fn (WorkflowContext $ctx): mixed => $ctx->promise('approval')->peek(), 'sysPeekPromise'];
        yield 'resolve' => [static function (WorkflowContext $ctx): null {
            $ctx->promise('approval')->resolve(['status' => 'approved']);

            return null;
        }, 'sysResolvePromise'];
        yield 'reject' => [static function (WorkflowContext $ctx): null {
            $ctx->promise('approval')->reject(new TerminalError('denied', 409));

            return null;
        }, 'sysRejectPromise'];
    }

    /** @param \Closure(WorkflowContext): mixed $operation */
    #[DataProvider('promiseOperationProvider')]
    public function testPromiseOperationsAreCancelledWhenTheirAwaitReceivesTheCancelSignal(\Closure $operation, string $promiseOperation): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext($promiseOperation, AwaitState::CancelSignalReceived);
        $ctx = Contexts::workflow($invocation, 'checkout-1');

        try {
            $operation($ctx);
            self::fail('Expected the promise operation to be cancelled.');
        } catch (TerminalError $exception) {
            self::assertSame('Invocation cancelled', $exception->getMessage());
            self::assertSame(409, $exception->statusCode());
        }
        self::assertSame($invocation->handles($promiseOperation), $invocation->awaited());
        self::assertSame([], $invocation->taken());
    }

    public function testPromiseResolutionSuspends(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysResolvePromise', AwaitState::Suspended);
        $ctx = Contexts::workflowShared($invocation, 'checkout-1');

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('Promise resolution suspended.');

        $ctx->promise('approval')->resolve(['status' => 'approved']);
    }
}
