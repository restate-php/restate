<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use Restate\Context;
use Restate\Internal\Discovery;
use Restate\Internal\InvocationAttempt;
use Restate\JsonMappingException;
use Restate\JsonPayloadOptions;
use Restate\JsonSchema\Attributes\JsonName;
use Restate\JsonValueMappingContext;
use Restate\Payload;
use Restate\Reflection\BuiltinJsonValueMapper;
use Restate\Serde;
use Restate\Service;
use Restate\Internal\ServiceRegistry;
use Restate\TerminalError;
use Restate\Tests\Support\FakeProtocolVersions;
use Restate\Tests\Support\ScriptedInvocation;

final class JsonValueMappingScalarFixture
{
    public function stringParam(Context $ctx, string $value): string
    {
        return $value;
    }
    public function intParam(Context $ctx, int $value): int
    {
        return $value;
    }
    public function floatParam(Context $ctx, float $value): float
    {
        return $value;
    }
    public function boolParam(Context $ctx, bool $value): bool
    {
        return $value;
    }
    public function nullParam(Context $ctx, null $value): null
    {
        return $value;
    }
    public function trueParam(Context $ctx, true $value): true
    {
        return $value;
    }
    public function falseParam(Context $ctx, false $value): false
    {
        return $value;
    }
    public function nullableStringParam(Context $ctx, ?string $value): ?string
    {
        return $value;
    }
    public function unionParam(Context $ctx, string|int|null $value): string|int|null
    {
        return $value;
    }
    public function falseUnionParam(Context $ctx, string|false $value): string|false
    {
        return $value;
    }
    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public function arrayParam(Context $ctx, array $value): array
    {
        return $value;
    }
    public function classParam(Context $ctx, JsonValueMappingScalarFixture $value): JsonValueMappingScalarFixture
    {
        return $value;
    }
    public function stdClassParam(Context $ctx, \stdClass $value): \stdClass
    {
        return $value;
    }
    public function classUnionParam(Context $ctx, JsonValueMappingScalarFixture|string $value): JsonValueMappingScalarFixture|string
    {
        return $value;
    }
    public function stdClassUnionParam(Context $ctx, \stdClass|string $value): \stdClass|string
    {
        return $value;
    }
}

final readonly class JsonValueMappingAliasedUser
{
    public function __construct(
        #[JsonName('user_name')]
        public string $name,
    ) {
    }
}

final readonly class JsonValueMappingUppercaseMapper implements \Restate\JsonValueMapper
{
    #[\Override]
    public function supports(JsonValueMappingContext $context): bool
    {
        return $context->role === JsonValueMappingContext::ROLE_INPUT && $context->handlerName === 'greet';
    }

    #[\Override]
    public function hydrate(mixed $value, JsonValueMappingContext $context): mixed
    {
        return is_string($value) ? strtoupper($value) : $value;
    }

    #[\Override]
    public function normalize(mixed $value, JsonValueMappingContext $context): mixed
    {
        return $value;
    }
}

final readonly class JsonValueMappingFailingOutputMapper implements \Restate\JsonValueMapper
{
    #[\Override]
    public function supports(JsonValueMappingContext $context): bool
    {
        return $context->role === JsonValueMappingContext::ROLE_OUTPUT;
    }

    #[\Override]
    public function hydrate(mixed $value, JsonValueMappingContext $context): mixed
    {
        return $value;
    }

    #[\Override]
    public function normalize(mixed $value, JsonValueMappingContext $context): mixed
    {
        throw JsonMappingException::at($context->path, 'custom output failure');
    }
}

final class JsonValueMappingFailIfCalledMapper implements \Restate\JsonValueMapper
{
    public bool $called = false;

    #[\Override]
    public function supports(JsonValueMappingContext $context): bool
    {
        $this->called = true;

        return true;
    }

    #[\Override]
    public function hydrate(mixed $value, JsonValueMappingContext $context): mixed
    {
        throw JsonMappingException::at($context->path, 'mapper should not be called');
    }

    #[\Override]
    public function normalize(mixed $value, JsonValueMappingContext $context): mixed
    {
        throw JsonMappingException::at($context->path, 'mapper should not be called');
    }
}

