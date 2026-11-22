<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use Restate\Internal\Context\DurableFutureImpl;
use PHPUnit\Framework\TestCase;
use Restate\DurableFuture;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\SuspensionException;
use Restate\TerminalError;
use Restate\Tests\Support\ScriptedInvocation;

final class DurableFutureTest extends TestCase
{
    public function testHandleReturnsBackendHandle(): void
    {
        $future = DurableFutureImpl::jsonResult(ScriptedInvocation::new(), 41, 'Promise get', 'Promise get suspended.');

        self::assertSame(41, $future->handle);
    }

    public function testJsonResultDecodesSuccessAndCachesResolvedValue(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(41, NotificationResult::success('{"status":"ok"}'));
        $future = DurableFutureImpl::jsonResult($invocation, 41, 'Promise get', 'Promise get suspended.');

        self::assertSame(['status' => 'ok'], $future->awaitResult());
        self::assertSame(['status' => 'ok'], $future->awaitResult());
        self::assertSame([41], $invocation->awaited());
        self::assertSame([41], $invocation->taken());
    }

    public function testJsonResultReturnsNullForVoid(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(41, NotificationResult::void());
        $future = DurableFutureImpl::jsonResult($invocation, 41, 'Promise get', 'Promise get suspended.');

        self::assertNull($future->awaitResult());
    }

    public function testJsonResultThrowsTerminalErrorForFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(41, NotificationResult::failure(409, 'denied'));
        $future = DurableFutureImpl::jsonResult($invocation, 41, 'Promise get', 'Promise get suspended.');

        $this->expectException(TerminalError::class);
        $this->expectExceptionMessage('denied');
        $this->expectExceptionCode(409);

        $future->awaitResult();
    }

    public function testJsonResultThrowsTerminalErrorWithMetadataForFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(41, NotificationResult::failure(409, 'denied', ['key1' => 'value1']));
        $future = DurableFutureImpl::jsonResult($invocation, 41, 'Promise get', 'Promise get suspended.');

        try {
            $future->awaitResult();
            self::fail('Expected terminal failure.');
        } catch (TerminalError $exception) {
            self::assertSame('denied', $exception->getMessage());
            self::assertSame(409, $exception->statusCode());
            self::assertSame(['key1' => 'value1'], $exception->metadata());
        }
    }

    public function testJsonResultAcceptsNumericMetadataKeysFromDecodedFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        // json_decode(..., true) turns the wire key "404" into the int key 404.
        $invocation->complete(41, NotificationResult::failure(404, 'missing', [404 => 'not found', 'reason' => 'gone']));
        $future = DurableFutureImpl::jsonResult($invocation, 41, 'Promise get', 'Promise get suspended.');

        try {
            $future->awaitResult();
            self::fail('Expected terminal failure.');
        } catch (TerminalError $exception) {
            self::assertSame(404, $exception->statusCode());
            self::assertSame([404 => 'not found', 'reason' => 'gone'], $exception->metadata());
        }
    }

    public function testJsonResultCachesTerminalFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(41, NotificationResult::failure(409, 'conflict'));
        $future = DurableFutureImpl::jsonResult($invocation, 41, 'Service call', 'Service call suspended.');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $future->awaitResult();
                self::fail('Expected terminal failure.');
            } catch (TerminalError $exception) {
                self::assertSame('conflict', $exception->getMessage());
                self::assertSame(409, $exception->statusCode());
            }
        }

        self::assertSame([41], $invocation->awaited());
        self::assertSame([41], $invocation->taken());
    }

    public function testJsonResultCachesInvalidSuccessJsonFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(41, NotificationResult::success('{bad json'));
        $future = DurableFutureImpl::jsonResult($invocation, 41, 'Service call', 'Service call suspended.');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $future->awaitResult();
                self::fail('Expected invalid JSON failure.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Service call returned invalid success JSON.', $exception->getMessage());
            }
        }

        self::assertSame([41], $invocation->awaited());
        self::assertSame([41], $invocation->taken());
    }

    public function testAwaitResultSuspendedStateThrowsProvidedMessage(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->interrupt(41, AwaitState::Suspended);
        $future = DurableFutureImpl::jsonResult($invocation, 41, 'Promise get', 'custom suspension message');

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('custom suspension message');

        $future->awaitResult();
    }

    public function testAwaitResultCancelSignalThrowsTerminalCancellation(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->interrupt(41, AwaitState::CancelSignalReceived);
        $future = DurableFutureImpl::jsonResult($invocation, 41, 'Service call', 'Service call suspended.');

        $this->expectException(TerminalError::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('Invocation cancelled');

        $future->awaitResult();
    }

    public function testVoidResultAcceptsOnlyVoid(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(41, NotificationResult::void());
        $future = DurableFutureImpl::voidResult($invocation, 41, 'Timer', 'Timer suspended.');

        self::assertNull($future->awaitResult());

        $invocation = ScriptedInvocation::new();
        $invocation->complete(42, NotificationResult::success('null'));
        $future = DurableFutureImpl::voidResult($invocation, 42, 'Timer', 'Timer suspended.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported Timer result state: success');

        $future->awaitResult();
    }

    public function testInvocationIdResultReturnsInvocationId(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(41, NotificationResult::invocationId('inv-41'));
        $future = DurableFutureImpl::invocationId($invocation, 41, 'Invocation ID suspended.');

        self::assertSame('inv-41', $future->awaitResult());
    }
}
