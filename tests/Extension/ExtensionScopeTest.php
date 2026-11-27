<?php

declare(strict_types=1);

namespace Restate\Tests\Extension;

use Restate\AttachTarget;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\BackendErrorCategory;
use Restate\Internal\BackendException;
use Restate\Target;
use Restate\Tests\Support\Protocol\FrameCodec;

/**
 * Scope and limit key through the extension against a real VM: the commands protocol v7 (Restate 1.8) journals, byte
 * for byte.
 */
final class ExtensionScopeTest extends ExtensionTestCase
{
    public function testCallJournalsTheScopeAndLimitKey(): void
    {
        $invocation = self::running(open: true, contentType: self::V7);

        $invocation->sysCall(new CallTarget(self::counter(), limitKey: 'eu/user-42'), '1', 'once');

        self::assertSame(
            bin2hex(FrameCodec::callCommandFrame('Counter', 'add', '1', 'alice', 'once', 1, 2, scope: 'tenant-1', limitKey: 'eu/user-42')),
            bin2hex(self::written($invocation)),
        );
    }

    public function testSendJournalsTheScopeAndLimitKey(): void
    {
        $invocation = self::running(open: true, contentType: self::V7);

        $invocation->sysSend(new CallTarget(self::counter(), limitKey: 'user-42'), '1', null);
        $invocation->sysSend(new CallTarget(self::counter()), '2', null);

        self::assertSame(
            bin2hex(
                FrameCodec::sendCommandFrame('Counter', 'add', '1', null, 'alice', null, 1, scope: 'tenant-1', limitKey: 'user-42')
                . FrameCodec::sendCommandFrame('Counter', 'add', '2', null, 'alice', null, 2, scope: 'tenant-1'),
            ),
            bin2hex(self::written($invocation)),
        );
    }

    public function testAttachAndGetOutputJournalTheScopeOfTheirTarget(): void
    {
        $invocation = self::running(open: true, contentType: self::V7);

        $invocation->sysAttachInvocation(AttachTarget::workflow('Checkout', 'order-1', 'tenant-1'));
        $invocation->sysGetInvocationOutput(AttachTarget::idempotency(Target::service('Greeter', 'greet')->scoped('tenant-1'), 'once'));

        self::assertSame(
            bin2hex(
                FrameCodec::attachWorkflowCommandFrame('Checkout', 'order-1', 1, scope: 'tenant-1')
                . FrameCodec::getIdempotentRequestOutputCommandFrame('Greeter', null, 'greet', 'once', 2, scope: 'tenant-1'),
            ),
            bin2hex(self::written($invocation)),
        );
    }

    public function testUnscopedTargetsJournalNoScope(): void
    {
        $invocation = self::running(open: true, contentType: self::V7);

        $invocation->sysCall(new CallTarget(Target::object('Counter', 'alice', 'add')), '1');

        self::assertSame(
            bin2hex(FrameCodec::callCommandFrame('Counter', 'add', '1', 'alice', null, 1, 2)),
            bin2hex(self::written($invocation)),
        );
    }

    public function testTheExtensionRefusesALimitKeyWithoutScope(): void
    {
        $invocation = self::running(open: true, contentType: self::V7);

        $exception = self::backendException(static fn () => $invocation->sysCall(new CallTarget(Target::service('Greeter', 'greet'), limitKey: 'user-42'), '1'));

        self::assertSame(BackendErrorCategory::InvalidArgument, $exception->category());
        self::assertStringContainsString('A limit key needs a scope.', $exception->getMessage());
        self::assertSame('', self::written($invocation), 'nothing was journaled');
    }

    private static function counter(): Target
    {
        return Target::object('Counter', 'alice', 'add')->scoped('tenant-1');
    }

    private static function backendException(\Closure $call): BackendException
    {
        try {
            $call();
        } catch (BackendException $e) {
            return $e;
        }

        self::fail('Expected a BackendException.');
    }
}
