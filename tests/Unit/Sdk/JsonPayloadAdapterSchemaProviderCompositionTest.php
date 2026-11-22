<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use CuyZ\Valinor\MapperBuilder;
use CuyZ\Valinor\Normalizer\Format;
use CuyZ\Valinor\NormalizerBuilder;
use PHPUnit\Framework\TestCase;
use Restate\Bridge\Symfony\SymfonyJsonPayloadAdapter;
use Restate\Bridge\Valinor\ValinorJsonPayloadAdapter;
use Restate\Context;
use Restate\Internal\Discovery;
use Restate\FallbackJsonSchemaProvider;
use Restate\Internal\InvocationAttempt;
use Restate\JsonPayloadOptions;
use Restate\JsonSchemaContext;
use Restate\JsonSchemaMetadata;
use Restate\JsonSchemaProvider;
use Restate\Service;
use Restate\Internal\ServiceRegistry;
use Restate\Tests\Support\FakeProtocolVersions;
use Restate\Tests\Support\ScriptedInvocation;
use Restate\UnsupportedJsonSchemaException;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final readonly class JsonPayloadCompositionCreateUser
{
    public function __construct(
        private string $name,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }
}

final readonly class JsonPayloadCompositionCreatedUser
{
    public function __construct(
        private string $id,
        private string $name,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

final class JsonPayloadCompositionSchemaProvider implements JsonSchemaProvider
{
    public function schemaFor(JsonSchemaContext $context): JsonSchemaMetadata
    {
        if ($context->serviceName !== 'Users' || $context->handlerName !== 'create') {
            throw UnsupportedJsonSchemaException::forContext($context);
        }

        return match ($context->role) {
            JsonSchemaContext::ROLE_INPUT => JsonSchemaMetadata::fromArray([
                'type' => 'object',
                'description' => 'Custom schema for composed create user input.',
                'required' => ['name'],
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Display name accepted on the JSON wire.',
                    ],
                ],
            ]),
            JsonSchemaContext::ROLE_OUTPUT => JsonSchemaMetadata::fromArray([
                'type' => 'object',
                'description' => 'Custom schema for composed create user output.',
                'required' => ['id', 'name'],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                ],
            ]),
            default => throw UnsupportedJsonSchemaException::forContext($context),
        };
    }
}

final class JsonPayloadAdapterSchemaProviderCompositionTest extends TestCase
{
    public function testSymfonyAdapterMapsDtosWhileCustomProviderSuppliesDiscoverySchemas(): void
    {
        $adapter = new SymfonyJsonPayloadAdapter($this->symfonySerializer());
        $schemaProvider = new JsonPayloadCompositionSchemaProvider();
        $options = JsonPayloadOptions::new()
            ->withValueMapper($adapter)
            ->withSchemaProvider(new FallbackJsonSchemaProvider($schemaProvider, $adapter));
        $registry = $this->registry($options);

        $output = json_decode($registry->invoke('Users', 'create', '{"name":"Ada"}', $this->context()), true, flags: JSON_THROW_ON_ERROR);
        $handler = $this->manifestHandler($registry, $options);

        self::assertSame('user-ada', $output['id']);
        self::assertSame('Ada', $output['name']);
        $this->assertCustomJsonSchemaMetadata($handler);
    }

    public function testValinorAdapterMapsDtosWhileCustomProviderSuppliesDiscoverySchemas(): void
    {
        $adapter = $this->valinorAdapter();
        $schemaProvider = new JsonPayloadCompositionSchemaProvider();
        $options = JsonPayloadOptions::new()
            ->withValueMapper($adapter)
            ->withSchemaProvider(new FallbackJsonSchemaProvider($schemaProvider, $adapter));
        $registry = $this->registry($options);

        $output = json_decode($registry->invoke('Users', 'create', '{"name":"Ada"}', $this->context()), true, flags: JSON_THROW_ON_ERROR);
        $handler = $this->manifestHandler($registry, $options);

        self::assertSame('user-ada', $output['id']);
        self::assertSame('Ada', $output['name']);
        $this->assertCustomJsonSchemaMetadata($handler);
    }

