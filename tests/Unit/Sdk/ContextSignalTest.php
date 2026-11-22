<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\AttachTarget;
use Restate\Internal\AwaitCombinator;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\SuspensionException;
use Restate\JournalValueCodec;
use Restate\Serde;
use Restate\Target;
use Restate\TerminalError;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class ContextSignalTest extends TestCase
{
    public function testSignalIsAFutureOfTheNextDeliveryOfTheNamedSignal(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysSignal', NotificationResult::success('{"approved":true}'));
        $ctx = Contexts::service($invocation);

        $future = $ctx->signal('approval');

        self::assertSame([['approval']], $invocation->calls('sysSignal'));
        self::assertSame(['approved' => true], $future->awaitResult());
        self::assertSame([1], $invocation->awaited());
        self::assertSame([1], $invocation->taken());
    }

    public function testARejectedSignalThrowsItsTerminalError(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysSignal', NotificationResult::failure(409, 'denied', ['by' => 'admin']));
        $future = Contexts::service($invocation)->signal('approval');

        try {
            $future->awaitResult();
            self::fail('Expected a TerminalError.');
        } catch (TerminalError $e) {
            self::assertSame('denied', $e->getMessage());
            self::assertSame(409, $e->statusCode());
            self::assertSame(['by' => 'admin'], $e->metadata());
        }
    }

    public function testASignalNotDeliveredYetSuspends(): void
    {
        $future = Contexts::service(ScriptedInvocation::new())->signal('approval');

        $this->expectException(SuspensionException::class);

        $future->awaitResult();
    }

    public function testSignalDecodesWithItsSerdeAndTheJournalValueCodec(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysSignal', NotificationResult::success(strrev('YES')));
        $ctx = Contexts::service($invocation, journalValueCodec: new SignalTestReversingCodec());

        self::assertSame('yes', $ctx->signal('approval', new SignalTestUpperSerde())->awaitResult());
    }

    public function testSignalsCombineWithOtherFuturesOfTheContext(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysSignal', NotificationResult::success('"a"'));
        $invocation->onNext('sysSignal', NotificationResult::success('"b"'));
        $ctx = Contexts::service($invocation);
        $first = $ctx->signal('first');
        $second = $ctx->signal('second');

        self::assertSame(['first' => 'a', 'second' => 'b'], $ctx->all(['first' => $first, 'second' => $second]));
        self::assertEquals([AwaitCombinator::AllSucceededOrFirstFailed->tree([1, 2])], $invocation->awaitedTrees());

        // A signal never delivered loses a race against a timer that fires.
        $neverDelivered = $ctx->signal('third');
        $timeout = $ctx->sleepFuture(1_000);
        self::assertSame('timeout', $ctx->select(['signal' => $neverDelivered, 'timeout' => $timeout]));
        self::assertFalse($neverDelivered->isCompleted());
    }

    public function testAnEmptySignalNameIsRejectedBeforeReachingTheBackend(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        foreach ([
            static fn () => $ctx->signal(''),
            static fn () => $ctx->invocation('inv-1')->signal(''),
        ] as $call) {
            try {
                $call();
                self::fail('Expected an InvalidArgumentException.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Signal name must not be empty.', $e->getMessage());
            }
        }
        self::assertSame([], $invocation->operations());
    }

    public function testResolvingASignalOfAnotherInvocationJournalsItsValue(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        $ctx->invocation('inv-1')->signal('approval')->resolve(['approved' => true]);
        $ctx->invocation('inv-2')->signal('approval')->resolve();

        self::assertSame(
            [['inv-1', 'approval', '{"approved":true}'], ['inv-2', 'approval', 'null']],
            $invocation->calls('sysResolveSignal'),
        );
    }

    public function testRejectingASignalOfAnotherInvocationJournalsTheTerminalError(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        $ctx->invocation('inv-1')->signal('approval')->reject(new TerminalError('denied', 409, ['by' => 'admin']));

        self::assertSame([['inv-1', 'approval', 409, 'denied', ['by' => 'admin']]], $invocation->calls('sysRejectSignal'));
    }

    public function testResolveEncodesWithItsSerdeOrTheContextDefaultNotTheInvocationOutputSerde(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation, journalValueCodec: new SignalTestReversingCodec());

        $signal = $ctx->invocation('inv-1', new SignalTestUpperSerde())->signal('approval');
        $signal->resolve('yes');
        $signal->resolve('yes', new SignalTestUpperSerde());

        self::assertSame(
            [['inv-1', 'approval', strrev('"yes"')], ['inv-1', 'approval', strrev('YES')]],
            $invocation->calls('sysResolveSignal'),
        );
    }

    public function testSignallingASentInvocationWaitsForItsId(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysSend', NotificationResult::invocationId('inv-7'));
        $ctx = Contexts::service($invocation);

        $ctx->send(Target::service('Approvals', 'wait'), null)->signal('approval')->resolve(true);

        self::assertSame([['inv-7', 'approval', 'true']], $invocation->calls('sysResolveSignal'));
    }

    public function testAHandleWithoutAKnownInvocationIdCannotBeSignalled(): void
    {
        $invocation = ScriptedInvocation::new();
        $handle = Contexts::service($invocation)->attach(AttachTarget::workflow('Checkout', 'order-1'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Signalling an invocation requires a known invocation ID.');

        $handle->signal('approval')->resolve(true);
    }
}

final class SignalTestReversingCodec implements JournalValueCodec
{
    public function encode(string $bytes): string
    {
        return strrev($bytes);
    }

    public function decode(string $bytes): string
    {
        return strrev($bytes);
    }
}

final class SignalTestUpperSerde implements Serde
{
    public function serialize(mixed $value): string
    {
        return strtoupper(is_string($value) ? $value : '');
    }

    public function deserialize(string $bytes): mixed
    {
        return strtolower($bytes);
    }

    public function contentType(): string
    {
        return 'text/plain';
    }
}
