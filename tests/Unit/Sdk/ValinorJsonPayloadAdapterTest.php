<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use Attribute;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use CuyZ\Valinor\Normalizer\AsTransformer;
use CuyZ\Valinor\Normalizer\Format;
use CuyZ\Valinor\Normalizer\Normalizer;
use CuyZ\Valinor\NormalizerBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Restate\Bridge\Valinor\ValinorJsonPayloadAdapter;
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

final readonly class ValinorJsonPayloadCreateUser
{
    public function __construct(
        public string $name,
    ) {
    }
}

final readonly class ValinorJsonPayloadCreatedUser
{
    public function __construct(
        #[ValinorJsonPayloadPrefixedWith('user_')]
        public string $id,
    ) {
    }
}

#[AsTransformer]
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class ValinorJsonPayloadPrefixedWith
{
    public function __construct(private string $prefix)
    {
    }

    public function normalizeKey(string $key): string
    {
        return $this->prefix . $key;
    }
}

final class ValinorJsonPayloadFixture
{
    public function create(Context $ctx, ValinorJsonPayloadCreateUser $input): ValinorJsonPayloadCreatedUser
    {
        return new ValinorJsonPayloadCreatedUser('user-1');
    }

    public function scalar(Context $ctx, string $input): string
    {
        return $input;
    }
}

final class ValinorJsonPayloadAdapterTest extends TestCase
{
    public function testHydratesAndNormalizesDtoUsingValinorConfiguration(): void
    {
        $adapter = new ValinorJsonPayloadAdapter($this->mapper(), $this->normalizer());

        self::assertTrue($adapter->supports($this->inputContext('create')));
        $input = $adapter->hydrate(['user_name' => 'Ada'], $this->inputContext('create'));

        self::assertInstanceOf(ValinorJsonPayloadCreateUser::class, $input);
        self::assertSame('Ada', $input->name);

        self::assertSame(
            ['user_id' => 'user-1'],
            $adapter->normalize(new ValinorJsonPayloadCreatedUser('user-1'), $this->outputContext('create')),
        );
    }

    public function testProvidesUnknownSchemaForSupportedDtoTypesOnly(): void
    {
        $adapter = new ValinorJsonPayloadAdapter($this->mapper(), $this->normalizer());

        self::assertTrue($adapter->schemaFor($this->schemaContext('create', JsonSchemaContext::ROLE_INPUT))->isUnknown());

        $this->expectException(UnsupportedJsonSchemaException::class);
        $adapter->schemaFor($this->schemaContext('scalar', JsonSchemaContext::ROLE_INPUT));
    }

    public function testWrapsValinorMappingFailuresAsJsonMappingExceptions(): void
    {
        $adapter = new ValinorJsonPayloadAdapter($this->mapper(), $this->normalizer());

        try {
            $adapter->hydrate(['user_name' => ['not' => 'a string']], $this->inputContext('create'));
            self::fail('Expected Valinor mapping failure to be wrapped.');
        } catch (JsonMappingException $e) {
            self::assertStringStartsWith('Invalid JSON value at $:', $e->getMessage());
        }
    }

    public function testServiceRegistryUsesValinorAdapterForJsonPayloads(): void
    {
        $adapter = new ValinorJsonPayloadAdapter($this->mapper(), $this->normalizer());
        $registry = new ServiceRegistry(
            [
                Service::new('Users')->handler(
                    'create',
                    static fn (Context $ctx, ValinorJsonPayloadCreateUser $input): ValinorJsonPayloadCreatedUser => new ValinorJsonPayloadCreatedUser('user-' . strtolower($input->name)),
                ),
            ],
            JsonPayloadOptions::new()->withPayloadAdapter($adapter),
        );

        self::assertSame(
            ['user_id' => 'user-ada'],
            json_decode($registry->invoke('Users', 'create', '{"user_name":"Ada"}', $this->context()), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testDiscoveryUsesConservativeValinorSchemaProviderAndPreservesExplicitSchemas(): void
    {
        $adapter = new ValinorJsonPayloadAdapter($this->mapper(), $this->normalizer());
        $unknownRegistry = new ServiceRegistry(
            [Service::new('Users')->handler('create', static fn (Context $ctx, ValinorJsonPayloadCreateUser $input): ValinorJsonPayloadCreatedUser => new ValinorJsonPayloadCreatedUser('user-1'))],
        );
        $explicitSchema = ['type' => 'object', 'required' => ['user_name']];
        $explicitRegistry = new ServiceRegistry(
            [
                Service::new('Users')->handler(
                    'create',
                    static fn (Context $ctx, ValinorJsonPayloadCreateUser $input): ValinorJsonPayloadCreatedUser => new ValinorJsonPayloadCreatedUser('user-1'),
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

    private function mapper(): TreeMapper
    {
        return new MapperBuilder()
            ->registerKeyConverter(static fn (string $key): string => str_starts_with($key, 'user_') ? substr($key, 5) : $key)
            ->mapper();
    }

    /** @return Normalizer<mixed> */
    private function normalizer(): Normalizer
    {
        return new NormalizerBuilder()->normalizer(Format::array());
    }

    private function context(): InvocationAttempt
    {
        return new InvocationAttempt(ScriptedInvocation::unused());
    }

    private function inputContext(string $method): JsonValueMappingContext
    {
        $reflection = new ReflectionMethod(ValinorJsonPayloadFixture::class, $method);

        return new JsonValueMappingContext(
            $reflection->getParameters()[1]->getType(),
            JsonValueMappingContext::ROLE_INPUT,
            'Users',
            $method,
        );
    }

    private function outputContext(string $method): JsonValueMappingContext
    {
        $reflection = new ReflectionMethod(ValinorJsonPayloadFixture::class, $method);

        return new JsonValueMappingContext(
            $reflection->getReturnType(),
            JsonValueMappingContext::ROLE_OUTPUT,
            'Users',
            $method,
        );
    }

    private function schemaContext(string $method, string $role): JsonSchemaContext
    {
        $reflection = new ReflectionMethod(ValinorJsonPayloadFixture::class, $method);
        $type = $role === JsonSchemaContext::ROLE_INPUT
            ? $reflection->getParameters()[1]->getType()
            : $reflection->getReturnType();

        return new JsonSchemaContext($type, $role, 'Users', $method);
    }
}
