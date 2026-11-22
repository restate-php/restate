<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use Restate\Context;
use Restate\Endpoint;
use Restate\FallbackJsonSchemaProvider;
use Restate\Internal\Backend;
use Restate\Internal\Backend\Invocation;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\Backend\ResponseHead;
use Restate\JsonMappingException;
use Restate\JsonPayloadOptions;
use Restate\JsonSchemaContext;
use Restate\JsonSchemaMetadata;
use Restate\JsonSchemaProvider;
use Restate\JsonValueMapper;
use Restate\JsonValueMappingContext;
use Restate\Payload;
use Restate\Service;
use Restate\Tests\Support\FakeBackend;
use Restate\UnsupportedJsonSchemaException;

final class EndpointJsonPayloadConfigurationTest extends TestCase
{
    public function testEndpointDiscoveryAndInvocationUseRegistryJsonPayloadConfiguration(): void
    {
        $adapter = new EndpointJsonPayloadConfigurationAdapter();
        $endpoint = $this->endpoint(JsonPayloadOptions::new()->withPayloadAdapter($adapter));

        $handler = $this->discoverHandler($endpoint);
        $output = $this->invoke($endpoint);

        self::assertSame('adapter input schema', $handler['input']['jsonSchema']['description']);
        self::assertSame('adapter output schema', $handler['output']['jsonSchema']['description']);
        self::assertSame('adapter', $output['mapped_by']);
        self::assertSame('user-ada', $output['id']);
        self::assertSame(['input:Users/create', 'output:Users/create'], $adapter->schemaCalls);
        self::assertSame(['input:Users/create'], $adapter->hydrateCalls);
        self::assertSame(['output:Users/create'], $adapter->normalizeCalls);
    }

    public function testEndpointDiscoveryPreservesExplicitJsonSchemaBeforeConfiguredProvider(): void
    {
        $adapter = new EndpointJsonPayloadConfigurationAdapter();
        $inputSchema = ['type' => 'object', 'description' => 'explicit input schema'];
        $outputSchema = ['type' => 'object', 'description' => 'explicit output schema'];
        $endpoint = $this->endpoint(
            JsonPayloadOptions::new()->withPayloadAdapter($adapter),
            Payload::json($inputSchema),
            Payload::json($outputSchema),
        );

        $handler = $this->discoverHandler($endpoint);
        $output = $this->invoke($endpoint);

        self::assertSame($inputSchema, $handler['input']['jsonSchema']);
        self::assertSame($outputSchema, $handler['output']['jsonSchema']);
        self::assertSame('adapter', $output['mapped_by']);
        self::assertSame([], $adapter->schemaCalls);
        self::assertSame(['input:Users/create'], $adapter->hydrateCalls);
        self::assertSame(['output:Users/create'], $adapter->normalizeCalls);
    }

    public function testEndpointDiscoveryUsesRichProviderBeforeConservativeAdapterWhileRuntimeUsesAdapter(): void
    {
        $adapter = new EndpointJsonPayloadConfigurationAdapter();
        $richProvider = new EndpointJsonPayloadConfigurationRichSchemaProvider();
        $endpoint = $this->endpoint(
            JsonPayloadOptions::new()
                ->withValueMapper($adapter)
                ->withSchemaProvider(new FallbackJsonSchemaProvider($richProvider, $adapter)),
        );

        $handler = $this->discoverHandler($endpoint);
        $output = $this->invoke($endpoint);

        self::assertSame('rich input schema', $handler['input']['jsonSchema']['description']);
        self::assertSame('rich output schema', $handler['output']['jsonSchema']['description']);
        self::assertSame('adapter', $output['mapped_by']);
        self::assertSame(['input:Users/create', 'output:Users/create'], $richProvider->schemaCalls);
        self::assertSame([], $adapter->schemaCalls);
        self::assertSame(['input:Users/create'], $adapter->hydrateCalls);
        self::assertSame(['output:Users/create'], $adapter->normalizeCalls);
    }

    private function endpoint(JsonPayloadOptions $jsonPayloads, ?Payload $input = null, ?Payload $output = null): Endpoint
    {
        return Endpoint::create(
            $this->backendWithInput('{"name":"Ada"}'),
            [
                Service::new('Users')->handler(
                    'create',
                    static fn (Context $ctx, EndpointJsonPayloadConfigurationCreateUser $input): EndpointJsonPayloadConfigurationCreatedUser => new EndpointJsonPayloadConfigurationCreatedUser(
                        'user-' . strtolower($input->name),
                        $input->name,
                    ),
                    $input,
                    $output,
                ),
            ],
            jsonPayloads: $jsonPayloads,
        );
    }

    private function backendWithInput(string $input): Backend
    {
        $chunks = [];
        $invocation = self::createStub(Invocation::class);
        $invocation->method('responseHead')->willReturn(new ResponseHead(200, []));
        $invocation->method('isReadyToExecute')->willReturn(true);
        $invocation->method('input')->willReturn(new InvocationInput('inv-1', 0, '', [], $input));
        $invocation->method('writeOutputSuccess')->willReturnCallback(static function (string $bytes) use (&$chunks): void {
            $chunks[] = $bytes;
        });
        $invocation->method('takeOutput')->willReturnCallback(static function () use (&$chunks): ?string {
            return array_shift($chunks);
        });

        return new FakeBackend($invocation);
    }

