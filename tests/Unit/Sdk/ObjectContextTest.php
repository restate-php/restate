<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\SuspensionException;
use Restate\ObjectSharedContext;
use Restate\TerminalError;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class ObjectContextTest extends TestCase
{
    public function testSharedContextReturnsObjectKey(): void
    {
        $ctx = Contexts::objectShared(ScriptedInvocation::new(), 'counter-1');

        self::assertSame('counter-1', $ctx->key());
    }

    public function testObjectContextsDefaultRandomSeedToZero(): void
    {
        self::assertSame(0, Contexts::objectShared(ScriptedInvocation::new(), 'counter-1')->randomSeed());
        self::assertSame(0, Contexts::object(ScriptedInvocation::new(), 'counter-1')->randomSeed());
    }

    public function testGetReturnsDecodedStateValue(): void
    {
        $vm = ScriptedInvocation::new();
        $vm->state['value'] = '{"count":5,"tags":["hot"]}';
        $ctx = Contexts::object($vm, 'counter-1');

        self::assertSame(['count' => 5, 'tags' => ['hot']], $ctx->get('value'));
        self::assertSame([['value']], $vm->calls('sysGetState'));
    }

    public function testGetReturnsNullForMissingState(): void
    {
        $vm = ScriptedInvocation::new();
        $ctx = Contexts::object($vm, 'counter-1');

        self::assertNull($ctx->get('value'));
        self::assertSame([['value']], $vm->calls('sysGetState'));
    }

    public function testSetJsonEncodesValueAndDelegatesToBackendStateSet(): void
    {
        $vm = ScriptedInvocation::new();
        $ctx = Contexts::object($vm, 'counter-1');

        $ctx->set('value', 5);

        self::assertSame([['value', '5']], $vm->calls('setState'));
    }

    public function testClearDelegatesToBackendStateClear(): void
    {
        $vm = ScriptedInvocation::new();
        $ctx = Contexts::object($vm, 'counter-1');

        $ctx->clear('value');

        self::assertSame([['value']], $vm->calls('clearState'));
    }

    public function testSharedContextKeysDelegatesToBackendStateKeys(): void
    {
        $vm = ScriptedInvocation::new();
        $vm->state = ['value' => '5', 'other' => '6'];
        $ctx = Contexts::objectShared($vm, 'counter-1');

        self::assertSame(['value', 'other'], $ctx->stateKeys());
        self::assertCount(1, $vm->calls('sysGetStateKeys'));
    }

    public function testClearAllDelegatesToBackendStateClearAll(): void
    {
        $vm = ScriptedInvocation::new();
        $ctx = Contexts::object($vm, 'counter-1');

        $ctx->clearAll();

        self::assertCount(1, $vm->calls('clearAllState'));
    }

    public function testGetIsCancelledWhenItsAwaitReceivesTheCancelSignal(): void
    {
        $vm = ScriptedInvocation::new();
        $vm->onNext('sysGetState', AwaitState::CancelSignalReceived);
        $ctx = Contexts::object($vm, 'counter-1');

        $this->expectExceptionObject(new TerminalError('Invocation cancelled', 409));

        $ctx->get('value');
    }

    public function testKeysAreCancelledWhenTheirAwaitReceivesTheCancelSignal(): void
    {
        $vm = ScriptedInvocation::new();
        $vm->onNext('sysGetStateKeys', AwaitState::CancelSignalReceived);
        $ctx = Contexts::objectShared($vm, 'counter-1');

        $this->expectExceptionObject(new TerminalError('Invocation cancelled', 409));

        $ctx->stateKeys();
    }

    public function testGetSuspendsWhenItsAwaitSuspends(): void
    {
        $vm = ScriptedInvocation::new();
        $vm->onNext('sysGetState', AwaitState::Suspended);
        $ctx = Contexts::object($vm, 'counter-1');

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('State get suspended.');

        $ctx->get('value');
    }

    public function testKeysSuspendWhenTheirAwaitSuspends(): void
    {
        $vm = ScriptedInvocation::new();
        $vm->onNext('sysGetStateKeys', AwaitState::Suspended);
        $ctx = Contexts::objectShared($vm, 'counter-1');

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('State keys suspended.');

        $ctx->stateKeys();
    }
    public function testSharedContextDoesNotExposeStateMutationMethods(): void
    {
        $publicMethods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            new ReflectionClass(ObjectSharedContext::class)->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        self::assertNotContains('set', $publicMethods);
        self::assertNotContains('clear', $publicMethods);
        self::assertNotContains('clearAll', $publicMethods);
    }
}
