<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use Restate\Internal\Context\InvocationHandleImpl;
use PHPUnit\Framework\TestCase;
use Restate\AttachTarget;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\SuspensionException;
use Restate\Target;
use Restate\TerminalError;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class InvocationHandleTest extends TestCase
{
    public function testContextCreatesKnownInvocationHandle(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        $handle = $ctx->invocation('inv-1');

        self::assertSame('inv-1', $handle->id());
    }

    public function testContextAttachesTargetAndOutputUsesAttachOperation(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysAttachInvocation', NotificationResult::success('"attached output"'));
        $ctx = Contexts::service($invocation);

        $handle = $ctx->attach(AttachTarget::workflow('Checkout', 'order-1'));

        self::assertSame('attached output', $handle->output());
        self::assertEquals([[AttachTarget::workflow('Checkout', 'order-1')]], $invocation->calls('sysAttachInvocation'));
        self::assertSame([], $invocation->calls('sysGetInvocationOutput'));
    }

    public function testAttachedHandleCannotResolveInvocationId(): void
    {
        $invocation = ScriptedInvocation::new();
        $handle = new InvocationHandleImpl($invocation, AttachTarget::workflow('Checkout', 'order-1'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invocation ID is not available for this handle.');

        $handle->id();
    }

    public function testPendingHandleResolvesAndCachesInvocationId(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(66, NotificationResult::invocationId('inv-66'));
        $handle = InvocationHandleImpl::pending($invocation, 66);

        self::assertSame('inv-66', $handle->id());
        self::assertSame('inv-66', $handle->id());
        self::assertSame([66], $invocation->awaited());
        self::assertSame([66], $invocation->taken());
    }

    public function testPendingHandleOutputAttachesToResolvedInvocationId(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(66, NotificationResult::invocationId('inv-66'));
        $invocation->onNext('sysAttachInvocation', NotificationResult::success('"pending output"'));
        $handle = InvocationHandleImpl::pending($invocation, 66);

        self::assertSame('pending output', $handle->output());
        self::assertSame([66, 1], $invocation->awaited());
        self::assertSame([66, 1], $invocation->taken());
        self::assertEquals([[AttachTarget::invocationId('inv-66')]], $invocation->calls('sysAttachInvocation'));
        self::assertSame([], $invocation->calls('sysGetInvocationOutput'));
    }

    public function testOutputOfAKnownInvocationWaitsForItToFinish(): void
    {
        // A get-output command completes with "not ready" (void) while the invocation runs; output() must not use it.
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysGetInvocationOutput', NotificationResult::void());
        $invocation->onNext('sysAttachInvocation', NotificationResult::success('"finished"'));
        $ctx = Contexts::service($invocation);

        self::assertSame('finished', $ctx->invocation('inv-1')->output());
        self::assertEquals([[AttachTarget::invocationId('inv-1')]], $invocation->calls('sysAttachInvocation'));
        self::assertSame([], $invocation->calls('sysGetInvocationOutput'));
    }

    public function testOutputOfASentInvocationWaitsForItToFinish(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysSend', NotificationResult::invocationId('inv-sent'));
        $invocation->onNext('sysGetInvocationOutput', NotificationResult::void());
        $invocation->onNext('sysAttachInvocation', NotificationResult::success('"finished"'));
        $ctx = Contexts::service($invocation);

        $handle = $ctx->send(Target::service('Greeter', 'greet'), 'Mary');

        self::assertSame('finished', $handle->output());
        self::assertEquals([[AttachTarget::invocationId('inv-sent')]], $invocation->calls('sysAttachInvocation'));
        self::assertSame([], $invocation->calls('sysGetInvocationOutput'));
    }

    public function testPeekOutputReturnsNullWhileTheInvocationRuns(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysGetInvocationOutput', NotificationResult::void());
        $ctx = Contexts::service($invocation);

        self::assertNull($ctx->invocation('inv-1')->peekOutput());
        self::assertEquals([[AttachTarget::invocationId('inv-1')]], $invocation->calls('sysGetInvocationOutput'));
        self::assertSame([], $invocation->calls('sysAttachInvocation'));
    }

    public function testPeekOutputReturnsTheOutputOfAFinishedInvocation(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysGetInvocationOutput', NotificationResult::success('{"status":"ok"}'));
        $ctx = Contexts::service($invocation);

        self::assertSame(['status' => 'ok'], $ctx->invocation('inv-1')->peekOutput());
    }

    public function testPeekOutputThrowsTheTerminalErrorOfAFailedInvocation(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysGetInvocationOutput', NotificationResult::failure(500, 'failed', ['key1' => 'value1']));
        $ctx = Contexts::service($invocation);

        try {
            $ctx->invocation('inv-1')->peekOutput();
            self::fail('Expected terminal failure.');
        } catch (TerminalError $exception) {
            self::assertSame('failed', $exception->getMessage());
            self::assertSame(500, $exception->statusCode());
            self::assertSame(['key1' => 'value1'], $exception->metadata());
        }
    }

    public function testPendingHandlePeekOutputUsesResolvedInvocationId(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(66, NotificationResult::invocationId('inv-66'));
        $invocation->onNext('sysGetInvocationOutput', NotificationResult::success('"pending output"'));
        $handle = InvocationHandleImpl::pending($invocation, 66);

        self::assertSame('pending output', $handle->peekOutput());
        self::assertSame([66, 1], $invocation->awaited());
        self::assertEquals([[AttachTarget::invocationId('inv-66')]], $invocation->calls('sysGetInvocationOutput'));
    }

    public function testAttachedHandlePeeksByItsTarget(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysGetInvocationOutput', NotificationResult::void());
        $ctx = Contexts::service($invocation);

        self::assertNull($ctx->attach(AttachTarget::workflow('Checkout', 'order-1'))->peekOutput());
        self::assertEquals([[AttachTarget::workflow('Checkout', 'order-1')]], $invocation->calls('sysGetInvocationOutput'));
    }

    public function testPeekOutputSuspends(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysGetInvocationOutput', AwaitState::Suspended);
        $handle = new InvocationHandleImpl($invocation, AttachTarget::invocationId('inv-1'), 'inv-1');

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('Invocation output suspended.');

        $handle->peekOutput();
    }

    public function testPeekOutputIsCancelledWhenAwaitReceivesCancelSignal(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysGetInvocationOutput', AwaitState::CancelSignalReceived);
        $handle = new InvocationHandleImpl($invocation, AttachTarget::invocationId('inv-1'), 'inv-1');

        try {
            $handle->peekOutput();
            self::fail('Expected cancellation.');
        } catch (TerminalError $e) {
            self::assertSame('Invocation cancelled', $e->getMessage());
            self::assertSame(409, $e->statusCode());
        }

        self::assertSame([], $invocation->taken());
    }

    public function testPendingHandleCancelUsesResolvedInvocationId(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(66, NotificationResult::invocationId('inv-66'));
        $handle = InvocationHandleImpl::pending($invocation, 66);

        $handle->cancel();

        self::assertSame([['inv-66']], $invocation->calls('sysCancelInvocation'));
        self::assertSame([66], $invocation->awaited());
        self::assertSame([66], $invocation->taken());
    }

    public function testPendingHandleIdSuspends(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->interrupt(66, AwaitState::Suspended);
        $handle = InvocationHandleImpl::pending($invocation, 66);

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('Invocation ID suspended.');

        $handle->id();
    }

    public function testPendingHandleIdIsCancelledWhenAwaitReceivesCancelSignal(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->interrupt(66, AwaitState::CancelSignalReceived);
        $handle = InvocationHandleImpl::pending($invocation, 66);

        try {
            $handle->id();
            self::fail('Expected cancellation.');
        } catch (TerminalError $e) {
            self::assertSame('Invocation cancelled', $e->getMessage());
            self::assertSame(409, $e->statusCode());
        }

        self::assertSame([], $invocation->taken());
    }

    public function testOutputIsCancelledWhenAwaitReceivesCancelSignal(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysAttachInvocation', AwaitState::CancelSignalReceived);
        $handle = new InvocationHandleImpl($invocation, AttachTarget::invocationId('inv-1'), 'inv-1');

        try {
            $handle->output();
            self::fail('Expected cancellation.');
        } catch (TerminalError $e) {
            self::assertSame('Invocation cancelled', $e->getMessage());
            self::assertSame(409, $e->statusCode());
        }

        self::assertSame([], $invocation->taken());
    }

    public function testAttachedOutputIsCancelledWhenAwaitReceivesCancelSignal(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysAttachInvocation', AwaitState::CancelSignalReceived);
        $handle = new InvocationHandleImpl($invocation, AttachTarget::workflow('Checkout', 'order-1'));

        try {
            $handle->output();
            self::fail('Expected cancellation.');
        } catch (TerminalError $e) {
            self::assertSame('Invocation cancelled', $e->getMessage());
            self::assertSame(409, $e->statusCode());
        }

        self::assertEquals([[AttachTarget::workflow('Checkout', 'order-1')]], $invocation->calls('sysAttachInvocation'));
        self::assertSame([], $invocation->taken());
    }

    public function testOutputReturnsDecodedSuccess(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysAttachInvocation', NotificationResult::success('{"status":"ok"}'));
        $handle = new InvocationHandleImpl($invocation, AttachTarget::invocationId('inv-1'), 'inv-1');

        self::assertSame(['status' => 'ok'], $handle->output());
        self::assertEquals([[AttachTarget::invocationId('inv-1')]], $invocation->calls('sysAttachInvocation'));
    }

    public function testOutputReturnsNullForVoid(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysAttachInvocation', NotificationResult::void());
        $handle = new InvocationHandleImpl($invocation, AttachTarget::invocationId('inv-1'), 'inv-1');

        self::assertNull($handle->output());
    }

    public function testOutputThrowsTerminalErrorForFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysAttachInvocation', NotificationResult::failure(500, 'failed'));
        $handle = new InvocationHandleImpl($invocation, AttachTarget::invocationId('inv-1'), 'inv-1');

        $this->expectException(TerminalError::class);
        $this->expectExceptionMessage('failed');

        $handle->output();
    }

    public function testOutputThrowsTerminalErrorWithMetadataForFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysAttachInvocation', NotificationResult::failure(500, 'failed', ['key1' => 'value1']));
        $handle = new InvocationHandleImpl($invocation, AttachTarget::invocationId('inv-1'), 'inv-1');

        try {
            $handle->output();
            self::fail('Expected terminal failure.');
        } catch (TerminalError $exception) {
            self::assertSame('failed', $exception->getMessage());
            self::assertSame(500, $exception->statusCode());
            self::assertSame(['key1' => 'value1'], $exception->metadata());
        }
    }

    public function testOutputAcceptsNumericMetadataKeysFromDecodedFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        // json_decode(..., true) turns the wire key "404" into the int key 404.
        $invocation->onNext('sysAttachInvocation', NotificationResult::failure(404, 'missing', [404 => 'not found', 'reason' => 'gone']));
        $handle = new InvocationHandleImpl($invocation, AttachTarget::invocationId('inv-1'), 'inv-1');

        try {
            $handle->output();
            self::fail('Expected terminal failure.');
        } catch (TerminalError $exception) {
            self::assertSame(404, $exception->statusCode());
            self::assertSame([404 => 'not found', 'reason' => 'gone'], $exception->metadata());
        }
    }

    public function testOutputSuspends(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysAttachInvocation', AwaitState::Suspended);
        $handle = new InvocationHandleImpl($invocation, AttachTarget::invocationId('inv-1'), 'inv-1');

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('Invocation output suspended.');

        $handle->output();
    }

    public function testCancelDelegatesKnownInvocationId(): void
    {
        $invocation = ScriptedInvocation::new();
        $handle = new InvocationHandleImpl($invocation, AttachTarget::invocationId('inv-1'), 'inv-1');

        $handle->cancel();

        self::assertSame([['inv-1']], $invocation->calls('sysCancelInvocation'));
    }

    public function testAttachedHandleCannotCancelWithoutKnownInvocationId(): void
    {
        $invocation = ScriptedInvocation::new();
        $handle = new InvocationHandleImpl($invocation, AttachTarget::workflow('Checkout', 'order-1'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invocation cancellation requires a known invocation ID.');

        $handle->cancel();
    }
}