    /** @return array<string, mixed> */
    private function discoverHandler(Endpoint $endpoint): array
    {
        $response = $endpoint->handleRequest([
            'REQUEST_URI' => '/discover',
            'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v4+json',
        ], '');

        self::assertSame(200, $response->statusCode);
        $manifest = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        return $manifest['services'][0]['handlers'][0];
    }

    /** @return array<string, mixed> */
    private function invoke(Endpoint $endpoint): array
    {
        $response = $endpoint->handleRequest(['REQUEST_URI' => '/invoke/Users/create', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], '');

        self::assertSame(200, $response->statusCode);

        return json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
    }
}

final readonly class EndpointJsonPayloadConfigurationCreateUser
{
    public function __construct(public string $name)
    {
    }
}

final readonly class EndpointJsonPayloadConfigurationCreatedUser
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}

final class EndpointJsonPayloadConfigurationAdapter implements JsonValueMapper, JsonSchemaProvider
{
    /** @var list<string> */
    public array $schemaCalls = [];

    /** @var list<string> */
    public array $hydrateCalls = [];

    /** @var list<string> */
    public array $normalizeCalls = [];

    #[\Override]
    public function supports(JsonValueMappingContext $context): bool
    {
        return match ($context->role) {
            JsonValueMappingContext::ROLE_INPUT => $this->typeName($context) === EndpointJsonPayloadConfigurationCreateUser::class,
            JsonValueMappingContext::ROLE_OUTPUT => $this->typeName($context) === EndpointJsonPayloadConfigurationCreatedUser::class,
            default => false,
        };
    }

    #[\Override]
    public function hydrate(mixed $value, JsonValueMappingContext $context): mixed
    {
        $this->hydrateCalls[] = $this->callKey($context);
        if (!is_array($value) || !isset($value['name']) || !is_string($value['name'])) {
            throw JsonMappingException::at($context->path, 'expected object with string name');
        }

        return new EndpointJsonPayloadConfigurationCreateUser($value['name']);
    }

    #[\Override]
    public function normalize(mixed $value, JsonValueMappingContext $context): mixed
    {
        $this->normalizeCalls[] = $this->callKey($context);
        if (!$value instanceof EndpointJsonPayloadConfigurationCreatedUser) {
            throw JsonMappingException::at($context->path, 'expected created user DTO');
        }

        return [
            'mapped_by' => 'adapter',
            'id' => $value->id,
            'name' => $value->name,
        ];
    }

    #[\Override]
    public function schemaFor(JsonSchemaContext $context): JsonSchemaMetadata
    {
        $this->schemaCalls[] = $this->schemaCallKey($context);

        return match ($context->role) {
            JsonSchemaContext::ROLE_INPUT => JsonSchemaMetadata::fromArray([
                'type' => 'object',
                'description' => 'adapter input schema',
                'properties' => ['name' => ['type' => 'string']],
                'x-source' => 'adapter',
            ]),
            JsonSchemaContext::ROLE_OUTPUT => JsonSchemaMetadata::fromArray([
                'type' => 'object',
                'description' => 'adapter output schema',
                'properties' => [
                    'mapped_by' => ['type' => 'string'],
                    'id' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                ],
                'x-source' => 'adapter',
            ]),
            default => throw UnsupportedJsonSchemaException::forContext($context),
        };
    }

    private function typeName(JsonValueMappingContext $context): ?string
    {
        return $context->type instanceof ReflectionNamedType ? $context->type->getName() : null;
    }

    private function callKey(JsonValueMappingContext $context): string
    {
        return "{$context->role}:{$context->serviceName}/{$context->handlerName}";
    }

    private function schemaCallKey(JsonSchemaContext $context): string
    {
        return "{$context->role}:{$context->serviceName}/{$context->handlerName}";
    }
}

final class EndpointJsonPayloadConfigurationRichSchemaProvider implements JsonSchemaProvider
{
    /** @var list<string> */
    public array $schemaCalls = [];

    #[\Override]
    public function schemaFor(JsonSchemaContext $context): JsonSchemaMetadata
    {
        $this->schemaCalls[] = "{$context->role}:{$context->serviceName}/{$context->handlerName}";
        if ($context->serviceName !== 'Users' || $context->handlerName !== 'create') {
            throw UnsupportedJsonSchemaException::forContext($context);
        }

        return match ($context->role) {
            JsonSchemaContext::ROLE_INPUT => JsonSchemaMetadata::fromArray([
                'type' => 'object',
                'description' => 'rich input schema',
                'x-source' => 'rich-provider',
            ]),
            JsonSchemaContext::ROLE_OUTPUT => JsonSchemaMetadata::fromArray([
                'type' => 'object',
                'description' => 'rich output schema',
                'x-source' => 'rich-provider',
            ]),
            default => throw UnsupportedJsonSchemaException::forContext($context),
        };
    }
}
