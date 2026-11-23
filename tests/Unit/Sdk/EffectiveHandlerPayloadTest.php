<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\Internal\Discovery;
use Restate\Internal\InvocationAttempt;
use Restate\JsonMappingException;
use Restate\JsonPayloadOptions;
use Restate\JsonSchemaContext;
use Restate\JsonSchemaMetadata;
use Restate\JsonSchema\Attributes\JsonName;
use Restate\JsonSchemaProvider;
use Restate\JsonValueMapper;
use Restate\JsonValueMappingContext;
use Restate\Payload;
use Restate\Serde;
use Restate\Service;
use Restate\TerminalError;
use Restate\Internal\ServiceRegistry;
use Restate\Tests\Support\FakeProtocolVersions;
use Restate\Tests\Support\ScriptedInvocation;
use Restate\PayloadRole;

final class EffectiveHandlerPayloadTest extends TestCase
{
    public function testCustomDefaultSerdeWithJsonContentTypeBypassesJsonPayloadAdapterAndSchemaProvider(): void
    {
        $mapper = new EffectiveHandlerPayloadFailIfCalledMapper();
        $schemaProvider = new EffectiveHandlerPayloadSchemaProvider();
        $registry = new ServiceRegistry(
            [
                Service::new('Users')->handler(
                    'echo',
                    static fn (Context $ctx, array $payload): array => ['seen' => $payload['name']],
                ),
            ],
            JsonPayloadOptions::new()->withValueMapper($mapper)->withSchemaProvider($schemaProvider),
            defaultSerde: new EffectiveHandlerPayloadEnvelopeSerde('default', 'application/json'),
        );

        self::assertSame('default:{"seen":"Ada"}', $registry->invoke('Users', 'echo', 'default:{"name":"Ada"}', $this->context()));

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(['required' => false, 'contentType' => 'application/json'], $handler['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json'], $handler['output']);
        self::assertSame(0, $mapper->supportsCalls);
        self::assertSame(0, $schemaProvider->calls);
    }

