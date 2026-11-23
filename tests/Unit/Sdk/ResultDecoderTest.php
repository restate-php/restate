<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\ResultDecoder;
use Restate\Internal\SuspensionException;
use Restate\JournalValueCodec;
use Restate\JsonSerde;
use Restate\Reflection\HandlerSerdes;
use Restate\Serde;
use Restate\TerminalError;

final class ResultDecoderTest extends TestCase
{
    public function testACompletedAwaitReturns(): void
    {
        ResultDecoder::assertCompleted(AwaitResult::completed(), 'sleep', 'Sleep suspended.');

        $this->addToAssertionCount(1);
    }

    public function testASuspendedAwaitThrowsTheSuspension(): void
    {
        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('Sleep suspended.');

        ResultDecoder::assertCompleted(AwaitResult::suspended(), 'sleep', 'Sleep suspended.');
    }

    public function testACancelSignalIsATerminal409(): void
    {
        try {
            ResultDecoder::void('sleep')->assertAwaited(AwaitResult::cancelSignalReceived(), 'Sleep suspended.');
        } catch (TerminalError $e) {
            self::assertSame(['Invocation cancelled', 409], [$e->getMessage(), $e->statusCode()]);

            return;
        }

        self::fail('Expected a TerminalError.');
    }

    /** @return iterable<string, array{AwaitResult}> */
    public static function unfinishedStates(): iterable
    {
        yield 'read from input' => [AwaitResult::of(AwaitState::ReadFromInput)];
        yield 'waiting pending run' => [AwaitResult::of(AwaitState::WaitingPendingRun)];
        yield 'execute run' => [AwaitResult::executeRun(3)];
    }

    #[DataProvider('unfinishedStates')]
    public function testAnyOtherAwaitStateNamesTheOperation(AwaitResult $result): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unsupported service call await state: {$result->state->value}");