    public function testSymfonyConservativeSchemaProviderMustFollowRicherSchemaProvider(): void
    {
        $adapter = new SymfonyJsonPayloadAdapter($this->symfonySerializer());
        $schemaProvider = new JsonPayloadCompositionSchemaProvider();
        $registry = $this->registry();

        $adapterFirstHandler = $this->manifestHandler(
            $registry,
            JsonPayloadOptions::new()->withSchemaProvider(new FallbackJsonSchemaProvider($adapter, $schemaProvider)),
        );
        $schemaProviderFirstHandler = $this->manifestHandler(
            $registry,
            JsonPayloadOptions::new()->withSchemaProvider(new FallbackJsonSchemaProvider($schemaProvider, $adapter)),
        );

        self::assertTrue($adapterFirstHandler['input']['jsonSchema']);
        $this->assertCustomJsonSchemaMetadata($schemaProviderFirstHandler);
    }

    public function testValinorConservativeSchemaProviderMustFollowRicherSchemaProvider(): void
    {
        $adapter = $this->valinorAdapter();
        $schemaProvider = new JsonPayloadCompositionSchemaProvider();
        $registry = $this->registry();

        $adapterFirstHandler = $this->manifestHandler(
            $registry,
            JsonPayloadOptions::new()->withSchemaProvider(new FallbackJsonSchemaProvider($adapter, $schemaProvider)),
        );
        $schemaProviderFirstHandler = $this->manifestHandler(
            $registry,
            JsonPayloadOptions::new()->withSchemaProvider(new FallbackJsonSchemaProvider($schemaProvider, $adapter)),
        );

        self::assertTrue($adapterFirstHandler['input']['jsonSchema']);
        $this->assertCustomJsonSchemaMetadata($schemaProviderFirstHandler);
    }

    private function symfonySerializer(): Serializer
    {
        return new Serializer([
            new ObjectNormalizer(
                new ClassMetadataFactory(new AttributeLoader()),
                propertyTypeExtractor: new ReflectionExtractor(),
            ),
        ]);
    }

    private function valinorAdapter(): ValinorJsonPayloadAdapter
    {
        return new ValinorJsonPayloadAdapter(
            new MapperBuilder()->mapper(),
            new NormalizerBuilder()->normalizer(Format::array()),
        );
    }

    private function registry(?JsonPayloadOptions $options = null): ServiceRegistry
    {
        return new ServiceRegistry([
            Service::new('Users')->handler(
                'create',
                static fn (Context $ctx, JsonPayloadCompositionCreateUser $input): JsonPayloadCompositionCreatedUser => new JsonPayloadCompositionCreatedUser(
                    'user-' . strtolower($input->getName()),
                    $input->getName(),
                ),
            ),
        ], $options);
    }

    /** @return array<string, mixed> */
    private function manifestHandler(ServiceRegistry $registry, JsonPayloadOptions $options): array
    {
        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, jsonPayloads: $options), true, flags: JSON_THROW_ON_ERROR);

        return $manifest['services'][0]['handlers'][0];
    }

    /** @param array<string, mixed> $handler */
    private function assertCustomJsonSchemaMetadata(array $handler): void
    {
        self::assertIsArray($handler['input']['jsonSchema']);
        self::assertIsArray($handler['output']['jsonSchema']);

        $inputSchema = $handler['input']['jsonSchema'];
        $outputSchema = $handler['output']['jsonSchema'];

        self::assertSame('object', $inputSchema['type']);
        self::assertSame('Custom schema for composed create user input.', $inputSchema['description']);
        self::assertSame(['name'], $inputSchema['required']);
        self::assertSame('string', $inputSchema['properties']['name']['type']);
        self::assertSame('Display name accepted on the JSON wire.', $inputSchema['properties']['name']['description']);

        self::assertSame('object', $outputSchema['type']);
        self::assertSame('Custom schema for composed create user output.', $outputSchema['description']);
        self::assertSame(['id', 'name'], $outputSchema['required']);
        self::assertSame('string', $outputSchema['properties']['id']['type']);
        self::assertSame('string', $outputSchema['properties']['name']['type']);
    }

    private function context(): InvocationAttempt
    {
        return new InvocationAttempt(ScriptedInvocation::unused());
    }
}
