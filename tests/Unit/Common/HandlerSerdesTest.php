<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Common;

use PHPUnit\Framework\TestCase;
use Restate\JsonMappingException;
use Restate\JsonSerde;
use Restate\JsonValueMapper;
use Restate\JsonValueMappingContext;
use Restate\Reflection\BuiltinJsonValueMapper;
use Restate\Reflection\HandlerSerdes;
use Restate\Tests\Unit\Common\Fixtures\Order;
use Restate\Tests\Unit\Common\Fixtures\Priority;
use Restate\Tests\Unit\Common\Fixtures\Receipt;
use Restate\Tests\Unit\Common\Fixtures\TaggedTextSerde;
use Restate\PayloadRole;

/** The caller's side of a handler's payloads, built from its declared types. */
final class HandlerSerdesTest extends TestCase
{
    public function testJsonPayloadsAreMappedToAndFromTheSignaturesTypes(): void
    {
        $quote = HandlerSerdes::of('Greeter', 'quote', static fn (Order $order): Receipt => throw new \LogicException());

        self::assertSame('{"id":"o-1","quantity":3,"priority":"express"}', $quote->input->serialize(new Order('o-1', 3, Priority::Express)));
        self::assertEquals(new Receipt('o-1', 3, Priority::Express), $quote->output->deserialize('{"orderId":"o-1","itemCount":3,"priority":"express"}'));
        self::assertSame('application/json', $quote->input->contentType());
        self::assertSame(['a'], HandlerSerdes::of('Greeter', 'keys', static fn (array $counts): array => throw new \LogicException())->output->deserialize('["a"]'));
        self::assertSame(['x' => [1]], HandlerSerdes::of('Greeter', 'echo', static fn (mixed $value): mixed => throw new \LogicException())->output->deserialize('{"x":[1]}'));
        self::assertSame(['x' => [1]], HandlerSerdes::of('Greeter', 'untyped', static fn ($value) => throw new \LogicException())->output->deserialize('{"x":[1]}'));
    }

    public function testScalarOutputsAreCheckedAgainstTheReturnType(): void
    {
        $this->expectException(JsonMappingException::class);
        $this->expectExceptionMessage('$: expected integer, got string');

        HandlerSerdes::of('Counter', 'get', static fn (): int => throw new \LogicException())->output->deserialize('"seven"');
    }

    public function testNoInputIsPlainJsonAndNoOutputDecodesToNull(): void
    {
        $ping = HandlerSerdes::of('Greeter', 'ping', static fn () => throw new \LogicException());

        self::assertInstanceOf(JsonSerde::class, $ping->input);
        self::assertNull($ping->output->deserialize(''));
    }

    public function testBinaryAndCustomSerializerPayloads(): void
    {
        $bytes = HandlerSerdes::of('Greeter', 'reverseBytes', static fn (string $bytes) => throw new \LogicException(), input: HandlerSerdes::bytes(), output: HandlerSerdes::bytes('image/png'));
        $text = new TaggedTextSerde();
        $shout = HandlerSerdes::of('Greeter', 'shout', static fn (string $text) => throw new \LogicException(), input: $text, output: $text);

        self::assertSame("\x00\xff", $bytes->input->serialize("\x00\xff"));
        self::assertSame("\x00\xff", $bytes->output->deserialize("\x00\xff"));
        self::assertSame('application/octet-stream', $bytes->input->contentType());
        self::assertSame('image/png', $bytes->output->contentType());
        self::assertSame($text, $shout->input);
        self::assertSame($text, $shout->output);
    }

    public function testTheGivenValueMapperIsUsedWhenItSupportsTheType(): void
    {
        $mapper = new class () implements JsonValueMapper {
            public function supports(JsonValueMappingContext $context): bool
            {
                return $context->handlerName === 'greet';
            }

            public function hydrate(mixed $value, JsonValueMappingContext $context): mixed
            {
                return 'hydrated ' . (is_string($value) ? $value : '?');
            }

            public function normalize(mixed $value, JsonValueMappingContext $context): mixed
            {
                return 'normalized';
            }
        };
        $greet = HandlerSerdes::of('Greeter', 'greet', static fn (string $name): string => throw new \LogicException(), $mapper);
        $quote = HandlerSerdes::of('Greeter', 'quote', static fn (Order $order): Receipt => throw new \LogicException(), $mapper);

        self::assertSame('"normalized"', $greet->input->serialize('Mary'));
        self::assertSame('hydrated Hello', $greet->output->deserialize('"Hello"'));
        self::assertSame('{"id":"o-1","quantity":1,"priority":"normal"}', $quote->input->serialize(new Order('o-1', 1)));
    }

    public function testRejectsASignatureWithMoreThanThePayloadAndAnEmptyContentType(): void
    {
        try {
            HandlerSerdes::of('Greeter', 'greet', static fn (string $name, int $extra) => throw new \LogicException());
            self::fail('A signature takes at most the payload.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("The signature of Greeter/greet must declare at most one parameter, the handler's payload.", $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        HandlerSerdes::bytes('');
    }

    public function testTheBuiltinMapperHydratesAndNormalizesWhicheverRoleThePayloadHas(): void
    {
        $type = new \ReflectionFunction(static fn (Order $order): Receipt => throw new \LogicException())->getParameters()[0]->getType();
        $mapper = new BuiltinJsonValueMapper();
        $input = new JsonValueMappingContext($type, PayloadRole::Input, 'Checkout', 'run');

        self::assertSame(['id' => 'o-1', 'quantity' => 1, 'priority' => 'normal'], $mapper->normalize(new Order('o-1', 1), $input));
        self::assertEquals(new Order('o-1', 1), $mapper->hydrate(['id' => 'o-1', 'quantity' => 1], new JsonValueMappingContext($type, PayloadRole::Output, 'Checkout', 'run')));
    }
}
