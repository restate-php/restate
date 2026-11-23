<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Attributes\Handler;
use Restate\Attributes\Input;
use Restate\Attributes\Output as OutputAttribute;
use Restate\Attributes\Service as ServiceAttribute;
use Restate\Attributes\Shared;
use Restate\Attributes\VirtualObject as VirtualObjectAttribute;
use Restate\Attributes\Workflow as WorkflowAttribute;
use Restate\BuiltinJsonSchemaProvider;
use Restate\Context;
use Restate\Internal\Discovery;
use Restate\FallbackJsonSchemaProvider;
use Restate\InvocationConfiguration;
use Restate\InvocationRetryPolicy;
use Restate\JsonPayloadOptions;
use Restate\JsonSchemaContext;
use Restate\JsonSchemaMetadata;
use Restate\JsonSchemaProvider;
use Restate\ObjectContext;
use Restate\Payload;
use Restate\Reflection\ServiceReflector;
use Restate\Serde;
use Restate\Service;
use Restate\Internal\ServiceRegistry;
use Restate\Tests\Support\FakeBackend;
use Restate\Tests\Support\FakeProtocolVersions;
use Restate\UnsupportedJsonSchemaException;
use Restate\VirtualObject;
use Restate\Workflow;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;
use Restate\PayloadRole;

final class DiscoveryTestCustomSerde implements Serde
{
    public function serialize(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function deserialize(string $bytes): mixed
    {
        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    }

    public function contentType(): string
    {
        return 'application/x-discovery-serde';
    }
}

final class DiscoveryTestCustomJsonSerde implements Serde
{
    public function serialize(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function deserialize(string $bytes): mixed
    {
        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    }

    public function contentType(): string
    {
        return 'application/json';
    }
}

final class DiscoveryTestSchemaProvider implements JsonSchemaProvider
{
    public function schemaFor(JsonSchemaContext $context): JsonSchemaMetadata
    {
        if ($context->role === PayloadRole::Input && $context->serviceName === 'Greeter' && $context->handlerName === 'greet') {
            return JsonSchemaMetadata::fromArray(['type' => 'string', 'description' => 'custom input schema']);
        }

        if ($context->role === PayloadRole::Output && $context->serviceName === 'Greeter' && $context->handlerName === 'greet') {
            return JsonSchemaMetadata::fromArray(['type' => 'string', 'description' => 'custom output schema']);
        }

        throw UnsupportedJsonSchemaException::forContext($context);
    }
}

final class DiscoveryTestConfigurableSchemaProvider implements JsonSchemaProvider
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly ?JsonSchemaMetadata $schema)
    {
    }

    public function schemaFor(JsonSchemaContext $context): JsonSchemaMetadata
    {
        $this->calls[] = "{$context->role->value}:{$context->serviceName}/{$context->handlerName}";

        return $this->schema ?? throw UnsupportedJsonSchemaException::forContext($context);
    }
}

final readonly class DiscoveryTestUserDto
{
    public function __construct(public string $name)
    {
    }
}

final readonly class DiscoveryTestUnsupportedArrayDto
{
    /** @param list<string> $items */
    public function __construct(public array $items)
    {
    }
}

#[ServiceAttribute('ReflectedGreeter')]
final class DiscoveryReflectedGreeterFixture
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    #[Handler('schemaEcho')]
    #[Input(
        schema: ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
        required: true,
        accept: 'application/*+json, application/json',
    )]
    #[OutputAttribute(schema: ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]], setContentTypeIfEmpty: true)]
    public function schemaEcho(Context $ctx, array $payload): array
    {
        return $payload;
    }
}

#[VirtualObjectAttribute('ReflectedFiles')]
final class DiscoveryReflectedFilesFixture
{
    #[Handler('upload')]
    #[Input(contentType: 'application/octet-stream', json: false)]
    #[OutputAttribute(empty: true)]
    public function upload(ObjectContext $ctx, string $bytes): null
    {
        return null;
    }
}

