<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use Restate\Internal\Context\AwakeableImpl;
use PHPUnit\Framework\TestCase;
use Restate\Awakeable;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\SuspensionException;
use Restate\TerminalError;
use Restate\Tests\Support\ScriptedInvocation;

final class AwakeableTest extends TestCase
{
    public function testIdReturnsAwakeableId(): void
    {
        $awakeable = new AwakeableImpl(ScriptedInvocation::new(), 'awk-1', 41);

        self::assertSame('awk-1', $awakeable->id());
    }

    public function testValueReturnsDecodedSuccess(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(41, NotificationResult::success('{"status":"ok"}'));
        $awakeable = new AwakeableImpl($invocation, 'awk-1', 41);

        self::assertSame(['status' => 'ok'], $awakeable->value());
        self::assertSame([41], $invocation->awaited());
        self::assertSame([41], $invocation->taken());
    }

    public function testValueReturnsNullForVoid(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(41, NotificationResult::void());
        $awakeable = new AwakeableImpl($invocation, 'awk-1', 41);

        self::assertNull($awakeable->value());
    }

    public function testValueThrowsTerminalErrorForFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(41, NotificationResult::failure(409, 'denied'));
        $awakeable = new AwakeableImpl($invocation, 'awk-1', 41);

        $this->expectException(TerminalError::class);
        $this->expectExceptionMessage('denied');
        $this->expectExceptionCode(409);

        $awakeable->value();
    }

    public function testValueThrowsTerminalErrorWithMetadataForFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(41, NotificationResult::failure(409, 'denied', ['key1' => 'value1']));
        $awakeable = new AwakeableImpl($invocation, 'awk-1', 41);

        try {
            $awakeable->value();
            self::fail('Expected terminal failure.');
        } catch (TerminalError $exception) {
            self::assertSame('denied', $exception->getMessage());
            self::assertSame(409, $exception->statusCode());
            self::assertSame(['key1' => 'value1'], $exception->metadata());
        }
    }

    public function testValueAcceptsNumericMetadataKeysFromDecodedFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        // json_decode(..., true) turns the wire key "404" into the int key 404.
        $invocation->complete(41, NotificationResult::failure(404, 'missing', [404 => 'not found', 'reason' => 'gone']));
        $awakeable = new AwakeableImpl($invocation, 'awk-1', 41);

        try {
            $awakeable->value();
            self::fail('Expected terminal failure.');
        } catch (TerminalError $exception) {
            self::assertSame(404, $exception->statusCode());
            self::assertSame([404 => 'not found', 'reason' => 'gone'], $exception->metadata());
        }
    }

    public function testValueSuspendsForSuspendedAwaitState(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->interrupt(41, AwaitState::Suspended);
        $awakeable = new AwakeableImpl($invocation, 'awk-1', 41);

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('Awakeable suspended.');

        $awakeable->value();
    }

    public function testValueThrowsTerminalCancellationForCancelSignal(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->interrupt(41, AwaitState::CancelSignalReceived);
        $awakeable = new AwakeableImpl($invocation, 'awk-1', 41);

        $this->expectException(TerminalError::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('Invocation cancelled');

        $awakeable->value();
    }

    public function testValueThrowsForUnsupportedAwaitState(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->interrupt(41, AwaitState::ExecuteRun);
        $awakeable = new AwakeableImpl($invocation, 'awk-1', 41);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported awakeable await state: execute_run');

        $awakeable->value();
    }

    public function testValueThrowsRuntimeExceptionForMalformedJsonSuccess(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(41, NotificationResult::success('{bad json'));
        $awakeable = new AwakeableImpl($invocation, 'awk-1', 41);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Awakeable returned invalid success JSON.');

        $awakeable->value();
    }

    public function testValueThrowsForUnsupportedResultState(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(41, NotificationResult::stateKeys([]));
        $awakeable = new AwakeableImpl($invocation, 'awk-1', 41);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported awakeable result state: state_keys');

        $awakeable->value();
    }
}
