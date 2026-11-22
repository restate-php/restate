<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\CallOptions;
use Restate\ClientRequest;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\NotificationResult;
use Restate\SendOptions;
use Restate\Target;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class ClientRequestTest extends TestCase
{
    public function testCallDelegatesToContextCall(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        $result = new ClientRequest($ctx, Target::service('Greeter', 'greet'), 'Mary')->call();

        self::assertSame('Hello Mary', $result);
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), '"Mary"', null]], $invocation->calls('sysCall'));
        self::assertSame([1], $invocation->awaited());
        self::assertSame([1], $invocation->taken());
    }

    public function testIdempotencyKeyIsImmutableAndDelegatesToContextCall(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);
        $request = new ClientRequest($ctx, Target::service('Greeter', 'greet'), 'Mary');

        $withKey = $request->idempotencyKey('idem-client');

        self::assertNotSame($request, $withKey);
        self::assertSame('Hello Mary', $withKey->call());
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), '"Mary"', 'idem-client']], $invocation->calls('sysCall'));
    }

    public function testHeaderIsImmutableAndDelegatesToContextCall(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);
        $request = new ClientRequest($ctx, Target::service('Greeter', 'greet'), 'Mary');

        $withHeader = $request->header('x-request-id', 'request-1');

        self::assertNotSame($request, $withHeader);
        $withHeader->call();
        self::assertSame(
            [['x-request-id', 'request-1']],
            $invocation->calls('sysCall')[0][0]->headers,
        );
    }

    public function testCallOptionsDelegateIdempotencyKeyToContextCall(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        $result = new ClientRequest($ctx, Target::service('Greeter', 'greet'), 'Mary')
            ->call(CallOptions::idempotencyKey('idem-request'));

        self::assertSame('Hello Mary', $result);
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), '"Mary"', 'idem-request']], $invocation->calls('sysCall'));
    }

    public function testCallOptionsAppendHeadersToRequestHeaders(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        new ClientRequest($ctx, Target::service('Greeter', 'greet'), 'Mary')
            ->headers(['x-request' => 'request'])
            ->call(CallOptions::headers([['x-option', 'option']]));

        self::assertSame(
            [['x-request', 'request'], ['x-option', 'option']],
            $invocation->calls('sysCall')[0][0]->headers,
        );
    }

    public function testCallFutureDelegatesToContextCallFuture(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        $future = new ClientRequest($ctx, Target::service('Greeter', 'greet'), 'Mary')->callFuture();

        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), '"Mary"', null]], $invocation->calls('sysCall'));
        self::assertSame([], $invocation->awaited());
    }

    public function testSendDelegatesToContextSend(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        $handle = new ClientRequest($ctx, Target::object('Counter', 'alice', 'add'), 3)->send();

        self::assertSame('inv-from-send', $handle->id());
        self::assertEquals([[new CallTarget(Target::object('Counter', 'alice', 'add')), '3', null, null]], $invocation->calls('sysSend'));
    }

    public function testIdempotencyKeyDelegatesToContextSend(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        $handle = new ClientRequest($ctx, Target::object('Counter', 'alice', 'add'), 3)
            ->idempotencyKey('idem-send')
            ->send();

        self::assertSame('inv-from-send', $handle->id());
        self::assertEquals([[new CallTarget(Target::object('Counter', 'alice', 'add')), '3', null, 'idem-send']], $invocation->calls('sysSend'));
    }

    public function testSendOptionsDelegateDelayAndIdempotencyKeyToContextSend(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        $handle = new ClientRequest($ctx, Target::service('Greeter', 'greet'), 'Mary')
            ->send(SendOptions::delayMillis(500)->withIdempotencyKey('idem-send'));

        self::assertSame('inv-from-send', $handle->id());
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), '"Mary"', 500, 'idem-send']], $invocation->calls('sysSend'));
    }

    public function testSendAfterDelegatesToContextSendAfter(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        $handle = new ClientRequest($ctx, Target::workflow('Checkout', 'order-1', 'run'), 'ping')->sendAfter(500);

        self::assertSame('inv-from-send', $handle->id());
        self::assertEquals([[new CallTarget(Target::workflow('Checkout', 'order-1', 'run')), '"ping"', 500, null]], $invocation->calls('sysSend'));
    }

    public function testSendAfterAcceptsSendOptionsForHeadersAndSerdes(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        new ClientRequest($ctx, Target::workflow('Checkout', 'order-1', 'run'), 'ping')
            ->sendAfter(500, SendOptions::header('x-schedule', 'yes'));

        self::assertSame(
            [['x-schedule', 'yes']],
            $invocation->calls('sysSend')[0][0]->headers,
        );
        self::assertSame(500, $invocation->calls('sysSend')[0][2]);
    }

    public function testSendAfterPreservesContextDelayValidation(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Send delay must be greater than or equal to 0 milliseconds.');

        new ClientRequest($ctx, Target::service('Greeter', 'greet'), 'Mary')->sendAfter(-1);
    }

    public function testIdempotencyKeyRejectsEmptyValue(): void
    {
        $ctx = Contexts::service(self::runtime());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Idempotency key must not be empty.');

        new ClientRequest($ctx, Target::service('Greeter', 'greet'), 'Mary')->idempotencyKey('');
    }

    public function testHeadersRejectInvalidName(): void
    {
        $ctx = Contexts::service(self::runtime());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Request header name must not be empty.');

        new ClientRequest($ctx, Target::service('Greeter', 'greet'), 'Mary')->headers(['' => 'value']);
    }

    public function testCallOptionsRejectInvalidHeaderValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid request header value for x-bad.');

        CallOptions::header('x-bad', "bad\nvalue");
    }

    /** Calls return $callResult, sends the invocation id inv-from-send. */
    private static function runtime(string $callResult = '"Hello Mary"'): ScriptedInvocation
    {
        return ScriptedInvocation::new()
            ->onEvery('sysCall', NotificationResult::success($callResult))
            ->onEvery('sysSend', NotificationResult::invocationId('inv-from-send'));
    }
}
