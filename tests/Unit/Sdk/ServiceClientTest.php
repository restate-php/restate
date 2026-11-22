<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\NotificationResult;
use Restate\Target;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class ServiceClientTest extends TestCase
{
    public function testServiceClientRequestCallsServiceTarget(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        $result = $ctx->serviceClient('Greeter')->request('greet', 'Mary')->call();

        self::assertSame('Hello Mary', $result);
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), '"Mary"', null]], $invocation->calls('sysCall'));
    }

    public function testServiceClientRequestDefaultsPayloadToNull(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        $ctx->serviceClient('Greeter')->request('noop')->send();

        self::assertEquals([[new CallTarget(Target::service('Greeter', 'noop')), 'null', null, null]], $invocation->calls('sysSend'));
    }

    public function testServiceClientUsesTargetValidation(): void
    {
        $ctx = Contexts::service(self::runtime());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Restate handler name bad-name.');

        $ctx->serviceClient('Greeter')->request('bad-name');
    }

    /** Calls return $callResult, sends the invocation id inv-from-send. */
    private static function runtime(string $callResult = '"Hello Mary"'): ScriptedInvocation
    {
        return ScriptedInvocation::new()
            ->onEvery('sysCall', NotificationResult::success($callResult))
            ->onEvery('sysSend', NotificationResult::invocationId('inv-from-send'));
    }
}