#[WorkflowAttribute('ReflectedCheckout')]
final class DiscoveryReflectedWorkflowFixture
{
    /** @param array<string, mixed> $order */
    #[Handler]
    #[Input(schema: ['type' => 'object'], required: true)]
    public function run(WorkflowContext $ctx, array $order): string
    {
        return 'started';
    }

    #[Handler('approve')]
    #[Shared]
    #[OutputAttribute(empty: true)]
    public function approve(WorkflowSharedContext $ctx): null
    {
        return null;
    }
}

#[ServiceAttribute('ReflectedConfigured')]
final class DiscoveryReflectedInvocationConfigurationFixture
{
    #[Handler('greet', invocationConfiguration: new InvocationConfiguration(inactivityTimeoutMillis: 250, abortTimeoutMillis: 1000, journalRetentionMillis: 5000, idempotencyRetentionMillis: 7000, ingressPrivate: true))]
    public function greet(Context $ctx, string $name): string
    {
        return "Hello {$name}";
    }
}

final class DiscoveryTest extends TestCase
{
    public function testManifestAdvertisesRegisteredRequestResponseService(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter')
                ->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}")
                ->handler('greetWithRun', static fn (Context $ctx, string $name): string => "Hello {$name} from run"),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('REQUEST_RESPONSE', $manifest['protocolMode']);
        self::assertSame(5, $manifest['minProtocolVersion']);
        self::assertSame(8, $manifest['maxProtocolVersion']);
        self::assertSame('Greeter', $manifest['services'][0]['name']);
        self::assertSame('SERVICE', $manifest['services'][0]['ty']);
        self::assertSame('greet', $manifest['services'][0]['handlers'][0]['name']);
        self::assertArrayNotHasKey('ty', $manifest['services'][0]['handlers'][0]);
        self::assertSame('greetWithRun', $manifest['services'][0]['handlers'][1]['name']);
        self::assertSame('application/json', $manifest['services'][0]['handlers'][0]['input']['contentType']);
        self::assertSame('application/json', $manifest['services'][0]['handlers'][0]['output']['contentType']);
        self::assertSame(
            ['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']],
            $manifest['services'][0]['handlers'][0]['input'],
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']],
            $manifest['services'][0]['handlers'][0]['output'],
        );
    }

    public function testManifestIncludesDocumentationAndMetadataForSupportedVersions(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter', documentation: 'Greeter service.', metadata: ['domain' => 'examples'])
                ->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}", documentation: 'Greets a person.', metadata: ['owner' => 'sdk']),
        ]);

        foreach ([3, 4, 5] as $version) {
            $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, $version), true, flags: JSON_THROW_ON_ERROR);
            $service = $manifest['services'][0];
            $handler = $service['handlers'][0];

            self::assertSame('Greeter service.', $service['documentation']);
            self::assertSame(['domain' => 'examples'], $service['metadata']);
            self::assertSame('Greets a person.', $handler['documentation']);
            self::assertSame(['owner' => 'sdk'], $handler['metadata']);
        }
    }

    public function testManifestOmitsDocumentationAndMetadataForVersionTwo(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter', documentation: 'Greeter service.', metadata: ['domain' => 'examples'])
                ->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}", documentation: 'Greets a person.', metadata: ['owner' => 'sdk']),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 2), true, flags: JSON_THROW_ON_ERROR);
        $service = $manifest['services'][0];
        $handler = $service['handlers'][0];

        self::assertArrayNotHasKey('documentation', $service);
        self::assertArrayNotHasKey('metadata', $service);
        self::assertArrayNotHasKey('documentation', $handler);
        self::assertArrayNotHasKey('metadata', $handler);
    }

    public function testManifestIncludesInvocationRetryPolicyForVersionFour(): void
    {
        $registry = new ServiceRegistry([
            Service::new(
                'Greeter',
                invocationRetryPolicy: new InvocationRetryPolicy(
                    initialIntervalMillis: 100,
                    exponentiationFactor: 2.5,
                    maxIntervalMillis: 5000,
                    maxAttempts: 3,
                    onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_KILL,
                ),
            )->handler(
                'greet',
                static fn (Context $ctx, string $name): string => "Hello {$name}",
                invocationRetryPolicy: new InvocationRetryPolicy(
                    initialIntervalMillis: 50,
                    maxAttempts: 2,
                    onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_PAUSE,
                ),
            ),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 4), true, flags: JSON_THROW_ON_ERROR);
        $service = $manifest['services'][0];
        $handler = $service['handlers'][0];

        self::assertSame(100, $service['retryPolicyInitialInterval']);
        self::assertSame(5000, $service['retryPolicyMaxInterval']);
        self::assertSame(3, $service['retryPolicyMaxAttempts']);
        self::assertSame(2.5, $service['retryPolicyExponentiationFactor']);
        self::assertSame('KILL', $service['retryPolicyOnMaxAttempts']);

        self::assertSame(50, $handler['retryPolicyInitialInterval']);
        self::assertArrayNotHasKey('retryPolicyMaxInterval', $handler);
        self::assertSame(2, $handler['retryPolicyMaxAttempts']);
        self::assertArrayNotHasKey('retryPolicyExponentiationFactor', $handler);
        self::assertSame('PAUSE', $handler['retryPolicyOnMaxAttempts']);
    }

    public function testManifestRejectsServiceInvocationRetryPolicyBeforeVersionFour(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter', invocationRetryPolicy: new InvocationRetryPolicy(initialIntervalMillis: 100)),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service Greeter invocation retry policy requires discovery manifest version 4.');

        Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 3);
    }

    public function testManifestRejectsHandlerInvocationRetryPolicyBeforeVersionFour(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler(
                'greet',
                static fn (Context $ctx, string $name): string => "Hello {$name}",
                invocationRetryPolicy: new InvocationRetryPolicy(maxAttempts: 3),
            ),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler Greeter/greet invocation retry policy requires discovery manifest version 4.');

        Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 3);
    }

    public function testManifestIncludesInvocationConfigurationForVersionThree(): void
    {
        $registry = new ServiceRegistry([
            Service::new(
                'Greeter',
                invocationConfiguration: new InvocationConfiguration(
                    inactivityTimeoutMillis: 1000,
                    abortTimeoutMillis: 2000,
                    journalRetentionMillis: 3000,
                    idempotencyRetentionMillis: 4000,
                    ingressPrivate: true,
                ),
            )->handler(
                'greet',
                static fn (Context $ctx, string $name): string => "Hello {$name}",
                invocationConfiguration: new InvocationConfiguration(
                    inactivityTimeoutMillis: 50,
                    abortTimeoutMillis: 100,
                    journalRetentionMillis: 150,
                    idempotencyRetentionMillis: 200,
                    ingressPrivate: false,
                ),
            ),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 3), true, flags: JSON_THROW_ON_ERROR);
        $service = $manifest['services'][0];
        $handler = $service['handlers'][0];

        self::assertSame(1000, $service['inactivityTimeout']);
        self::assertSame(2000, $service['abortTimeout']);
        self::assertSame(3000, $service['journalRetention']);
        self::assertSame(4000, $service['idempotencyRetention']);
        self::assertTrue($service['ingressPrivate']);
        self::assertSame(50, $handler['inactivityTimeout']);
        self::assertSame(100, $handler['abortTimeout']);
        self::assertSame(150, $handler['journalRetention']);
        self::assertSame(200, $handler['idempotencyRetention']);
        self::assertFalse($handler['ingressPrivate']);
    }

    public function testManifestIncludesReflectedInvocationConfiguration(): void
    {
        $registry = new ServiceRegistry([
            ServiceReflector::fromObject(new DiscoveryReflectedInvocationConfigurationFixture()),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 3), true, flags: JSON_THROW_ON_ERROR);
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(250, $handler['inactivityTimeout']);
        self::assertSame(1000, $handler['abortTimeout']);
        self::assertSame(5000, $handler['journalRetention']);
        self::assertSame(7000, $handler['idempotencyRetention']);
        self::assertTrue($handler['ingressPrivate']);
    }

    public function testManifestIncludesWorkflowCompletionRetentionForWorkflowHandler(): void
    {
        $registry = new ServiceRegistry([
            Workflow::new('Checkout')->run(
                static fn (WorkflowContext $ctx, array $order): string => 'started',
                invocationConfiguration: new InvocationConfiguration(workflowCompletionRetentionMillis: 86_400_000),
            ),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 3), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(86_400_000, $manifest['services'][0]['handlers'][0]['workflowCompletionRetention']);
    }

    public function testManifestRejectsInvocationConfigurationBeforeVersionThree(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter', invocationConfiguration: new InvocationConfiguration(inactivityTimeoutMillis: 1000)),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service Greeter invocation configuration requires discovery manifest version 3.');

        Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 2);
    }

    public function testManifestRejectsInvalidWorkflowRetentionScopes(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler(
                'greet',
                static fn (Context $ctx, string $name): string => "Hello {$name}",
                invocationConfiguration: new InvocationConfiguration(workflowCompletionRetentionMillis: 1000),
            ),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler Greeter/greet workflow completion retention is valid only for workflow handlers.');

        Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 3);
    }

    public function testManifestRejectsIdempotencyRetentionOnWorkflowHandler(): void
    {
        $registry = new ServiceRegistry([
            Workflow::new('Checkout')->run(
                static fn (WorkflowContext $ctx, array $order): string => 'started',
                invocationConfiguration: new InvocationConfiguration(idempotencyRetentionMillis: 1000),
            ),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler Checkout/run idempotency retention is not valid for workflow handlers.');

        Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 3);
    }

    public function testManifestJsonDefaultsToLatestVersion(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter', documentation: 'Greeter service.'),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Greeter service.', $manifest['services'][0]['documentation']);
    }

    public function testManifestAdvertisesTheProtocolVersionsItIsGiven(): void
    {
        $registry = new ServiceRegistry([Service::new('Greeter')]);

        $manifest = json_decode(Discovery::manifestJson($registry, ['min' => 6, 'max' => 7]), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(6, $manifest['minProtocolVersion']);
        self::assertSame(7, $manifest['maxProtocolVersion']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function acceptHeaders(): iterable
    {
        // Restate sends every manifest version it reads, oldest first, comma separated.
        yield 'Restate 1.8 (v1 to v5)' => [self::accept(1, 2, 3, 4, 5), 5];
        yield 'Restate 1.6 and 1.7 (v1 to v4)' => [self::accept(1, 2, 3, 4), 4];
        yield 'v3' => [self::accept(3), 3];
        yield 'v2' => [self::accept(2), 2];
        yield 'newest first' => [self::accept(5, 4, 3), 5];
        yield 'a version the SDK does not implement next to one it does' => [self::accept(2, 6), 2];
        yield 'media types are case-insensitive (RFC 9110)' => ['Application/Vnd.Restate.Endpointmanifest.V5+json', 5];
    }

    #[DataProvider('acceptHeaders')]
    public function testManifestVersionIsTheNewestTheServerAcceptsAndTheSdkImplements(string $accept, int $version): void
    {
        self::assertSame($version, Discovery::manifestVersion($accept));
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedAcceptHeaders(): iterable
    {
        yield 'no header' => [''];
        yield 'plain JSON' => ['application/json'];
        yield 'v1 only' => [self::accept(1)];
        yield 'only newer versions' => [self::accept(6)];
    }

    #[DataProvider('unsupportedAcceptHeaders')]
    public function testManifestVersionIsNullWhenTheSdkImplementsNoneTheServerAccepts(string $accept): void
    {
        self::assertNull(Discovery::manifestVersion($accept));
    }

    public function testContentTypeNamesTheManifestVersion(): void
    {
        self::assertSame('application/vnd.restate.endpointmanifest.v5+json', Discovery::contentType(5));
        self::assertSame('application/vnd.restate.endpointmanifest.v2+json', Discovery::contentType(2));
    }

    public function testManifestV5IsTheV4ManifestUntilTheSdkUsesItsOptionalFields(): void
    {
        $registry = new ServiceRegistry([
            Service::new(
                'Greeter',
                documentation: 'Greeter service.',
                metadata: ['domain' => 'examples'],
                invocationRetryPolicy: new InvocationRetryPolicy(initialIntervalMillis: 100, maxAttempts: 3),
            )->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}", documentation: 'Greets a person.'),
            VirtualObject::new('Counter')->handler('get', static fn (ObjectContext $ctx): int => 0),
        ]);

        self::assertSame(
            Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 4),
            Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 5),
        );
        self::assertSame(
            Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 5),
            Discovery::manifestJson($registry, FakeProtocolVersions::RANGE),
        );
    }

    /** @return iterable<string, array{int}> */
    public static function unimplementedManifestVersions(): iterable
    {
        yield 'v1' => [1];
        yield 'v6' => [6];
    }

    #[DataProvider('unimplementedManifestVersions')]
    public function testManifestVersionTheSdkDoesNotImplementIsRejected(int $version): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Discovery manifest version {$version} is not supported; this SDK implements versions 2 to 5.");

        Discovery::manifestJson(new ServiceRegistry([Service::new('Greeter')]), FakeProtocolVersions::RANGE, $version);
    }

    private static function accept(int ...$versions): string
    {
        return implode(', ', array_map(static fn (int $version): string => "application/vnd.restate.endpointmanifest.v{$version}+json", $versions));
    }

    public function testManifestAdvertisesVirtualObjectHandlersAndJsonPayloadMetadata(): void
    {
        $registry = new ServiceRegistry([
            VirtualObject::new('Counter')
                ->handler('increment', static fn (Context $ctx, int $amount): int => $amount + 1)
                ->sharedHandler('get', static fn (Context $ctx): int => 1),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Counter', $manifest['services'][0]['name']);
        self::assertSame('VIRTUAL_OBJECT', $manifest['services'][0]['ty']);

        self::assertSame([
            'name' => 'increment',
            'ty' => 'EXCLUSIVE',
            'input' => ['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'integer']],
            'output' => ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'integer']],
        ], $manifest['services'][0]['handlers'][0]);
        self::assertSame([
            'name' => 'get',
            'ty' => 'SHARED',
            'input' => ['required' => false],
            'output' => ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'integer']],
        ], $manifest['services'][0]['handlers'][1]);
    }

    public function testManifestAdvertisesWorkflowAndHandlerTypes(): void
    {
        $registry = new ServiceRegistry([
            Workflow::new('Checkout')
                ->run(static fn (Context $ctx, string $orderId): string => "started {$orderId}")
                ->sharedHandler('approve', static fn (Context $ctx, string $orderId): string => "approved {$orderId}"),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Checkout', $manifest['services'][0]['name']);
        self::assertSame('WORKFLOW', $manifest['services'][0]['ty']);
        self::assertSame('run', $manifest['services'][0]['handlers'][0]['name']);
        self::assertSame('WORKFLOW', $manifest['services'][0]['handlers'][0]['ty']);
        self::assertSame('approve', $manifest['services'][0]['handlers'][1]['name']);
        self::assertSame('SHARED', $manifest['services'][0]['handlers'][1]['ty']);
        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']], $manifest['services'][0]['handlers'][0]['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']], $manifest['services'][0]['handlers'][0]['output']);
        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']], $manifest['services'][0]['handlers'][1]['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']], $manifest['services'][0]['handlers'][1]['output']);
    }

    public function testManifestIncludesReflectedWorkflowHandlersAndPayloadMetadata(): void
    {
        $registry = new ServiceRegistry([
            ServiceReflector::fromObject(new DiscoveryReflectedWorkflowFixture()),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);
        $service = $manifest['services'][0];

        self::assertSame('ReflectedCheckout', $service['name']);
        self::assertSame('WORKFLOW', $service['ty']);
        self::assertSame('run', $service['handlers'][0]['name']);
        self::assertSame('WORKFLOW', $service['handlers'][0]['ty']);
        self::assertSame(['required' => true, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'object']], $service['handlers'][0]['input']);
        self::assertSame('approve', $service['handlers'][1]['name']);
        self::assertSame('SHARED', $service['handlers'][1]['ty']);
        self::assertSame(['setContentTypeIfEmpty' => false], $service['handlers'][1]['output']);
    }

    public function testManifestIncludesExplicitJsonSchemaPayloads(): void
    {
        $schema = ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]];
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler(
                'greet',
                static fn (Context $ctx, array $input): array => $input,
                Payload::json($schema, required: true)->withAcceptContentType('application/*+json, application/json'),
                Payload::json($schema)->withSetContentTypeIfEmpty(),
            ),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(['required' => true, 'contentType' => 'application/*+json, application/json', 'jsonSchema' => $schema], $handler['input']);
        self::assertSame(['setContentTypeIfEmpty' => true, 'contentType' => 'application/json', 'jsonSchema' => $schema], $handler['output']);
    }

    public function testManifestIncludesCustomSerdePayloadMetadata(): void
    {
        $schema = ['type' => 'object', 'required' => ['name']];
        $registry = new ServiceRegistry([
            Service::new('Codec')->handler(
                'convert',
                static fn (Context $ctx, array $input): array => $input,
                Payload::custom(DiscoveryTestCustomSerde::class, required: true),
                Payload::custom(DiscoveryTestCustomJsonSerde::class, $schema),
            ),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(['required' => true, 'contentType' => 'application/x-discovery-serde'], $handler['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => $schema], $handler['output']);
    }

    public function testManifestIncludesBinaryAndEmptyPayloadMetadata(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Files')
                ->handler('upload', static fn (Context $ctx, string $bytes): null => null, Payload::binary('application/octet-stream'), Payload::empty()),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(['required' => false, 'contentType' => 'application/octet-stream'], $handler['input']);
        self::assertSame(['setContentTypeIfEmpty' => false], $handler['output']);
    }

    public function testManifestIncludesReflectedJsonSchemaPayloadAttributes(): void
    {
        $schema = ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]];
        $registry = new ServiceRegistry([
            ServiceReflector::fromObject(new DiscoveryReflectedGreeterFixture()),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(['required' => true, 'contentType' => 'application/*+json, application/json', 'jsonSchema' => $schema], $handler['input']);
        self::assertSame(['setContentTypeIfEmpty' => true, 'contentType' => 'application/json', 'jsonSchema' => $schema], $handler['output']);
    }

    public function testManifestIncludesReflectedBinaryAndEmptyPayloadAttributes(): void
    {
        $registry = new ServiceRegistry([
            ServiceReflector::fromObject(new DiscoveryReflectedFilesFixture()),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(['required' => false, 'contentType' => 'application/octet-stream'], $handler['input']);
        self::assertSame(['setContentTypeIfEmpty' => false], $handler['output']);
    }

    public function testManifestUsesConfiguredJsonSchemaProviderBeforeBuiltInInference(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}"),
        ]);

        $manifest = json_decode(
            Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, jsonPayloads: JsonPayloadOptions::new()->withSchemaProvider(new DiscoveryTestSchemaProvider())),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(
            ['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string', 'description' => 'custom input schema']],
            $handler['input'],
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string', 'description' => 'custom output schema']],
            $handler['output'],
        );
    }

    public function testManifestUsesConfiguredJsonSchemaProvidersInOrder(): void
    {
        $first = new DiscoveryTestConfigurableSchemaProvider(null);
        $second = new DiscoveryTestConfigurableSchemaProvider(JsonSchemaMetadata::fromArray(['type' => 'string', 'description' => 'second provider']));
        $third = new DiscoveryTestConfigurableSchemaProvider(JsonSchemaMetadata::fromArray(['type' => 'string', 'description' => 'third provider']));
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler('greet', static function (Context $ctx, string $name): void {
            }),
        ]);

        $manifest = json_decode(
            Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, jsonPayloads: JsonPayloadOptions::new()->withSchemaProvider(new FallbackJsonSchemaProvider($first, $second, $third))),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            ['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string', 'description' => 'second provider']],
            $manifest['services'][0]['handlers'][0]['input'],
        );
        self::assertSame(['input:Greeter/greet'], $first->calls);
        self::assertSame(['input:Greeter/greet'], $second->calls);
        self::assertSame([], $third->calls);
    }

    public function testUnknownJsonSchemaProviderResultStopsProviderChainAndBuiltInInference(): void
    {
        $unknown = new DiscoveryTestConfigurableSchemaProvider(JsonSchemaMetadata::unknown());
        $later = new DiscoveryTestConfigurableSchemaProvider(JsonSchemaMetadata::fromArray(['type' => 'string', 'description' => 'later provider']));
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler('greet', static function (Context $ctx, string $name): void {
            }),
        ]);

        $manifest = json_decode(
            Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, jsonPayloads: JsonPayloadOptions::new()->withSchemaProvider(new FallbackJsonSchemaProvider($unknown, $later))),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            ['required' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $manifest['services'][0]['handlers'][0]['input'],
        );
        self::assertSame(['input:Greeter/greet'], $unknown->calls);
        self::assertSame([], $later->calls);
    }

    public function testNoOpinionJsonSchemaProvidersFallBackToBuiltInInference(): void
    {
        $first = new DiscoveryTestConfigurableSchemaProvider(null);
        $second = new DiscoveryTestConfigurableSchemaProvider(null);
        $registry = new ServiceRegistry([
            Service::new('Greeter')
                ->handler('greet', static function (Context $ctx, string $name): void {
                })
                ->handler('create', static function (Context $ctx, DiscoveryTestUserDto $user): void {
                }),
        ]);

        $manifest = json_decode(
            Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, jsonPayloads: JsonPayloadOptions::new()->withSchemaProvider(new FallbackJsonSchemaProvider($first, $second, new BuiltinJsonSchemaProvider()))),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            ['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']],
            $manifest['services'][0]['handlers'][0]['input'],
        );
        self::assertSame(
            [
                'required' => false,
                'contentType' => 'application/json',
                'jsonSchema' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string']],
                    'required' => ['name'],
                ],
            ],
            $manifest['services'][0]['handlers'][1]['input'],
        );
        self::assertSame(['input:Greeter/greet', 'input:Greeter/create'], $first->calls);
        self::assertSame(['input:Greeter/greet', 'input:Greeter/create'], $second->calls);
    }

    public function testBuiltInDiscoveryPublishesUnknownSchemaForMappingUnsafeDto(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler(
                'echo',
                static fn (Context $ctx, DiscoveryTestUnsupportedArrayDto $input): DiscoveryTestUnsupportedArrayDto => $input,
            ),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $handler['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $handler['output']);
    }

    public function testCustomProviderBeforeBuiltInPublishesKnownSchemaForMappingUnsafeDto(): void
    {
        $schema = ['type' => 'object', 'description' => 'custom mapping-unsafe DTO schema'];
        $provider = new DiscoveryTestConfigurableSchemaProvider(JsonSchemaMetadata::fromArray($schema));
        $registry = new ServiceRegistry([
            Service::new('Users')->handler(
                'echo',
                static fn (Context $ctx, DiscoveryTestUnsupportedArrayDto $input): DiscoveryTestUnsupportedArrayDto => $input,
            ),
        ]);

        $manifest = json_decode(
            Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, jsonPayloads: JsonPayloadOptions::new()->withSchemaProvider(new FallbackJsonSchemaProvider($provider, new BuiltinJsonSchemaProvider()))),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => $schema], $handler['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => $schema], $handler['output']);
        self::assertSame(['input:Users/echo', 'output:Users/echo'], $provider->calls);
    }

    public function testBuiltInUnknownMappingUnsafeDtoSchemaStopsLaterFallbackProvider(): void
    {
        $later = new DiscoveryTestConfigurableSchemaProvider(JsonSchemaMetadata::fromArray(['type' => 'object', 'description' => 'later provider']));
        $registry = new ServiceRegistry([
            Service::new('Users')->handler(
                'echo',
                static fn (Context $ctx, DiscoveryTestUnsupportedArrayDto $input): DiscoveryTestUnsupportedArrayDto => $input,
            ),
        ]);

        $manifest = json_decode(
            Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, jsonPayloads: JsonPayloadOptions::new()->withSchemaProvider(new FallbackJsonSchemaProvider(new BuiltinJsonSchemaProvider(), $later))),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $handler['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $handler['output']);
        self::assertSame([], $later->calls);
    }

    public function testExplicitJsonSchemaBypassesConfiguredJsonSchemaProviders(): void
    {
        $explicitSchema = ['type' => 'object', 'required' => ['name']];
        $provider = new DiscoveryTestConfigurableSchemaProvider(JsonSchemaMetadata::fromArray(['type' => 'string', 'description' => 'configured provider']));
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler(
                'greet',
                static fn (Context $ctx, array $input): array => $input,
                Payload::json($explicitSchema, required: true),
                Payload::json($explicitSchema),
            ),
        ]);

        $manifest = json_decode(
            Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, jsonPayloads: JsonPayloadOptions::new()->withSchemaProvider($provider)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(['required' => true, 'contentType' => 'application/json', 'jsonSchema' => $explicitSchema], $handler['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => $explicitSchema], $handler['output']);
        self::assertSame([], $provider->calls);
    }

    public function testExplicitJsonSchemaPublishesKnownSchemaForMappingUnsafeDto(): void
    {
        $explicitSchema = ['type' => 'object', 'required' => ['items']];
        $provider = new DiscoveryTestConfigurableSchemaProvider(JsonSchemaMetadata::fromArray(['type' => 'string', 'description' => 'configured provider']));
        $registry = new ServiceRegistry([
            Service::new('Users')->handler(
                'echo',
                static fn (Context $ctx, DiscoveryTestUnsupportedArrayDto $input): DiscoveryTestUnsupportedArrayDto => $input,
                Payload::json($explicitSchema),
                Payload::json($explicitSchema),
            ),
        ]);

        $manifest = json_decode(
            Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, jsonPayloads: JsonPayloadOptions::new()->withSchemaProvider($provider)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => $explicitSchema], $handler['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => $explicitSchema], $handler['output']);
        self::assertSame([], $provider->calls);
    }

    public function testConfiguredJsonSchemaProviderCanScopeSchemasByServiceName(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Other')->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}"),
        ]);

        $manifest = json_decode(
            Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, jsonPayloads: JsonPayloadOptions::new()->withSchemaProvider(new FallbackJsonSchemaProvider(new DiscoveryTestSchemaProvider(), new BuiltinJsonSchemaProvider()))),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(
            ['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']],
            $handler['input'],
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']],
            $handler['output'],
        );
    }

    public function testEndpointDiscoveryUsesConfiguredJsonSchemaProvider(): void
    {
        $endpoint = \Restate\Endpoint::create(
            new FakeBackend(),
            [Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => $name)],
            jsonPayloads: JsonPayloadOptions::new()->withSchemaProvider(new DiscoveryTestSchemaProvider()),
        );

        $response = $endpoint->handleRequest([
            'REQUEST_URI' => '/discover',
            'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v4+json',
        ], '');

        $manifest = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(
            ['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string', 'description' => 'custom input schema']],
            $manifest['services'][0]['handlers'][0]['input'],
        );
    }

    public function testExplicitJsonSchemaOverridesConfiguredJsonSchemaProvider(): void
    {
        $schema = ['type' => 'object', 'required' => ['name']];
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler(
                'greet',
                static fn (Context $ctx, string $name): string => "Hello {$name}",
                Payload::json($schema, required: true),
                Payload::json($schema),
            ),
        ]);

        $manifest = json_decode(
            Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, jsonPayloads: JsonPayloadOptions::new()->withSchemaProvider(new DiscoveryTestSchemaProvider())),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $handler = $manifest['services'][0]['handlers'][0];

        self::assertSame(['required' => true, 'contentType' => 'application/json', 'jsonSchema' => $schema], $handler['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => $schema], $handler['output']);
    }
}
