<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\TestCase;
use Restate\CallOptions;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\SuspensionException;
use Restate\SendOptions;
use Restate\Target;
use Restate\TerminalError;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class ContextCommunicationTest extends TestCase
{
    public function testCallDelegatesToBackendCallAndReturnsDecodedResult(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysCall', NotificationResult::success('{"message":"Hello Mary"}'));
        $ctx = Contexts::service($invocation);

        $result = $ctx->call(Target::service('Greeter', 'greet'), ['name' => 'Mary']);

        self::assertSame(['message' => 'Hello Mary'], $result);
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), '{"name":"Mary"}', null]], $invocation->calls('sysCall'));
        $call = $invocation->handles('sysCall')[0];
        self::assertSame([$call], $invocation->awaited());
        self::assertSame([$call], $invocation->taken());
    }

    public function testCallDelegatesIdempotencyKeyToBackendCall(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysCall', NotificationResult::success('{"message":"Hello Mary"}'));
        $ctx = Contexts::service($invocation);

        $result = $ctx->call(Target::service('Greeter', 'greet'), ['name' => 'Mary'], 'idem-1');

        self::assertSame(['message' => 'Hello Mary'], $result);
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), '{"name":"Mary"}', 'idem-1']], $invocation->calls('sysCall'));
    }

    public function testCallForwardsCustomHeadersInTargetJson(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysCall', NotificationResult::success('{"message":"Hello Mary"}'));
        $ctx = Contexts::service($invocation);

        $ctx->call(Target::service('Greeter', 'greet'), ['name' => 'Mary'], headers: ['x-trace-id' => 'trace-1']);

        self::assertEquals(new CallTarget(Target::service('Greeter', 'greet'), [['x-trace-id', 'trace-1']]), $invocation->calls('sysCall')[0][0]);
    }

    public function testGenericCallDelegatesCallOptionsIdempotencyKey(): void
    {
        $invocation = ScriptedInvocation::new()->onNext('sysCall', NotificationResult::success('"Hello Mary"'));
        $ctx = Contexts::service($invocation);

        $result = $ctx->genericCall(Target::service('Greeter', 'greet'), 'Mary', CallOptions::idempotencyKey('idem-call'));

        self::assertSame('Hello Mary', $result);
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), '"Mary"', 'idem-call']], $invocation->calls('sysCall'));
    }

    public function testGenericCallDelegatesCallOptionsHeaders(): void
    {
        $invocation = ScriptedInvocation::new()->onNext('sysCall', NotificationResult::success('"Hello Mary"'));
        $ctx = Contexts::service($invocation);

        $ctx->genericCall(
            Target::service('Greeter', 'greet'),
            'Mary',
            CallOptions::headers(['x-client' => 'generic'])->withHeader('x-extra', '1'),
        );

        self::assertSame([['x-client', 'generic'], ['x-extra', '1']], $invocation->calls('sysCall')[0][0]->headers);
    }

    public function testCallRejectsEmptyIdempotencyKey(): void
    {
        $ctx = Contexts::service(ScriptedInvocation::new());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Idempotency key must not be empty.');

        $ctx->call(Target::service('Greeter', 'greet'), ['name' => 'Mary'], '');
    }

    public function testCallFutureRejectsEmptyIdempotencyKey(): void
    {
        $ctx = Contexts::service(ScriptedInvocation::new());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Idempotency key must not be empty.');

        $ctx->callFuture(Target::service('Greeter', 'greet'), ['name' => 'Mary'], '');
    }

    public function testCallReturnsNullForVoidResult(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysCall', NotificationResult::void());
        $ctx = Contexts::service($invocation);

        self::assertNull($ctx->call(Target::service('Greeter', 'noop'), null));
    }

    public function testCallThrowsTerminalErrorForRemoteTerminalFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysCall', NotificationResult::failure(409, 'conflict'));
        $ctx = Contexts::service($invocation);

        $this->expectException(TerminalError::class);
        $this->expectExceptionMessage('conflict');

        $ctx->call(Target::service('Greeter', 'fail'), null);
    }

    public function testCallPreservesTerminalFailureWithUnsupportedResultStatePrefix(): void
    {
        $this->assertTerminalCallFailurePreserved('Unsupported Service call result state: remote');
    }

    public function testCallPreservesTerminalFailureWithUnsupportedAwaitStatePrefix(): void
    {
        $this->assertTerminalCallFailurePreserved('Unsupported durable future await state: remote');
    }

    public function testCallReportsMalformedSuccessJsonAsCallFailureNotJsonException(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysCall', NotificationResult::success('{bad json'));
        $ctx = Contexts::service($invocation);

        try {
            $ctx->call(Target::service('Greeter', 'badJson'), null);
            self::fail('Expected the call to fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('Service call returned invalid success JSON.', $e->getMessage());
            self::assertInstanceOf(JsonException::class, $e->getPrevious());
            self::assertSame(self::jsonError('{bad json'), $e->getPrevious()->getMessage());
        }
    }

    public function testCallRejectsUnsupportedResultStateWithOriginalMessage(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysCall', NotificationResult::invocationId('inv-44'));
        $ctx = Contexts::service($invocation);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported service call result state: invocation_id');

        $ctx->call(Target::service('Greeter', 'unexpected'), null);
    }

    public function testCallSuspendsForSuspendedAwaitState(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysCall', AwaitState::Suspended);
        $ctx = Contexts::service($invocation);

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('Service call suspended.');

        $ctx->call(Target::service('Greeter', 'greet'), 'Mary');
    }

    public function testCallRejectsUnexpectedAwaitState(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysCall', AwaitState::ExecuteRun);
        $ctx = Contexts::service($invocation);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported service call await state: execute_run');

        $ctx->call(Target::service('Greeter', 'greet'), 'Mary');
    }

    public function testSelectThrowsTerminalErrorForCancelSignal(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->whenPending = AwaitResult::cancelSignalReceived();
        $ctx = Contexts::service($invocation);
        $future = $ctx->callFuture(Target::service('Greeter', 'greet'), 'Mary');

        try {
            $ctx->select([$future]);
            self::fail('Expected cancelled select to throw.');
        } catch (TerminalError $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertSame('Invocation cancelled', $exception->getMessage());
        }
    }

    public function testSendDelegatesToBackendSendWithoutDelay(): void
    {
        $invocation = ScriptedInvocation::new()->onNext('sysSend', NotificationResult::invocationId('inv-from-send'));
        $ctx = Contexts::service($invocation);

        $handle = $ctx->send(Target::object('Counter', 'alice', 'add'), 3);

        self::assertSame('inv-from-send', $handle->id());
        self::assertEquals([[new CallTarget(Target::object('Counter', 'alice', 'add')), '3', null, null]], $invocation->calls('sysSend'));
    }

    public function testSendForwardsCustomHeadersInTargetJson(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        $ctx->send(Target::object('Counter', 'alice', 'add'), 3, headers: [['x-tenant', 'acme']]);

        self::assertEquals(new CallTarget(Target::object('Counter', 'alice', 'add'), [['x-tenant', 'acme']]), $invocation->calls('sysSend')[0][0]);
    }

    public function testSendDelegatesIdempotencyKeyToBackendSend(): void
    {
        $invocation = ScriptedInvocation::new()->onNext('sysSend', NotificationResult::invocationId('inv-from-send'));
        $ctx = Contexts::service($invocation);

        $handle = $ctx->send(Target::object('Counter', 'alice', 'add'), 3, 'idem-2');

        self::assertSame('inv-from-send', $handle->id());
        self::assertEquals([[new CallTarget(Target::object('Counter', 'alice', 'add')), '3', null, 'idem-2']], $invocation->calls('sysSend'));
    }

    public function testSendAfterDelegatesToBackendSendWithDelayMilliseconds(): void
    {
        $invocation = ScriptedInvocation::new()->onNext('sysSend', NotificationResult::invocationId('inv-from-send'));
        $ctx = Contexts::service($invocation);

        $handle = $ctx->sendAfter(Target::service('Greeter', 'greet'), 'Mary', 500);

        self::assertSame('inv-from-send', $handle->id());
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), '"Mary"', 500, null]], $invocation->calls('sysSend'));
    }

    public function testSendAfterDelegatesIdempotencyKeyToBackendSend(): void
    {
        $invocation = ScriptedInvocation::new()->onNext('sysSend', NotificationResult::invocationId('inv-from-send'));
        $ctx = Contexts::service($invocation);

        $handle = $ctx->sendAfter(Target::service('Greeter', 'greet'), 'Mary', 500, 'idem-3');

        self::assertSame('inv-from-send', $handle->id());
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), '"Mary"', 500, 'idem-3']], $invocation->calls('sysSend'));
    }

    public function testGenericSendDelegatesSendOptionsDelay(): void
    {
        $invocation = ScriptedInvocation::new()->onNext('sysSend', NotificationResult::invocationId('inv-from-send'));
        $ctx = Contexts::service($invocation);

        $handle = $ctx->genericSend(Target::service('Greeter', 'greet'), 'Mary', SendOptions::delayMillis(250));

        self::assertSame('inv-from-send', $handle->id());
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), '"Mary"', 250, null]], $invocation->calls('sysSend'));
    }

    public function testGenericSendDelegatesSendOptionsHeaders(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        $ctx->genericSend(
            Target::service('Greeter', 'greet'),
            'Mary',
            SendOptions::delayMillis(250)->withHeaders([['x-client', 'generic-send']]),
        );

        self::assertSame([['x-client', 'generic-send']], $invocation->calls('sysSend')[0][0]->headers);
    }

    public function testSendAfterRejectsNegativeDelay(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Send delay must be greater than or equal to 0 milliseconds.');

        $ctx->sendAfter(Target::service('Greeter', 'greet'), 'Mary', -1);
    }

    public function testSendRejectsEmptyIdempotencyKey(): void
    {
        $ctx = Contexts::service(ScriptedInvocation::new());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Idempotency key must not be empty.');

        $ctx->send(Target::service('Greeter', 'greet'), 'Mary', '');
    }

    public function testSendAfterRejectsEmptyIdempotencyKey(): void
    {
        $ctx = Contexts::service(ScriptedInvocation::new());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Idempotency key must not be empty.');

        $ctx->sendAfter(Target::service('Greeter', 'greet'), 'Mary', 1, '');
    }

    private function assertTerminalCallFailurePreserved(string $message): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysCall', NotificationResult::failure(409, $message));
        $ctx = Contexts::service($invocation);

        try {
            $ctx->call(Target::service('Greeter', 'fail'), null);
            self::fail('Expected terminal exception.');
        } catch (TerminalError $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame(409, $e->statusCode());
            self::assertSame(409, $e->getCode());
        } catch (\RuntimeException $e) {
            self::fail('Expected terminal exception, got ' . $e::class . ': ' . $e->getMessage());
        }
    }

    /** PHP's message for decoding $json: 8.6 adds where the error is ("Syntax error near location 1:2"). */
    private static function jsonError(string $json): string
    {
        json_decode($json);

        return json_last_error_msg();
    }
}
