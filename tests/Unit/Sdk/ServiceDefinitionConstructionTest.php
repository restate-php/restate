<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\Attributes as Restate;
use Restate\Context;
use Restate\Internal\Discovery;
use Restate\HandlerDefinition;
use Restate\InvocationConfiguration;
use Restate\InvocationRetryPolicy;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Payload;
use Restate\Reflection\ServiceReflector;
use Restate\Service;
use Restate\ServiceDefinition;
use Restate\Internal\ServiceRegistry;
use Restate\VirtualObject;
use Restate\Workflow;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;
use Restate\Tests\Support\FakeProtocolVersions;

#[Restate\Service(
    'ConstructionGreeter',
    documentation: 'Greeter service.',
    metadata: ['domain' => 'examples'],
    invocationRetryPolicy: new InvocationRetryPolicy(initialIntervalMillis: 100),
    invocationConfiguration: new InvocationConfiguration(inactivityTimeoutMillis: 1_000),
)]
final class ConstructionGreeterFixture
{
    #[Restate\Handler(
        'greet',
        documentation: 'Greets a person.',
        metadata: ['owner' => 'sdk'],
        invocationRetryPolicy: new InvocationRetryPolicy(maxAttempts: 3),
        invocationConfiguration: new InvocationConfiguration(abortTimeoutMillis: 2_000),
    )]
    #[Restate\Input(schema: ['type' => 'string'], required: true)]
    #[Restate\Output(schema: ['type' => 'string'])]
    public function greet(Context $ctx, string $name): string
    {
        return "Hello {$name}";
    }
}

#[Restate\VirtualObject(
    'ConstructionCounter',
    documentation: 'Counter object.',
    metadata: ['domain' => 'state'],
    invocationConfiguration: new InvocationConfiguration(inactivityTimeoutMillis: 3_000),
)]
final class ConstructionCounterFixture
{
    #[Restate\Handler('add', documentation: 'Adds value.', metadata: ['mode' => 'write'])]
    #[Restate\Input(schema: ['type' => 'integer'])]
    #[Restate\Output(schema: ['type' => 'integer'])]
    public function add(ObjectContext $ctx, int $amount): int
    {
        return $amount;
    }

    #[Restate\Handler('get', documentation: 'Reads value.', metadata: ['mode' => 'read'])]
    #[Restate\Shared]
    #[Restate\Output(schema: ['type' => 'integer'])]
    public function get(ObjectSharedContext $ctx): int
    {
        return 1;
    }
}

#[Restate\Workflow(
    'ConstructionCheckout',
    documentation: 'Checkout workflow.',
    metadata: ['domain' => 'orders'],
)]
final class ConstructionCheckoutFixture
{
    #[Restate\Handler(documentation: 'Starts checkout.', metadata: ['mode' => 'run'])]
    #[Restate\Input(schema: ['type' => 'string'])]
    #[Restate\Output(schema: ['type' => 'string'])]
    public function run(WorkflowContext $ctx, string $orderId): string
    {
        return $orderId;
    }

    #[Restate\Handler('status', documentation: 'Reads checkout status.', metadata: ['mode' => 'shared'])]
    #[Restate\Shared]
    #[Restate\Input(schema: ['type' => 'string'])]
    #[Restate\Output(schema: ['type' => 'string'])]
    public function status(WorkflowSharedContext $ctx, string $orderId): string
    {
        return "status {$orderId}";
    }
}

#[Restate\Workflow('ConstructionRenamedRun')]
final class ConstructionRenamedRunFixture
{
    #[Restate\Handler('run')]
    #[Restate\Input(schema: ['type' => 'string'])]
    #[Restate\Output(schema: ['type' => 'string'])]
    public function execute(WorkflowContext $ctx, string $orderId): string
    {
        return $orderId;
    }
}

final class ServiceDefinitionConstructionTest extends TestCase
{
    public function testBuilderAndReflectionAdaptersProduceEquivalentServiceDefinitions(): void
    {
        self::assertSame(
            self::definitionMetadata(self::builderService()),
            self::definitionMetadata(ServiceReflector::fromObject(new ConstructionGreeterFixture())),
        );

        self::assertSame(
            self::definitionMetadata(self::builderVirtualObject()),
            self::definitionMetadata(ServiceReflector::fromObject(new ConstructionCounterFixture())),
        );

        self::assertSame(
            self::definitionMetadata(self::builderWorkflow()),
            self::definitionMetadata(ServiceReflector::fromObject(new ConstructionCheckoutFixture())),
        );
    }

    public function testExplicitReflectedHandlerNamePreservesWorkflowRunIdentity(): void
    {
        $builder = Workflow::new('ConstructionRenamedRun')
            ->run(
                static fn (WorkflowContext $ctx, string $orderId): string => $orderId,
                Payload::json(['type' => 'string']),
                Payload::json(['type' => 'string']),
            )
            ->definition();

        $reflected = ServiceReflector::fromObject(new ConstructionRenamedRunFixture());

        self::assertSame(self::definitionMetadata($builder), self::definitionMetadata($reflected));
        self::assertSame('run', $reflected->handlers[0]->name);
        self::assertSame(HandlerDefinition::TYPE_WORKFLOW, $reflected->handlers[0]->type);
    }

