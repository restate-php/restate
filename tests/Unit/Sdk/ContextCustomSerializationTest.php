<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\AttachTarget;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\NotificationResult;
use Restate\JournalValueCodec;
use Restate\RunOptions;
use Restate\RunRetryPolicy;
use Restate\Serde;
use Restate\Target;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class ContextCustomSerializationTest extends TestCase
{
    public function testStateGetAndSetUseExplicitSerde(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::object($invocation, 'counter-1');

        $ctx->set('profile', new ContextCustomSerializationValue('stored'), ContextCustomSerializationSerde::class);
        $value = $ctx->get('profile', ContextCustomSerializationSerde::class);

        self::assertSame([['profile', 'custom:stored']], $invocation->calls('setState'));
        self::assertSame([['profile']], $invocation->calls('sysGetState'));
        self::assertInstanceOf(ContextCustomSerializationValue::class, $value);
        self::assertSame('stored', $value->value);
    }

    public function testDefaultSerdeAppliesToStateCallSendRunAwakeableAndPromiseValues(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation
            ->onNext('sysCall', NotificationResult::success('custom:reply'))
            ->onNext('sysSend', NotificationResult::invocationId('inv-send-1'))
            ->onNext('sysAttachInvocation', NotificationResult::success('custom:sent-output'))
            ->onNext('sysAwakeable', NotificationResult::success('custom:awoken'));
        $invocation->promises['approval'] = NotificationResult::success('custom:future');
        $ctx = Contexts::workflow($invocation, 'workflow-1', defaultSerde: ContextCustomSerializationSerde::class);

        $ctx->set('profile', new ContextCustomSerializationValue('stored'));
        $stateValue = $ctx->get('profile');
        $callValue = $ctx->call(Target::service('Greeter', 'greet'), new ContextCustomSerializationValue('request'));
        $sendValue = $ctx->send(Target::service('Greeter', 'greet'), new ContextCustomSerializationValue('send-payload'))->output();
        $callbackValue = new ContextCustomSerializationValue('from-callback');
        $runValue = $ctx->run('load-profile', static fn (): ContextCustomSerializationValue => $callbackValue);
        $awakeableValue = $ctx->awakeable()->value();
        $ctx->resolveAwakeable('awk-2', new ContextCustomSerializationValue('resolved'));
        $promise = $ctx->promise('approval');
        $promiseValue = $promise->value();
        $promiseFutureValue = $promise->future()->awaitResult();
        $promise->resolve(new ContextCustomSerializationValue('promise-resolved'));

        self::assertSame([['profile', 'custom:stored']], $invocation->calls('setState'));
        self::assertInstanceOf(ContextCustomSerializationValue::class, $stateValue);
        self::assertSame('stored', $stateValue->value);
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), 'custom:request', null]], $invocation->calls('sysCall'));
        self::assertInstanceOf(ContextCustomSerializationValue::class, $callValue);
        self::assertSame('reply', $callValue->value);
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), 'custom:send-payload', null, null]], $invocation->calls('sysSend'));
        self::assertInstanceOf(ContextCustomSerializationValue::class, $sendValue);
        self::assertSame('sent-output', $sendValue->value);
        self::assertEquals([[$invocation->handles('sysRun')[0], 'custom:from-callback', RunRetryPolicy::infinite()]], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertInstanceOf(ContextCustomSerializationValue::class, $runValue);
        self::assertSame('from-callback', $runValue->value);
        self::assertNotSame($callbackValue, $runValue, 'decoded from the recorded result');
        self::assertInstanceOf(ContextCustomSerializationValue::class, $awakeableValue);
        self::assertSame('awoken', $awakeableValue->value);
        self::assertSame([['awk-2', 'custom:resolved']], $invocation->calls('sysResolveAwakeable'));
        self::assertInstanceOf(ContextCustomSerializationValue::class, $promiseValue);
        self::assertSame('future', $promiseValue->value);
        self::assertInstanceOf(ContextCustomSerializationValue::class, $promiseFutureValue);
        self::assertSame('future', $promiseFutureValue->value);
        self::assertSame([['approval', 'custom:promise-resolved']], $invocation->calls('sysResolvePromise'));
    }

    public function testJournalValueCodecWrapsContextValueBytes(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation
            ->onNext('sysCall', NotificationResult::success('codec:custom:reply'))
            ->onNext('sysSend', NotificationResult::invocationId('inv-send-1'))
            ->onNext('sysAttachInvocation', NotificationResult::success('codec:custom:sent-output'))
            ->onNext('sysAwakeable', NotificationResult::success('codec:custom:awoken'));
        $invocation->promises['approval'] = NotificationResult::success('codec:custom:future');
        $ctx = Contexts::workflow(
            $invocation,
            'workflow-1',
            defaultSerde: ContextCustomSerializationSerde::class,
            journalValueCodec: new ContextCustomSerializationPrefixCodec('codec'),
        );

        $ctx->set('profile', new ContextCustomSerializationValue('stored'));
        $stateValue = $ctx->get('profile');
        $callValue = $ctx->call(Target::service('Greeter', 'greet'), new ContextCustomSerializationValue('request'));
        $sendValue = $ctx->send(Target::service('Greeter', 'greet'), new ContextCustomSerializationValue('send-payload'))->output();
        $callbackValue = new ContextCustomSerializationValue('from-callback');
        $runValue = $ctx->run('load-profile', static fn (): ContextCustomSerializationValue => $callbackValue);
        $awakeableValue = $ctx->awakeable()->value();
        $ctx->resolveAwakeable('awk-2', new ContextCustomSerializationValue('resolved'));
        $promise = $ctx->promise('approval');
        $promiseValue = $promise->value();
        $promiseFutureValue = $promise->future()->awaitResult();
        $promise->resolve(new ContextCustomSerializationValue('promise-resolved'));

        self::assertSame([['profile', 'codec:custom:stored']], $invocation->calls('setState'));
        self::assertInstanceOf(ContextCustomSerializationValue::class, $stateValue);
        self::assertSame('stored', $stateValue->value);
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), 'codec:custom:request', null]], $invocation->calls('sysCall'));
        self::assertInstanceOf(ContextCustomSerializationValue::class, $callValue);
        self::assertSame('reply', $callValue->value);
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), 'codec:custom:send-payload', null, null]], $invocation->calls('sysSend'));
        self::assertInstanceOf(ContextCustomSerializationValue::class, $sendValue);
        self::assertSame('sent-output', $sendValue->value);
        self::assertEquals([[$invocation->handles('sysRun')[0], 'codec:custom:from-callback', RunRetryPolicy::infinite()]], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertInstanceOf(ContextCustomSerializationValue::class, $runValue);
        self::assertSame('from-callback', $runValue->value);
        self::assertNotSame($callbackValue, $runValue, 'decoded from the recorded result');
        self::assertInstanceOf(ContextCustomSerializationValue::class, $awakeableValue);
        self::assertSame('awoken', $awakeableValue->value);
        self::assertSame([['awk-2', 'codec:custom:resolved']], $invocation->calls('sysResolveAwakeable'));
        self::assertInstanceOf(ContextCustomSerializationValue::class, $promiseValue);
        self::assertSame('future', $promiseValue->value);
        self::assertInstanceOf(ContextCustomSerializationValue::class, $promiseFutureValue);
        self::assertSame('future', $promiseFutureValue->value);
        self::assertSame([['approval', 'codec:custom:promise-resolved']], $invocation->calls('sysResolvePromise'));
    }

    public function testStateSerdeFailureDoesNotWriteState(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::object($invocation, 'counter-1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('custom serialize failed');

        try {
            $ctx->set('profile', new ContextCustomSerializationValue('stored'), ContextCustomSerializationFailingSerializeSerde::class);
        } finally {
            self::assertSame([], $invocation->calls('setState'));
        }
    }

    public function testCallUsesExplicitInputAndOutputSerdes(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysCall', NotificationResult::success('custom:reply'));
        $ctx = Contexts::service($invocation);

        $result = $ctx->call(
            Target::service('Greeter', 'greet'),
            new ContextCustomSerializationValue('request'),
            inputSerde: ContextCustomSerializationSerde::class,
            outputSerde: ContextCustomSerializationSerde::class,
        );

        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), 'custom:request', null]], $invocation->calls('sysCall'));
        self::assertInstanceOf(ContextCustomSerializationValue::class, $result);
        self::assertSame('reply', $result->value);
    }

    public function testCallOutputSerdeFailurePropagates(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysCall', NotificationResult::success('custom:reply'));
        $ctx = Contexts::service($invocation);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('custom deserialize failed');

        $ctx->call(
            Target::service('Greeter', 'greet'),
            new ContextCustomSerializationValue('request'),
            inputSerde: ContextCustomSerializationSerde::class,
            outputSerde: ContextCustomSerializationFailingDeserializeSerde::class,
        );
    }

    public function testSendUsesInputSerdeAndInvocationOutputSerde(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation
            ->onNext('sysSend', NotificationResult::invocationId('inv-send-1'))
            ->onNext('sysAttachInvocation', NotificationResult::success('custom:sent-output'));
        $ctx = Contexts::service($invocation);

        $handle = $ctx->send(
            Target::service('Greeter', 'greet'),
            new ContextCustomSerializationValue('send-payload'),
            inputSerde: ContextCustomSerializationSerde::class,
            outputSerde: ContextCustomSerializationSerde::class,
        );

        $output = $handle->output();

        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), 'custom:send-payload', null, null]], $invocation->calls('sysSend'));
        self::assertEquals([[AttachTarget::invocationId('inv-send-1')]], $invocation->calls('sysAttachInvocation'));
        self::assertInstanceOf(ContextCustomSerializationValue::class, $output);
        self::assertSame('sent-output', $output->value);
    }

    public function testRunUsesSerdeForCompletionAndStoredResult(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);
        $callbackValue = new ContextCustomSerializationValue('from-callback');

        $result = $ctx->run(
            'load-profile',
            static fn (): ContextCustomSerializationValue => $callbackValue,
            RunOptions::none()->withSerde(ContextCustomSerializationSerde::class),
        );

        self::assertSame([['load-profile']], $invocation->calls('sysRun'));
        self::assertEquals([[$invocation->handles('sysRun')[0], 'custom:from-callback', RunRetryPolicy::infinite()]], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertInstanceOf(ContextCustomSerializationValue::class, $result);
        self::assertSame('from-callback', $result->value);
        self::assertNotSame($callbackValue, $result, 'decoded from the recorded result');
    }

    public function testRunReplayUsesSerdeWithoutExecutingCallback(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysRun', NotificationResult::success('custom:replayed'));
        $ctx = Contexts::service($invocation);
        $callbackExecuted = false;

        $result = $ctx->run(
            'load-profile',
            function () use (&$callbackExecuted): ContextCustomSerializationValue {
                $callbackExecuted = true;

                return new ContextCustomSerializationValue('from-callback');
            },
            serde: ContextCustomSerializationSerde::class,
        );

        self::assertFalse($callbackExecuted);
        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertInstanceOf(ContextCustomSerializationValue::class, $result);
        self::assertSame('replayed', $result->value);
    }

    public function testRunSerdeFailureDoesNotProposeCompletion(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('custom serialize failed');

        try {
            $ctx->run(
                'load-profile',
                static fn (): ContextCustomSerializationValue => new ContextCustomSerializationValue('from-callback'),
                serde: ContextCustomSerializationFailingSerializeSerde::class,
            );
        } finally {
            self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        }
    }

    public function testAwakeableUsesExplicitSerdeForValueAndResolution(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysAwakeable', NotificationResult::success('custom:awoken'));
        $ctx = Contexts::service($invocation);

        $value = $ctx->awakeable(ContextCustomSerializationSerde::class)->value();
        $ctx->resolveAwakeable('awk-2', new ContextCustomSerializationValue('resolved'), ContextCustomSerializationSerde::class);

        self::assertInstanceOf(ContextCustomSerializationValue::class, $value);
        self::assertSame('awoken', $value->value);
        self::assertSame([['awk-2', 'custom:resolved']], $invocation->calls('sysResolveAwakeable'));
    }

    public function testAwakeableSerdeFailurePropagates(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysAwakeable', NotificationResult::success('custom:awoken'));
        $ctx = Contexts::service($invocation);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('custom deserialize failed');

        $ctx->awakeable(ContextCustomSerializationFailingDeserializeSerde::class)->value();
    }

    public function testDurablePromiseUsesExplicitSerde(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->promises['approval'] = NotificationResult::success('custom:future');
        $invocation->onNext('sysPeekPromise', NotificationResult::success('custom:peeked'));
        $ctx = Contexts::workflow($invocation, 'workflow-1');
        $promise = $ctx->promise('approval', ContextCustomSerializationSerde::class);

        $value = $promise->value();
        $peeked = $promise->peek();
        $future = $promise->future()->awaitResult();
        $promise->resolve(new ContextCustomSerializationValue('resolved'));

        self::assertInstanceOf(ContextCustomSerializationValue::class, $value);
        self::assertSame('future', $value->value);
        self::assertInstanceOf(ContextCustomSerializationValue::class, $peeked);
        self::assertSame('peeked', $peeked->value);
        self::assertInstanceOf(ContextCustomSerializationValue::class, $future);
        self::assertSame('future', $future->value);
        self::assertSame([['approval', 'custom:resolved']], $invocation->calls('sysResolvePromise'));
    }
}

