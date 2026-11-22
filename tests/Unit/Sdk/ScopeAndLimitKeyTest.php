<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\AttachTarget;
use Restate\CallOptions;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\Extension\SurfaceValues;
use Restate\Internal\Backend\NotificationResult;
use Restate\SendOptions;
use Restate\Target;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

/**
 * Scope and limit key on calls, sends and attach targets: a scope is part of the target ({@see Target::scoped()},
 * `Context::scope()`), a limit key is an option of one call or send and needs a scope.
 */
final class ScopeAndLimitKeyTest extends TestCase
{
    public function testScopedTargetKeepsEverythingElse(): void
    {
        $target = Target::object('Counter', 'alice', 'add')->scoped('tenant-1');

        self::assertSame(['Counter', 'add', 'alice', 'tenant-1'], [$target->serviceName(), $target->handlerName(), $target->key(), $target->scope()]);
        self::assertSame(
            ['kind' => 'object', 'service' => 'Counter', 'handler' => 'add', 'key' => 'alice', 'scope' => 'tenant-1'],
            $target->toArray(),
        );
        self::assertNull(Target::service('Greeter', 'greet')->scope());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidScopes(): iterable
    {
        yield 'empty' => [''];
        yield 'slash' => ['tenant/1'];
        yield 'space' => ['tenant 1'];
        yield 'non-ASCII' => ["caf\u{e9}"];
        yield '37 characters' => [str_repeat('a', 37)];
        yield 'trailing newline' => ["tenant\n"];
    }

    #[DataProvider('invalidScopes')]
    public function testScopeMustBeOneTo36AllowedCharacters(string $scope): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a scope is 1 to 36 characters from [a-zA-Z0-9_.-].');

        Target::service('Greeter', 'greet')->scoped($scope);
    }

    public function testScopeAcceptsTheAllowedCharactersUpTo36(): void
    {
        $scope = str_repeat('aZ09_.-', 5) . 'x';

        self::assertSame($scope, Target::service('Greeter', 'greet')->scoped($scope)->scope());
    }

    public function testCallCarriesTheScopeOnTheTargetAndTheLimitKey(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);
        $target = Target::object('Counter', 'alice', 'add')->scoped('tenant-1');

        self::assertSame('ok', $ctx->call($target, 1, limitKey: 'user/42'));
        $ctx->callFuture($target, 2, 'once', limitKey: 'user')->awaitResult();

