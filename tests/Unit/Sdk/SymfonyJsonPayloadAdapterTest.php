<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Restate\Bridge\Symfony\SymfonyJsonPayloadAdapter;
use Restate\Context;
use Restate\Internal\Discovery;
use Restate\Internal\InvocationAttempt;
use Restate\JsonMappingException;
use Restate\JsonPayloadOptions;
use Restate\JsonSchemaContext;
use Restate\JsonValueMappingContext;
use Restate\Payload;
use Restate\Service;
use Restate\Internal\ServiceRegistry;
use Restate\Tests\Support\FakeProtocolVersions;
use Restate\Tests\Support\ScriptedInvocation;
use Restate\UnsupportedJsonSchemaException;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class SymfonyJsonPayloadCreateUser
{
    #[SerializedName('user_name')]
    public string $name;
}

final class SymfonyJsonPayloadCreatedUser
{
    public function __construct(
        #[SerializedName('user_id')]
        public readonly string $id,
    ) {
    }
}

final class SymfonyJsonPayloadGroupedUser
{
    #[Groups(['public'])]
    public string $name;

    #[Groups(['private'])]
    public string $secret = 'default';
}

final class SymfonyJsonPayloadFixture
{
    public function create(Context $ctx, SymfonyJsonPayloadCreateUser $input): SymfonyJsonPayloadCreatedUser
    {
        return new SymfonyJsonPayloadCreatedUser('user-1');
    }

    public function grouped(Context $ctx, SymfonyJsonPayloadGroupedUser $input): SymfonyJsonPayloadGroupedUser
    {
        return $input;
    }

    public function scalar(Context $ctx, string $input): string
    {
        return $input;
    }
}

final class SymfonyJsonPayloadAdapterTest extends TestCase
{
    public function testHydratesAndNormalizesDtoUsingSymfonyAttributes(): void
    {
        $adapter = new SymfonyJsonPayloadAdapter($this->serializer());

        self::assertTrue($adapter->supports($this->inputContext('create')));
        $input = $adapter->hydrate(['user_name' => 'Ada'], $this->inputContext('create'));

        self::assertInstanceOf(SymfonyJsonPayloadCreateUser::class, $input);
        self::assertSame('Ada', $input->name);

        self::assertSame(
            ['user_id' => 'user-1'],
            $adapter->normalize(new SymfonyJsonPayloadCreatedUser('user-1'), $this->outputContext('create')),
        );
    }

    public function testContextResolverOverridesBaseContextForSymfonyGroups(): void
    {
        $adapter = new SymfonyJsonPayloadAdapter(
            $this->serializer(),
            normalizationContext: ['groups' => ['private']],
            denormalizationContext: ['groups' => ['private']],
            contextResolver: static fn (JsonValueMappingContext $context): array => $context->handlerName === 'grouped'
                ? ['groups' => ['public']]
                : [],
        );

        $input = $adapter->hydrate(['name' => 'Ada', 'secret' => 'hidden'], $this->inputContext('grouped'));

        self::assertInstanceOf(SymfonyJsonPayloadGroupedUser::class, $input);
        self::assertSame('Ada', $input->name);
        self::assertSame('default', $input->secret);
        self::assertSame(['name' => 'Ada'], $adapter->normalize($input, $this->outputContext('grouped')));
    }

    public function testProvidesUnknownSchemaForSupportedDtoTypesOnly(): void
    {
        $adapter = new SymfonyJsonPayloadAdapter($this->serializer());

        self::assertTrue($adapter->schemaFor($this->schemaContext('create', JsonSchemaContext::ROLE_INPUT))->isUnknown());

        $this->expectException(UnsupportedJsonSchemaException::class);
        $adapter->schemaFor($this->schemaContext('scalar', JsonSchemaContext::ROLE_INPUT));
    }

    public function testWrapsSymfonyMappingFailuresAsJsonMappingExceptions(): void
    {
        $adapter = new SymfonyJsonPayloadAdapter($this->serializer());

        try {
            $adapter->hydrate(['user_name' => ['not' => 'a string']], $this->inputContext('create'));
            self::fail('Expected Symfony mapping failure to be wrapped.');
        } catch (JsonMappingException $e) {
            self::assertStringStartsWith('Invalid JSON value at $:', $e->getMessage());
        }
    }