final class ContextCustomSerializationValue
{
    public function __construct(public readonly string $value)
    {
    }
}

final class ContextCustomSerializationSerde implements Serde
{
    #[\Override]
    public function serialize(mixed $value): string
    {
        if (!$value instanceof ContextCustomSerializationValue) {
            throw new \InvalidArgumentException('Expected custom serialization value.');
        }

        return 'custom:' . $value->value;
    }

    #[\Override]
    public function deserialize(string $bytes): mixed
    {
        if (!str_starts_with($bytes, 'custom:')) {
            throw new \InvalidArgumentException('Expected custom serialization bytes.');
        }

        return new ContextCustomSerializationValue(substr($bytes, strlen('custom:')));
    }

    #[\Override]
    public function contentType(): string
    {
        return 'application/x-context-custom';
    }
}

final class ContextCustomSerializationFailingSerializeSerde implements Serde
{
    #[\Override]
    public function serialize(mixed $value): string
    {
        throw new \RuntimeException('custom serialize failed');
    }

    #[\Override]
    public function deserialize(string $bytes): mixed
    {
        return new ContextCustomSerializationValue('unused');
    }

    #[\Override]
    public function contentType(): string
    {
        return 'application/x-context-custom';
    }
}

final class ContextCustomSerializationFailingDeserializeSerde implements Serde
{
    #[\Override]
    public function serialize(mixed $value): string
    {
        return 'custom:unused';
    }

    #[\Override]
    public function deserialize(string $bytes): mixed
    {
        throw new \RuntimeException('custom deserialize failed');
    }

    #[\Override]
    public function contentType(): string
    {
        return 'application/x-context-custom';
    }
}

final readonly class ContextCustomSerializationPrefixCodec implements JournalValueCodec
{
    public function __construct(private string $prefix)
    {
    }

    #[\Override]
    public function encode(string $bytes): string
    {
        return $this->prefix . ':' . $bytes;
    }

    #[\Override]
    public function decode(string $bytes): string
    {
        $prefix = $this->prefix . ':';
        if (!str_starts_with($bytes, $prefix)) {
            throw new \InvalidArgumentException("Expected {$this->prefix} encoded bytes.");
        }

        return substr($bytes, strlen($prefix));
    }
}
