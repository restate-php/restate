<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\SuspensionException;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class ContextTimerTest extends TestCase
{
    public function testSleepDelegatesToBackendSleepAndConsumesVoidNotification(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysSleep', NotificationResult::void());
        $ctx = Contexts::service($invocation);

        $ctx->sleep(250);

        self::assertSame([[250]], $invocation->calls('sysSleep'));
        self::assertSame(['sysSleep', 'doAwait', 'takeNotificationResult'], $invocation->operations());
        self::assertSame([1], $invocation->awaited());
        self::assertSame([1], $invocation->taken());
    }

    public function testSleepRejectsNegativeDelayBeforeBackendDelegation(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sleep delay must be greater than or equal to 0 milliseconds.');

        try {
            $ctx->sleep(-1);
        } finally {
            self::assertSame([], $invocation->operations());
        }
    }

    public function testSleepSuspendsWhenAwaitSuspends(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysSleep', AwaitState::Suspended);
        $ctx = Contexts::service($invocation);

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('Sleep suspended.');

        $ctx->sleep(500);
    }

    public function testSleepRejectsUnexpectedAwaitState(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysSleep', AwaitState::ExecuteRun);
        $ctx = Contexts::service($invocation);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported sleep await state: execute_run');

        $ctx->sleep(500);
    }

    public function testSleepRejectsNonVoidNotification(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysSleep', NotificationResult::success('null'));
        $ctx = Contexts::service($invocation);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported sleep result state: success');

        $ctx->sleep(500);
    }
}