    public function testDiscoveryUsesRegistryJsonPayloadOptionsByDefault(): void
    {
        $schemaProvider = new EffectiveHandlerPayloadSchemaProvider();
        $registry = new ServiceRegistry(
            [Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => $name)],
            JsonPayloadOptions::new()->withSchemaProvider($schemaProvider),
        );

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(['type' => 'string', 'description' => 'input schema'], $handler['input']['jsonSchema']);
        self::assertSame(['type' => 'string', 'description' => 'output schema'], $handler['output']['jsonSchema']);
        self::assertSame(2, $schemaProvider->calls);
    }

    public function testRegistryResolvesEachHandlerPayloadOnce(): void
    {
        $service = Service::new('Greeter')
            ->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}")
            ->definition();
        $handler = $service->handlers[0];
        $registry = new ServiceRegistry(
            [$service],
            defaultSerde: new EffectiveHandlerPayloadEnvelopeSerde('default', 'application/x-default'),
        );

        $input = $registry->effectiveHandlerInputPayload($service, $handler);
        $output = $registry->effectiveHandlerOutputPayload($service, $handler);
        self::assertSame('default:"Hello Ada"', $registry->invoke('Greeter', 'greet', 'default:"Ada"', $this->context()));
        Discovery::manifestJson($registry, FakeProtocolVersions::RANGE);

        self::assertSame($input, $registry->effectiveHandlerInputPayload($service, $handler));
        self::assertSame($output, $registry->effectiveHandlerOutputPayload($service, $handler));
        self::assertSame('application/x-default', $input->manifest()['contentType'] ?? null);
    }

    public function testRegistryRejectsPayloadLookupForHandlerItDoesNotServe(): void
    {
        $registered = Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => $name);
        $registry = new ServiceRegistry([$registered]);
        $other = $registered->definition();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler Greeter/greet is not registered in this ServiceRegistry.');

        $registry->effectiveHandlerInputPayload($other, $other->handlers[0]);
    }

    public function testExplicitJsonPayloadKeepsJsonValueMappingWhenDefaultSerdeIsCustom(): void
    {
        $mapper = new EffectiveHandlerPayloadUppercaseInputMapper();
        $registry = new ServiceRegistry(
            [
                Service::new('Greeter')->handler(
                    'greet',
                    static fn (Context $ctx, string $name): string => $name,
                    Payload::json(),
                    Payload::json(),
                ),
            ],
            JsonPayloadOptions::new()->withValueMapper($mapper),
            defaultSerde: new EffectiveHandlerPayloadEnvelopeSerde('default', 'application/x-default'),
        );

        self::assertSame('"ADA"', $registry->invoke('Greeter', 'greet', '"Ada"', $this->context()));
        self::assertSame(1, $mapper->hydrateCalls);
    }

    /** @return iterable<string, array{\Closure, string}> */
    public static function optionalInputs(): iterable
    {
        yield 'nullable with a default' => [static fn (Context $ctx, ?string $name = null): string => $name ?? 'nobody', '"nobody"'];
        yield 'default' => [static fn (Context $ctx, string $name = 'world'): string => $name, '"world"'];
        yield 'nullable' => [static fn (Context $ctx, ?string $name): string => $name ?? 'nobody', '"nobody"'];
        yield 'mixed' => [static fn (Context $ctx, mixed $value): string => get_debug_type($value), '"null"'];
        yield 'untyped' => [static fn (Context $ctx, $value): string => get_debug_type($value), '"null"'];
        yield 'nullable DTO' => [static fn (Context $ctx, ?EffectiveHandlerPayloadPerson $person): string => $person->name ?? 'nobody', '"nobody"'];
    }

    /**
     * An empty body is no value: discovery advertises the input as optional, and the ingress client sends a null
     * payload as no body. The handler gets the parameter's default, or null when the type accepts it, without the
     * JSON value mapper seeing it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('optionalInputs')]
    public function testAnEmptyJsonInputIsTheParametersDefaultOrNull(\Closure $handler, string $output): void
    {
        $mapper = new EffectiveHandlerPayloadInputCountingMapper();
        $registry = new ServiceRegistry([Service::new('Greeter')->handler('greet', $handler)], JsonPayloadOptions::new()->withValueMapper($mapper));

        self::assertSame($output, $registry->invoke('Greeter', 'greet', '', $this->context()));
        self::assertSame(0, $mapper->inputCalls);
    }

    public function testAnEmptyJsonInputForAParameterThatNeedsAValueIsABadRequest(): void
    {
        $registry = new ServiceRegistry([Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => $name)]);

        try {
            $registry->invoke('Greeter', 'greet', '', $this->context());
            self::fail('Expected an empty body to fail.');
        } catch (TerminalError $e) {
            self::assertSame(400, $e->statusCode());
            self::assertSame('Greeter/greet requires an input payload, but the request has no body.', $e->getMessage());
        }
    }

    public function testADtoWithCollidingJsonNamesFailsTheInvocationTerminally(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler(
                'save',
                static fn (Context $ctx, EffectiveHandlerPayloadJsonNameCollision $user): string => $user->name,
            ),
        ]);

        try {
            $registry->invoke('Users', 'save', '{"name":"Ada"}', $this->context());
            self::fail('Expected a terminal error.');
        } catch (TerminalError $e) {
            self::assertSame(500, $e->statusCode());
            self::assertStringContainsString('to the same JSON name "name"', $e->getMessage());
        }
    }

    private function context(): InvocationAttempt
    {
        return new InvocationAttempt(ScriptedInvocation::unused());
    }
}

final class EffectiveHandlerPayloadEnvelopeSerde implements Serde
{
    public function __construct(private readonly string $prefix, private readonly string $contentType)
    {
    }

    #[\Override]
    public function serialize(mixed $value): string
    {
        return $this->prefix . ':' . json_encode($value, JSON_THROW_ON_ERROR);
    }

    #[\Override]
    public function deserialize(string $bytes): mixed
    {
        $prefix = $this->prefix . ':';
        if (!str_starts_with($bytes, $prefix)) {
            throw new \InvalidArgumentException("Expected {$this->prefix} bytes.");
        }

        return json_decode(substr($bytes, strlen($prefix)), true, flags: JSON_THROW_ON_ERROR);
    }

    #[\Override]
    public function contentType(): string
    {
        return $this->contentType;
    }
}

final class EffectiveHandlerPayloadFailIfCalledMapper implements JsonValueMapper
{
    public int $supportsCalls = 0;

    #[\Override]
    public function supports(JsonValueMappingContext $context): bool
    {
        ++$this->supportsCalls;

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

final class EffectiveHandlerPayloadUppercaseInputMapper implements JsonValueMapper
{
    public int $hydrateCalls = 0;

    #[\Override]
    public function supports(JsonValueMappingContext $context): bool
    {
        return $context->role === PayloadRole::Input;
    }

    #[\Override]
    public function hydrate(mixed $value, JsonValueMappingContext $context): mixed
    {
        ++$this->hydrateCalls;

        return is_string($value) ? strtoupper($value) : $value;
    }

    #[\Override]
    public function normalize(mixed $value, JsonValueMappingContext $context): mixed
    {
        return $value;
    }
}

final class EffectiveHandlerPayloadSchemaProvider implements JsonSchemaProvider
{
    public int $calls = 0;

    #[\Override]
    public function schemaFor(JsonSchemaContext $context): JsonSchemaMetadata
    {
        ++$this->calls;

        return JsonSchemaMetadata::fromArray(['type' => 'string', 'description' => "{$context->role->value} schema"]);
    }
}

final readonly class EffectiveHandlerPayloadPerson
{
    public function __construct(public string $name)
    {
    }
}

/** Maps no output, and counts the inputs it is asked about. */
final class EffectiveHandlerPayloadInputCountingMapper implements JsonValueMapper
{
    public int $inputCalls = 0;

    #[\Override]
    public function supports(JsonValueMappingContext $context): bool
    {
        if ($context->role === PayloadRole::Input) {
            ++$this->inputCalls;
        }

        return false;
    }

    #[\Override]
    public function hydrate(mixed $value, JsonValueMappingContext $context): mixed
    {
        return $value;
    }

    #[\Override]
    public function normalize(mixed $value, JsonValueMappingContext $context): mixed
    {
        return $value;
    }
}

final readonly class EffectiveHandlerPayloadJsonNameCollision
{
    public function __construct(
        #[JsonName('name')]
        public string $userName,
        public string $name,
    ) {
    }
}
