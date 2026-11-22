<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Restate\BuiltinJsonSchemaProvider;
use Restate\Context;
use Restate\FallbackJsonSchemaProvider;
use Restate\JsonMappingException;
use Restate\JsonPayloadOptions;
use Restate\JsonSchema;
use Restate\JsonSchemaContext;
use Restate\JsonSchemaMetadata;
use Restate\JsonSchemaProvider;
use Restate\JsonValueMapper;
use Restate\JsonValueMappingContext;
use Restate\Payload;
use Restate\UnsupportedJsonSchemaException;

final class JsonPayloadOptionsFixture
{
    public function handle(Context $ctx, string $name): string
    {
        return $name;
    }
}

final readonly class JsonPayloadOptionsTestMapper implements JsonValueMapper
{
    #[\Override]
    public function supports(JsonValueMappingContext $context): bool
    {
        return $context->role === JsonValueMappingContext::ROLE_INPUT;
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

final readonly class JsonPayloadOptionsTestSchemaProvider implements JsonSchemaProvider
{
    #[\Override]
    public function schemaFor(JsonSchemaContext $context): JsonSchemaMetadata
    {
        return JsonSchemaMetadata::fromArray(['type' => 'string']);
    }
}

final readonly class JsonPayloadOptionsTestUnsupportedSchemaProvider implements JsonSchemaProvider
{
    #[\Override]
    public function schemaFor(JsonSchemaContext $context): JsonSchemaMetadata
    {
        throw UnsupportedJsonSchemaException::forContext($context);
    }
}

final readonly class JsonPayloadOptionsTestAdapter implements JsonValueMapper, JsonSchemaProvider
{
    #[\Override]
    public function supports(JsonValueMappingContext $context): bool
    {
        return true;
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

    #[\Override]
    public function schemaFor(JsonSchemaContext $context): JsonSchemaMetadata
    {
        return JsonSchemaMetadata::fromArray(['type' => 'object']);
    }
}

final class JsonPayloadOptionsTest extends TestCase
{
    public function testOptionsAreImmutableAndExposeConfiguredAdapters(): void
    {
        $mapper = new JsonPayloadOptionsTestMapper();
        $schemaProvider = new JsonPayloadOptionsTestSchemaProvider();

        $base = JsonPayloadOptions::new();
        $withMapper = $base->withValueMapper($mapper);
        $withBoth = $withMapper->withSchemaProvider($schemaProvider);

        self::assertNull($base->valueMapper());
        self::assertNull($base->schemaProvider());
        self::assertSame($mapper, $withMapper->valueMapper());
        self::assertNull($withMapper->schemaProvider());
        self::assertSame($mapper, $withBoth->valueMapper());
        self::assertSame($schemaProvider, $withBoth->schemaProvider());
    }

    public function testPayloadAdapterConfiguresValueMapperAndSchemaProvider(): void
    {
        $adapter = new JsonPayloadOptionsTestAdapter();

        $base = JsonPayloadOptions::new();
        $withAdapter = $base->withPayloadAdapter($adapter);

        self::assertNull($base->valueMapper());
        self::assertNull($base->schemaProvider());
        self::assertSame($adapter, $withAdapter->valueMapper());
        self::assertSame($adapter, $withAdapter->schemaProvider());
    }

    public function testSchemaProvidersHelperComposesFallbackProvider(): void
    {
        $unsupported = new JsonPayloadOptionsTestUnsupportedSchemaProvider();
        $fallback = new JsonPayloadOptionsTestSchemaProvider();

        $options = JsonPayloadOptions::new()->withSchemaProviders($unsupported, $fallback);

        self::assertInstanceOf(FallbackJsonSchemaProvider::class, $options->schemaProvider());
        self::assertSame(['type' => 'string'], $options->schemaProvider()->schemaFor($this->schemaContext())->toManifestValue());
    }

    public function testSchemaProvidersHelperKeepsSingleProviderDirectly(): void
    {
        $provider = new JsonPayloadOptionsTestSchemaProvider();

        $options = JsonPayloadOptions::new()->withSchemaProviders($provider);

        self::assertSame($provider, $options->schemaProvider());
    }

    public function testDefaultsIncludeBuiltInSchemaProviderWhileNewStartsBlank(): void
    {
        $replacement = new JsonPayloadOptionsTestSchemaProvider();

        $defaults = JsonPayloadOptions::defaults();
        $replaced = $defaults->withSchemaProvider($replacement);

        self::assertNull(JsonPayloadOptions::new()->schemaProvider());
        self::assertInstanceOf(BuiltinJsonSchemaProvider::class, $defaults->schemaProvider());
        self::assertSame($replacement, $replaced->schemaProvider());
    }

    public function testFallbackSchemaProviderRejectsEmptyProviderList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fallback JSON Schema provider requires at least one provider.');

        new FallbackJsonSchemaProvider();
    }

    public function testJsonSchemaMetadataExposesManifestValues(): void
    {
        self::assertSame(['type' => 'string'], JsonSchemaMetadata::fromArray(['type' => 'string'])->toManifestValue());
        self::assertSame(['$schema' => 'http://json-schema.org/draft-07/schema#', 'type' => 'object'], JsonSchemaMetadata::fromArray(['$schema' => 'http://json-schema.org/draft-07/schema#', 'type' => 'object'])->toManifestValue());
        self::assertSame(['type' => 'integer'], JsonSchemaMetadata::fromSchema(JsonSchema::integer())->toManifestValue());
        self::assertFalse(JsonSchemaMetadata::fromSchema(JsonSchema::neverValid())->toManifestValue());
        self::assertTrue(JsonSchemaMetadata::unknown()->toManifestValue());
        self::assertEquals(new \stdClass(), JsonSchemaMetadata::any()->toManifestValue());
    }

    public function testJsonSchemaMetadataPublishesDocumentsOfAnyDialectAsGiven(): void
    {
        $document = [
            '$schema' => 'http://json-schema.org/draft-04/schema#',
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string']],
            'definitions' => ['empty' => new \stdClass()],
        ];

        self::assertEquals($document, JsonSchemaMetadata::fromArray($document)->toManifestValue());
        self::assertEquals($document, Payload::json($document)->explicitJsonSchema());
    }

    public function testJsonSchemaMetadataRejectsAmbiguousTopLevelArrays(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON Schema document must be a non-empty JSON object.');

        JsonSchemaMetadata::fromArray([]);
    }

    public function testMappingContextTracksRoleTypeAndPath(): void
    {
        $reflection = new ReflectionMethod(JsonPayloadOptionsFixture::class, 'handle');
        $type = $reflection->getParameters()[1]->getType();

        $context = new JsonValueMappingContext($type, JsonValueMappingContext::ROLE_INPUT, 'Greeter', 'greet');
        $child = $context->at('name', $type);

        self::assertSame($type, $context->type);
        self::assertSame('input', $context->role);
        self::assertSame('Greeter', $context->serviceName);
        self::assertSame('greet', $context->handlerName);
        self::assertSame('$', $context->path);
        self::assertSame('$.name', $child->path);
        self::assertSame($type, $child->type);
    }

    public function testSchemaContextTracksRoleAndType(): void
    {
        $reflection = new ReflectionMethod(JsonPayloadOptionsFixture::class, 'handle');
        $type = $reflection->getReturnType();

        $context = new JsonSchemaContext($type, JsonSchemaContext::ROLE_OUTPUT, 'Greeter', 'greet');

        self::assertSame($type, $context->type);
        self::assertSame('output', $context->role);
        self::assertSame('Greeter', $context->serviceName);
        self::assertSame('greet', $context->handlerName);
    }

    public function testMappingExceptionMessageIncludesPath(): void
    {
        $exception = JsonMappingException::at('$.name', 'expected string, got integer');

        self::assertSame('Invalid JSON value at $.name: expected string, got integer.', $exception->getMessage());
    }

    private function schemaContext(): JsonSchemaContext
    {
        $reflection = new ReflectionMethod(JsonPayloadOptionsFixture::class, 'handle');

        return new JsonSchemaContext($reflection->getReturnType(), JsonSchemaContext::ROLE_OUTPUT, 'Greeter', 'greet');
    }
}