final readonly class JsonValueMappingEnvelopeSerde implements Serde
{
    #[\Override]
    public function serialize(mixed $value): string
    {
        return 'encoded:' . json_encode($value, JSON_THROW_ON_ERROR);
    }

    #[\Override]
    public function deserialize(string $bytes): mixed
    {
        return json_decode(substr($bytes, 8), true, flags: JSON_THROW_ON_ERROR);
    }

    #[\Override]
    public function contentType(): string
    {
        return 'application/x-json-value-mapping';
    }
}

final class JsonValueMappingTest extends TestCase
{
    public function testServiceRegistryRejectsInvalidScalarInputAsBadRequest(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}"),
        ]);

        try {
            $registry->invoke('Greeter', 'greet', '123', $this->context());
            self::fail('Expected invalid scalar input to fail.');
        } catch (TerminalError $e) {
            self::assertSame(400, $e->statusCode());
            self::assertSame('Invalid JSON value at $: expected string, got integer.', $e->getMessage());
        }
    }

    public function testServiceRegistryUsesCustomInputMapperBeforeBuiltInMapper(): void
    {
        $registry = new ServiceRegistry(
            [Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => $name)],
            JsonPayloadOptions::new()->withValueMapper(new JsonValueMappingUppercaseMapper()),
        );

        self::assertSame('"MARY"', $registry->invoke('Greeter', 'greet', '"mary"', $this->context()));
    }

    public function testServiceRegistryMapsOutputMappingFailuresToServerErrors(): void
    {
        $registry = new ServiceRegistry(
            [Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => $name)],
            JsonPayloadOptions::new()->withValueMapper(new JsonValueMappingFailingOutputMapper()),
        );

        try {
            $registry->invoke('Greeter', 'greet', '"mary"', $this->context());
            self::fail('Expected output mapping failure.');
        } catch (TerminalError $e) {
            self::assertSame(500, $e->statusCode());
            self::assertSame('Invalid JSON value at $: custom output failure.', $e->getMessage());
        }
    }

    public function testCustomSerdePayloadsBypassJsonValueMapping(): void
    {
        $mapper = new JsonValueMappingFailIfCalledMapper();
        $serde = new JsonValueMappingEnvelopeSerde();
        $registry = new ServiceRegistry(
            [
                Service::new('Codec')->handler(
                    'echo',
                    static fn (Context $ctx, array $payload): array => ['seen' => $payload['name']],
                    Payload::custom($serde),
                    Payload::custom($serde),
                ),
            ],
            JsonPayloadOptions::new()->withValueMapper($mapper),
        );

        self::assertSame('encoded:{"seen":"Mary"}', $registry->invoke('Codec', 'echo', 'encoded:{"name":"Mary"}', $this->context()));
        self::assertFalse($mapper->called);
    }

    public function testBuiltInDtoMapperUsesJsonNameForRuntimeMapping(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler(
                'rename',
                static fn (Context $ctx, JsonValueMappingAliasedUser $input): JsonValueMappingAliasedUser => new JsonValueMappingAliasedUser(strtoupper($input->name)),
            ),
        ]);

        self::assertSame(
            ['user_name' => 'ADA'],
            json_decode($registry->invoke('Users', 'rename', '{"user_name":"Ada"}', $this->context()), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testDiscoveryUsesJsonNameForDtoSchemaMetadata(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler(
                'rename',
                static fn (Context $ctx, JsonValueMappingAliasedUser $input): JsonValueMappingAliasedUser => $input,
            ),
        ]);
        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);
        $schema = [
            'type' => 'object',
            'properties' => [
                'user_name' => ['type' => 'string'],
            ],
            'required' => ['user_name'],
        ];

        self::assertSame($schema, $manifest['services'][0]['handlers'][0]['input']['jsonSchema']);
        self::assertSame($schema, $manifest['services'][0]['handlers'][0]['output']['jsonSchema']);
    }

    #[DataProvider('validScalarProvider')]
    public function testHydratesSupportedScalarValues(string $method, mixed $value, mixed $expected): void
    {
        $mapper = new BuiltinJsonValueMapper();
        $context = $this->inputContext($method);

        self::assertTrue($mapper->supports($context));
        self::assertSame($expected, $mapper->hydrate($value, $context));
    }

    /** @return iterable<string, array{string, mixed, mixed}> */
    public static function validScalarProvider(): iterable
    {
        yield 'string' => ['stringParam', 'Ada', 'Ada'];
        yield 'integer' => ['intParam', 3, 3];
        yield 'float from float' => ['floatParam', 1.5, 1.5];
        yield 'float from int' => ['floatParam', 1, 1.0];
        yield 'boolean' => ['boolParam', true, true];
        yield 'null literal' => ['nullParam', null, null];
        yield 'true literal' => ['trueParam', true, true];
        yield 'false literal' => ['falseParam', false, false];
        yield 'nullable string value' => ['nullableStringParam', 'Ada', 'Ada'];
        yield 'nullable string null' => ['nullableStringParam', null, null];
        yield 'scalar union string' => ['unionParam', 'Ada', 'Ada'];
        yield 'scalar union int' => ['unionParam', 3, 3];
        yield 'scalar union null' => ['unionParam', null, null];
        yield 'false union false' => ['falseUnionParam', false, false];
    }

    #[DataProvider('invalidScalarProvider')]
    public function testRejectsIncompatibleScalarValues(string $method, mixed $value, string $message): void
    {
        $mapper = new BuiltinJsonValueMapper();

        $this->expectException(JsonMappingException::class);
        $this->expectExceptionMessage($message);

        $mapper->hydrate($value, $this->inputContext($method));
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function invalidScalarProvider(): iterable
    {
        yield 'integer is not string' => ['stringParam', 123, 'Invalid JSON value at $: expected string, got integer.'];
        yield 'float is not int' => ['intParam', 1.2, 'Invalid JSON value at $: expected integer, got double.'];
        yield 'integer is not bool' => ['boolParam', 1, 'Invalid JSON value at $: expected boolean, got integer.'];
        yield 'false is not true' => ['trueParam', false, 'Invalid JSON value at $: expected true, got boolean.'];
        yield 'true is not false' => ['falseParam', true, 'Invalid JSON value at $: expected false, got boolean.'];
        yield 'false is not scalar union' => ['unionParam', false, 'Invalid JSON value at $: unsupported JSON value mapping type.'];
    }

    public function testArrayTypesAreNotHandledByScalarMapper(): void
    {
        $mapper = new BuiltinJsonValueMapper();

        self::assertFalse($mapper->supports($this->inputContext('arrayParam')));
    }

    public function testNormalizesSupportedScalarOutputs(): void
    {
        $mapper = new BuiltinJsonValueMapper();
        $context = $this->outputContext('floatParam');

        self::assertTrue($mapper->supports($context));
        self::assertSame(1.0, $mapper->normalize(1, $context));
    }

    public function testDetectsTypesThatRequireMapping(): void
    {
        self::assertTrue(BuiltinJsonValueMapper::requiresMapping($this->inputContext('classParam')->type));
        self::assertFalse(BuiltinJsonValueMapper::requiresMapping($this->inputContext('stdClassParam')->type));
        self::assertFalse(BuiltinJsonValueMapper::requiresMapping($this->inputContext('stringParam')->type));
        self::assertTrue(BuiltinJsonValueMapper::requiresMapping($this->inputContext('classUnionParam')->type));
        self::assertFalse(BuiltinJsonValueMapper::requiresMapping($this->inputContext('stdClassUnionParam')->type));
        self::assertFalse(BuiltinJsonValueMapper::requiresMapping($this->inputContext('unionParam')->type));
    }

    private function inputContext(string $method): JsonValueMappingContext
    {
        $reflection = new ReflectionMethod(JsonValueMappingScalarFixture::class, $method);
        $type = $reflection->getParameters()[1]->getType();

        return new JsonValueMappingContext($type, JsonValueMappingContext::ROLE_INPUT, 'Scalar', $method);
    }

    private function outputContext(string $method): JsonValueMappingContext
    {
        $reflection = new ReflectionMethod(JsonValueMappingScalarFixture::class, $method);
        $type = $reflection->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);

        return new JsonValueMappingContext($type, JsonValueMappingContext::ROLE_OUTPUT, 'Scalar', $method);
    }

    private function context(): InvocationAttempt
    {
        return new InvocationAttempt(ScriptedInvocation::unused());
    }
}