        ResultDecoder::assertCompleted($result, 'service call', 'Service call suspended.');
    }

    /** @return iterable<string, array{ResultDecoder<mixed>}> */
    public static function decoders(): iterable
    {
        yield 'json' => [ResultDecoder::json('service call')];
        yield 'serde' => [ResultDecoder::serde('service call', new JsonSerde())];
        yield 'void' => [ResultDecoder::void('sleep')];
        yield 'state keys' => [ResultDecoder::stateKeys()];
        yield 'invocation id' => [ResultDecoder::invocationId()];
    }

    /** @param ResultDecoder<mixed> $decoder */
    #[DataProvider('decoders')]
    public function testEveryOperationThrowsAFailureAsItsTerminalError(ResultDecoder $decoder): void
    {
        try {
            $decoder->decode(NotificationResult::failure(404, 'gone', ['404' => 'missing']));
        } catch (TerminalError $e) {
            self::assertSame([404, 'gone', [404 => 'missing']], [$e->statusCode(), $e->getMessage(), $e->metadata()]);

            return;
        }

        self::fail('Expected a TerminalError.');
    }

    public function testJsonDecodesThroughTheCodecAndReportsMalformedJsonAsTheOperationsFailure(): void
    {
        self::assertSame(['a' => 1], ResultDecoder::json('service call', new ReversingCodec())->decode(NotificationResult::success('}1:"a"{')));
        self::assertNull(ResultDecoder::json('service call')->decode(NotificationResult::void()));

        try {
            ResultDecoder::json('service call')->decode(NotificationResult::success('{bad'));
        } catch (\RuntimeException $e) {
            self::assertSame('Service call returned invalid success JSON.', $e->getMessage());
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());

            return;
        }

        self::fail('Expected a RuntimeException.');
    }

    public function testSerdeLetsTheSerializersExceptionsThrough(): void
    {
        self::assertSame('olleh', ResultDecoder::serde('state get', new UpperSerde(), new ReversingCodec())->decode(NotificationResult::success('HELLO')));

        $this->expectException(\JsonException::class);
        ResultDecoder::serde('state get', new JsonSerde())->decode(NotificationResult::success('{bad'));
    }

    /**
     * A handler without output writes no bytes, so awaiting a call to it (or its invocation's output) completes with an
     * empty success. No JSON value encodes to no bytes: for JSON it is null, without the codec, which might not accept
     * empty bytes.
     *
     * @return iterable<string, array{ResultDecoder<mixed>}>
     */
    public static function jsonDecoders(): iterable
    {
        yield 'json' => [ResultDecoder::json('service call', new RejectingEmptyCodec())];
        yield 'serde with the JSON serializer' => [ResultDecoder::serde('invocation output', new JsonSerde(), new RejectingEmptyCodec())];
        yield 'value with the JSON serializer' => [ResultDecoder::value('awakeable', new JsonSerde(), new RejectingEmptyCodec())];
        yield 'serde with a handler-mapped JSON serializer' => [ResultDecoder::serde('service call', HandlerSerdes::of('Probe', 'nothing', static function (): void {
        })->output, new RejectingEmptyCodec())];
    }

    /** @param ResultDecoder<mixed> $decoder */
    #[DataProvider('jsonDecoders')]
    public function testAnEmptySuccessIsNullForJson(ResultDecoder $decoder): void
    {
        self::assertNull($decoder->decode(NotificationResult::success('')));
    }

    /** Any other serializer decides what empty bytes are: they can be a value of its own format. */
    public function testAnEmptySuccessGoesToAnyOtherSerializer(): void
    {
        self::assertSame('', ResultDecoder::serde('state get', new UpperSerde())->decode(NotificationResult::success('')));
        self::assertSame('', ResultDecoder::value('service call', new UpperSerde())->decode(NotificationResult::success('')));
    }

    public function testValuePicksJsonForTheJsonSerializer(): void
    {
        $this->expectExceptionMessage('Awakeable returned invalid success JSON.');

        ResultDecoder::value('awakeable', new JsonSerde())->decode(NotificationResult::success('{bad'));
    }

    public function testStateKeysAndInvocationIds(): void
    {
        self::assertSame(['a'], ResultDecoder::stateKeys()->decode(NotificationResult::stateKeys(['a'])));
        self::assertSame('inv-2', ResultDecoder::invocationId()->decode(NotificationResult::invocationId('inv-2')));
        ResultDecoder::void('sleep')->decode(NotificationResult::void());
    }

    /** @return iterable<string, array{ResultDecoder<mixed>, NotificationResult, string}> */
    public static function unsupportedResults(): iterable
    {
        yield 'sleep with a value' => [ResultDecoder::void('sleep'), NotificationResult::success('1'), 'Unsupported sleep result state: success'];
        yield 'call with an invocation id' => [ResultDecoder::json('service call'), NotificationResult::invocationId('i'), 'Unsupported service call result state: invocation_id'];
        yield 'awakeable with state keys' => [ResultDecoder::serde('awakeable', new JsonSerde()), NotificationResult::stateKeys([]), 'Unsupported awakeable result state: state_keys'];
        yield 'state keys with void' => [ResultDecoder::stateKeys(), NotificationResult::void(), 'Unsupported state keys result state: void'];
        yield 'invocation id with a value' => [ResultDecoder::invocationId(), NotificationResult::success('1'), 'Unsupported invocation ID result state: success'];
    }

    /** @param ResultDecoder<mixed> $decoder */
    #[DataProvider('unsupportedResults')]
    public function testAResultKindTheOperationNeverProducesIsUnsupported(ResultDecoder $decoder, NotificationResult $result, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);

        $decoder->decode($result);
    }
}

final class ReversingCodec implements JournalValueCodec
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

final class RejectingEmptyCodec implements JournalValueCodec
{
    public function encode(string $bytes): string
    {
        return $bytes;
    }

    public function decode(string $bytes): string
    {
        return $bytes === '' ? throw new \LogicException('The codec must not see an empty success.') : $bytes;
    }
}

final class UpperSerde implements Serde
{
    public function serialize(mixed $value): string
    {
        return strtoupper((string) $value);
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
