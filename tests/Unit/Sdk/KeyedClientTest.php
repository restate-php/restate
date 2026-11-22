<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\AttachTarget;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\NotificationResult;
use Restate\SendOptions;
use Restate\Target;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class KeyedClientTest extends TestCase
{
    public function testObjectClientRequestSendsObjectTarget(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        $handle = $ctx->objectClient('Counter', 'alice')->request('add', 3)->send();

        self::assertSame('inv-from-send', $handle->id());
        self::assertEquals([[new CallTarget(Target::object('Counter', 'alice', 'add')), '3', null, null]], $invocation->calls('sysSend'));
    }

    public function testWorkflowClientRequestSchedulesWorkflowTarget(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        $handle = $ctx->workflowClient('Checkout', 'order-1')->request('run', 'ping')->sendAfter(500);

        self::assertSame('inv-from-send', $handle->id());
        self::assertEquals([[new CallTarget(Target::workflow('Checkout', 'order-1', 'run')), '"ping"', 500, null]], $invocation->calls('sysSend'));
    }

    public function testWorkflowClientSubmitsTheRunAndAttachesToItInItsScope(): void
    {
        $invocation = ScriptedInvocation::new()
            ->onEvery('sysSend', NotificationResult::invocationId('inv_run'))
            ->onNext('sysAttachInvocation', NotificationResult::success('"done"'));
        $ctx = Contexts::service($invocation);

        $ctx->workflowClient('Checkout', 'order-1')->submit(['sku' => 'book'], SendOptions::none()->withIdempotencyKey('run-1'));
        $output = $ctx->scope('tenant-1')->workflowClient('Checkout', 'order-1')->attach()->output();

        self::assertSame('done', $output);
        self::assertEquals([[new CallTarget(Target::workflow('Checkout', 'order-1', 'run')), '{"sku":"book"}', null, 'run-1']], $invocation->calls('sysSend'));
        self::assertEquals([[AttachTarget::workflow('Checkout', 'order-1', 'tenant-1')]], $invocation->calls('sysAttachInvocation'));
    }

    public function testObjectClientUsesTargetKeyValidation(): void
    {
        $ctx = Contexts::service(self::runtime());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Target key must not be empty.');

        $ctx->objectClient('Counter', '')->request('add', 3);
    }

    public function testWorkflowClientUsesTargetKeyValidation(): void
    {
        $ctx = Contexts::service(self::runtime());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Target key must not be empty.');

        $ctx->workflowClient('Checkout', '')->request('run', 'ping');
    }

    /** Calls return $callResult, sends the invocation id inv-from-send. */
    private static function runtime(string $callResult = '"Hello Mary"'): ScriptedInvocation
    {
        return ScriptedInvocation::new()
            ->onEvery('sysCall', NotificationResult::success($callResult))
            ->onEvery('sysSend', NotificationResult::invocationId('inv-from-send'));
    }
}