        self::assertEquals(
            [
                [new CallTarget($target, limitKey: 'user/42'), '1', null],
                [new CallTarget($target, limitKey: 'user'), '2', 'once'],
            ],
            $invocation->calls('sysCall'),
        );
    }

    public function testSendAndDelayedSendCarryTheScopeAndLimitKey(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);
        $target = Target::service('Greeter', 'greet')->scoped('tenant-1');

        $ctx->send($target, 'Mary', limitKey: 'user');
        $ctx->sendAfter($target, 'Bob', 500, limitKey: 'user/7');
        $ctx->send($target, 'Ann');

        self::assertEquals(
            [
                [new CallTarget($target, limitKey: 'user'), '"Mary"', null, null],
                [new CallTarget($target, limitKey: 'user/7'), '"Bob"', 500, null],
                [new CallTarget($target), '"Ann"', null, null],
            ],
            $invocation->calls('sysSend'),
        );
    }

    public function testScopedClientsTargetTheScope(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);
        $scope = $ctx->scope('tenant-1');

        self::assertSame('tenant-1', $scope->name());
        $scope->serviceClient('Greeter')->request('greet', 'Mary')->limitKey('user')->call();
        $scope->objectClient('Counter', 'alice')->request('add', 1)->send();
        $scope->workflowClient('Checkout', 'order-1')->request('run')->limitKey('shop/eu')->send(SendOptions::delayMillis(10));

        self::assertEquals(
            [[new CallTarget(Target::service('Greeter', 'greet')->scoped('tenant-1'), limitKey: 'user'), '"Mary"', null]],
            $invocation->calls('sysCall'),
        );
        self::assertEquals(
            [
                [new CallTarget(Target::object('Counter', 'alice', 'add')->scoped('tenant-1')), '1', null, null],
                [new CallTarget(Target::workflow('Checkout', 'order-1', 'run')->scoped('tenant-1'), limitKey: 'shop/eu'), 'null', 10, null],
            ],
            $invocation->calls('sysSend'),
        );
    }

    public function testScopeRejectsAnInvalidName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid scope "tenant 1"');

        Contexts::service(self::runtime())->scope('tenant 1');
    }

    public function testOptionsCarryTheLimitKeyAndOverrideTheRequests(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);
        $target = Target::service('Greeter', 'greet')->scoped('tenant-1');

        $ctx->genericCall($target, 'a', CallOptions::limitKey('user'));
        $ctx->genericCall($target, 'b', CallOptions::idempotencyKey('once')->withLimitKey('user/1'));
        $ctx->genericSend($target, 'c', SendOptions::limitKey('user'));
        $ctx->genericSend($target, 'd', SendOptions::delayMillis(5)->withLimitKey('user/2'));
        $ctx->scope('tenant-1')->serviceClient('Greeter')->request('greet', 'e')->limitKey('request')->call(CallOptions::limitKey('option'));

        self::assertSame(
            ['user', 'user/1', 'option'],
            array_map(static fn (array $call): ?string => $call[0]->limitKey, $invocation->calls('sysCall')),
        );
        self::assertSame(
            ['user', 'user/2'],
            array_map(static fn (array $call): ?string => $call[0]->limitKey, $invocation->calls('sysSend')),
        );
        self::assertSame(5, $invocation->calls('sysSend')[1][2]);
    }

    public function testALimitKeyNeedsAScopedTarget(): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        foreach ([
            static fn () => $ctx->call(Target::service('Greeter', 'greet'), 'Mary', limitKey: 'user'),
            static fn () => $ctx->send(Target::service('Greeter', 'greet'), 'Mary', limitKey: 'user'),
            static fn () => $ctx->sendAfter(Target::service('Greeter', 'greet'), 'Mary', 5, limitKey: 'user'),
            static fn () => $ctx->serviceClient('Greeter')->request('greet', 'Mary')->limitKey('user')->call(),
        ] as $operation) {
            try {
                $operation();
                self::fail('A limit key without a scope was accepted.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('The limit key "user" needs a scoped target', $e->getMessage());
            }
        }
        self::assertSame([], $invocation->calls('sysCall'));
        self::assertSame([], $invocation->calls('sysSend'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLimitKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'three levels' => ['a/b/c'];
        yield 'empty level' => ['a/'];
        yield 'leading slash' => ['/a'];
        yield 'invalid character' => ['user!'];
        yield '37 characters' => [str_repeat('a', 37)];
    }

    #[DataProvider('invalidLimitKeys')]
    public function testLimitKeyIsOneOrTwoLevelsOfAllowedCharacters(string $limitKey): void
    {
        $invocation = self::runtime();
        $ctx = Contexts::service($invocation);

        foreach ([
            static fn () => $ctx->call(Target::service('Greeter', 'greet')->scoped('tenant-1'), 'Mary', limitKey: $limitKey),
            static fn () => CallOptions::limitKey($limitKey),
            static fn () => SendOptions::none()->withLimitKey($limitKey),
            static fn () => $ctx->scope('tenant-1')->serviceClient('Greeter')->request('greet')->limitKey($limitKey),
        ] as $operation) {
            try {
                $operation();
                self::fail("The limit key \"{$limitKey}\" was accepted.");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('a limit key is one or two levels separated by "/"', $e->getMessage());
            }
        }
        self::assertSame([], $invocation->calls('sysCall'));
    }

    public function testAttachTargetsCarryTheScope(): void
    {
        self::assertSame(
            ['kind' => 'workflow', 'name' => 'Checkout', 'key' => 'order-1', 'scope' => 'tenant-1'],
            AttachTarget::workflow('Checkout', 'order-1', 'tenant-1')->toArray(),
        );
        self::assertSame(
            ['kind' => 'idempotency', 'service_name' => 'Counter', 'service_key' => 'alice', 'handler_name' => 'add', 'idempotency_key' => 'once', 'scope' => 'tenant-1'],
            AttachTarget::idempotency(Target::object('Counter', 'alice', 'add')->scoped('tenant-1'), 'once')->toArray(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid scope ""');
        AttachTarget::workflow('Checkout', 'order-1', '');
    }

    public function testScopedAttachGoesToTheBackend(): void
    {
        $invocation = ScriptedInvocation::new()->onNext('sysAttachInvocation', NotificationResult::success('"done"'));
        $ctx = Contexts::service($invocation);

        self::assertSame('done', $ctx->attach(AttachTarget::workflow('Checkout', 'order-1', 'tenant-1'))->output());
        self::assertEquals([[AttachTarget::workflow('Checkout', 'order-1', 'tenant-1')]], $invocation->calls('sysAttachInvocation'));
    }

    public function testSurfaceValuesCarryScopeAndLimitKeyOnlyWhenSet(): void
    {
        self::assertSame(
            ['kind' => 'object', 'service' => 'Counter', 'handler' => 'add', 'key' => 'alice', 'scope' => 'tenant-1', 'limit_key' => 'user/42'],
            SurfaceValues::callTarget(new CallTarget(Target::object('Counter', 'alice', 'add')->scoped('tenant-1'), limitKey: 'user/42')),
        );
        self::assertSame(
            ['kind' => 'service', 'service' => 'Greeter', 'handler' => 'greet', 'key' => null, 'scope' => 'tenant-1'],
            SurfaceValues::callTarget(new CallTarget(Target::service('Greeter', 'greet')->scoped('tenant-1'))),
        );
        self::assertSame(
            ['kind' => 'service', 'service' => 'Greeter', 'handler' => 'greet', 'key' => null],
            SurfaceValues::callTarget(new CallTarget(Target::service('Greeter', 'greet'))),
        );
    }

    private static function runtime(): ScriptedInvocation
    {
        return ScriptedInvocation::new()
            ->onEvery('sysCall', NotificationResult::success('"ok"'))
            ->onEvery('sysSend', NotificationResult::invocationId('inv-from-send'));
    }
}