    public function testServiceRegistryUsesSymfonyAdapterForJsonPayloads(): void
    {
        $adapter = new SymfonyJsonPayloadAdapter($this->serializer());
        $registry = new ServiceRegistry(
            [
                Service::new('Users')->handler(
                    'create',
                    static fn (Context $ctx, SymfonyJsonPayloadCreateUser $input): SymfonyJsonPayloadCreatedUser => new SymfonyJsonPayloadCreatedUser('user-' . strtolower($input->name)),
                ),
            ],
            JsonPayloadOptions::new()->withPayloadAdapter($adapter),
        );

        self::assertSame(
            ['user_id' => 'user-ada'],
            json_decode($registry->invoke('Users', 'create', '{"user_name":"Ada"}', $this->context()), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testDiscoveryUsesConservativeSymfonySchemaProviderAndPreservesExplicitSchemas(): void
    {
        $adapter = new SymfonyJsonPayloadAdapter($this->serializer());
        $unknownRegistry = new ServiceRegistry(
            [Service::new('Users')->handler('create', static fn (Context $ctx, SymfonyJsonPayloadCreateUser $input): SymfonyJsonPayloadCreatedUser => new SymfonyJsonPayloadCreatedUser('user-1'))],
        );
        $explicitSchema = ['type' => 'object', 'required' => ['user_name']];
        $explicitRegistry = new ServiceRegistry(
            [
                Service::new('Users')->handler(
                    'create',
                    static fn (Context $ctx, SymfonyJsonPayloadCreateUser $input): SymfonyJsonPayloadCreatedUser => new SymfonyJsonPayloadCreatedUser('user-1'),
                    Payload::json($explicitSchema),
                    Payload::json($explicitSchema),
                ),
            ],
        );
        $options = JsonPayloadOptions::new()->withPayloadAdapter($adapter);

        $unknownManifest = json_decode(Discovery::manifestJson($unknownRegistry, FakeProtocolVersions::RANGE, jsonPayloads: $options), true, flags: JSON_THROW_ON_ERROR);
        $explicitManifest = json_decode(Discovery::manifestJson($explicitRegistry, FakeProtocolVersions::RANGE, jsonPayloads: $options), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($unknownManifest['services'][0]['handlers'][0]['input']['jsonSchema']);
        self::assertTrue($unknownManifest['services'][0]['handlers'][0]['output']['jsonSchema']);
        self::assertSame($explicitSchema, $explicitManifest['services'][0]['handlers'][0]['input']['jsonSchema']);
        self::assertSame($explicitSchema, $explicitManifest['services'][0]['handlers'][0]['output']['jsonSchema']);
    }

    private function serializer(): Serializer
    {
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($metadataFactory);

        return new Serializer([
            new ObjectNormalizer($metadataFactory, $nameConverter, propertyTypeExtractor: new ReflectionExtractor()),
        ]);
    }

    private function context(): InvocationAttempt
    {
        return new InvocationAttempt(ScriptedInvocation::unused());
    }

    private function inputContext(string $method): JsonValueMappingContext
    {
        $reflection = new ReflectionMethod(SymfonyJsonPayloadFixture::class, $method);

        return new JsonValueMappingContext(
            $reflection->getParameters()[1]->getType(),
            JsonValueMappingContext::ROLE_INPUT,
            'Users',
            $method,
        );
    }

    private function outputContext(string $method): JsonValueMappingContext
    {
        $reflection = new ReflectionMethod(SymfonyJsonPayloadFixture::class, $method);

        return new JsonValueMappingContext(
            $reflection->getReturnType(),
            JsonValueMappingContext::ROLE_OUTPUT,
            'Users',
            $method,
        );
    }

    private function schemaContext(string $method, string $role): JsonSchemaContext
    {
        $reflection = new ReflectionMethod(SymfonyJsonPayloadFixture::class, $method);
        $type = $role === JsonSchemaContext::ROLE_INPUT
            ? $reflection->getParameters()[1]->getType()
            : $reflection->getReturnType();

        return new JsonSchemaContext($type, $role, 'Users', $method);
    }
}