    public function testDiscoveryConsumesEquivalentCanonicalDefinitionsFromBothAdapters(): void
    {
        $builderManifest = json_decode(Discovery::manifestJson(new ServiceRegistry([
            self::builderService(),
            self::builderVirtualObject(),
            self::builderWorkflow(),
        ]), FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);

        $reflectedManifest = json_decode(Discovery::manifestJson(new ServiceRegistry([
            ServiceReflector::fromObject(new ConstructionGreeterFixture()),
            ServiceReflector::fromObject(new ConstructionCounterFixture()),
            ServiceReflector::fromObject(new ConstructionCheckoutFixture()),
        ]), FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($builderManifest, $reflectedManifest);
    }

    public function testReflectionAdapterHasNoDefaultSerdeInterface(): void
    {
        self::assertNull(ServiceReflector::fromObject(new ConstructionGreeterFixture())->defaultSerde);
    }

    private static function builderService(): ServiceDefinition
    {
        return Service::new(
            'ConstructionGreeter',
            documentation: 'Greeter service.',
            metadata: ['domain' => 'examples'],
            invocationRetryPolicy: new InvocationRetryPolicy(initialIntervalMillis: 100),
            invocationConfiguration: new InvocationConfiguration(inactivityTimeoutMillis: 1_000),
        )
            ->handler(
                'greet',
                static fn (Context $ctx, string $name): string => "Hello {$name}",
                Payload::json(['type' => 'string'], required: true),
                Payload::json(['type' => 'string']),
                documentation: 'Greets a person.',
                metadata: ['owner' => 'sdk'],
                invocationRetryPolicy: new InvocationRetryPolicy(maxAttempts: 3),
                invocationConfiguration: new InvocationConfiguration(abortTimeoutMillis: 2_000),
            )
            ->definition();
    }

    private static function builderVirtualObject(): ServiceDefinition
    {
        return VirtualObject::new(
            'ConstructionCounter',
            documentation: 'Counter object.',
            metadata: ['domain' => 'state'],
            invocationConfiguration: new InvocationConfiguration(inactivityTimeoutMillis: 3_000),
        )
            ->handler(
                'add',
                static fn (ObjectContext $ctx, int $amount): int => $amount,
                Payload::json(['type' => 'integer']),
                Payload::json(['type' => 'integer']),
                documentation: 'Adds value.',
                metadata: ['mode' => 'write'],
            )
            ->sharedHandler(
                'get',
                static fn (ObjectSharedContext $ctx): int => 1,
                output: Payload::json(['type' => 'integer']),
                documentation: 'Reads value.',
                metadata: ['mode' => 'read'],
            )
            ->definition();
    }

    private static function builderWorkflow(): ServiceDefinition
    {
        return Workflow::new(
            'ConstructionCheckout',
            documentation: 'Checkout workflow.',
            metadata: ['domain' => 'orders'],
        )
            ->run(
                static fn (WorkflowContext $ctx, string $orderId): string => $orderId,
                Payload::json(['type' => 'string']),
                Payload::json(['type' => 'string']),
                documentation: 'Starts checkout.',
                metadata: ['mode' => 'run'],
            )
            ->sharedHandler(
                'status',
                static fn (WorkflowSharedContext $ctx, string $orderId): string => "status {$orderId}",
                Payload::json(['type' => 'string']),
                Payload::json(['type' => 'string']),
                documentation: 'Reads checkout status.',
                metadata: ['mode' => 'shared'],
            )
            ->definition();
    }

    /** @return array<string, mixed> */
    private static function definitionMetadata(ServiceDefinition $definition): array
    {
        return [
            'name' => $definition->name,
            'type' => $definition->type,
            'documentation' => $definition->documentation,
            'metadata' => $definition->metadata,
            'invocationRetryPolicy' => $definition->invocationRetryPolicy?->manifestFields(),
            'invocationConfiguration' => $definition->invocationConfiguration?->manifestFields(),
            'defaultSerde' => $definition->defaultSerde?->contentType(),
            'handlers' => array_map(
                static fn (HandlerDefinition $handler): array => self::handlerMetadata($handler),
                $definition->handlers,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private static function handlerMetadata(HandlerDefinition $handler): array
    {
        return [
            'name' => $handler->name,
            'type' => $handler->type,
            'documentation' => $handler->documentation,
            'metadata' => $handler->metadata,
            'invocationRetryPolicy' => $handler->invocationRetryPolicy?->manifestFields(),
            'invocationConfiguration' => $handler->invocationConfiguration?->manifestFields(),
            'input' => $handler->inputPayload->inputManifest(),
            'output' => $handler->outputPayload->outputManifest(),
            'inputType' => self::typeName($handler->inputType),
            'outputType' => self::typeName($handler->outputType),
            'inputPayloadExplicit' => $handler->inputPayloadExplicit,
            'outputPayloadExplicit' => $handler->outputPayloadExplicit,
        ];
    }

    private static function typeName(?\ReflectionType $type): ?string
    {
        return $type?->__toString();
    }
}
