<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\Attributes as Restate;
use Restate\Context;
use Restate\HandlerDefinition;
use Restate\InvocationRetryPolicy;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Reflection\ServiceReflector;
use Restate\ServiceDefinition;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

#[Restate\Service('MetaGreeter', documentation: 'Greeter service.', metadata: ['domain' => 'examples'], invocationRetryPolicy: new InvocationRetryPolicy(initialIntervalMillis: 100))]
final class ReflectorMetadataServiceFixture
{
    #[Restate\Handler('greet', documentation: 'Greets a person.', metadata: ['owner' => 'sdk'], invocationRetryPolicy: new InvocationRetryPolicy(maxAttempts: 3, onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_PAUSE))]
    public function greet(Context $ctx): void
    {
    }
}

#[Restate\VirtualObject('MetaCounter', documentation: 'Counter object.', metadata: ['domain' => 'state'])]
final class ReflectorMetadataObjectFixture
{
    #[Restate\Handler('add', documentation: 'Adds to the counter.', metadata: ['consistency' => 'exclusive'])]
    public function add(ObjectContext $ctx): void
    {
    }

    #[Restate\Handler('get', documentation: 'Reads the counter.', metadata: ['consistency' => 'shared'])]
    #[Restate\Shared]
    public function get(ObjectSharedContext $ctx): void
    {
    }
}

#[Restate\Workflow('MetaCheckout', documentation: 'Checkout workflow.', metadata: ['domain' => 'orders'])]
final class ReflectorMetadataWorkflowFixture
{
    #[Restate\Handler(documentation: 'Runs checkout.', metadata: ['stage' => 'run'])]
    public function run(WorkflowContext $ctx): void
    {
    }

    #[Restate\Handler('status', documentation: 'Reads checkout status.', metadata: ['stage' => 'query'])]
    #[Restate\Shared]
    public function status(WorkflowSharedContext $ctx): void
    {
    }
}

final class ServiceReflectorMetadataTest extends TestCase
{
    public function testReflectsServiceDocumentationAndMetadata(): void
    {
        $definition = ServiceReflector::fromObject(new ReflectorMetadataServiceFixture());

        self::assertSame('MetaGreeter', $definition->name);
        self::assertSame(ServiceDefinition::TYPE_SERVICE, $definition->type);
        self::assertSame('Greeter service.', $definition->documentation);
        self::assertSame(['domain' => 'examples'], $definition->metadata);
        self::assertSame(['retryPolicyInitialInterval' => 100], $definition->invocationRetryPolicy?->manifestFields());

        self::assertCount(1, $definition->handlers);
        self::assertSame('greet', $definition->handlers[0]->name);
        self::assertNull($definition->handlers[0]->type);
        self::assertSame('Greets a person.', $definition->handlers[0]->documentation);
        self::assertSame(['owner' => 'sdk'], $definition->handlers[0]->metadata);
        self::assertSame([
            'retryPolicyMaxAttempts' => 3,
            'retryPolicyOnMaxAttempts' => 'PAUSE',
        ], $definition->handlers[0]->invocationRetryPolicy?->manifestFields());
    }

    public function testReflectsVirtualObjectDocumentationAndMetadata(): void
    {
        $definition = ServiceReflector::fromObject(new ReflectorMetadataObjectFixture());

        self::assertSame('MetaCounter', $definition->name);
        self::assertSame(ServiceDefinition::TYPE_VIRTUAL_OBJECT, $definition->type);
        self::assertSame('Counter object.', $definition->documentation);
        self::assertSame(['domain' => 'state'], $definition->metadata);

        self::assertCount(2, $definition->handlers);
        self::assertSame('add', $definition->handlers[0]->name);
        self::assertSame(HandlerDefinition::TYPE_EXCLUSIVE, $definition->handlers[0]->type);
        self::assertSame('Adds to the counter.', $definition->handlers[0]->documentation);
        self::assertSame(['consistency' => 'exclusive'], $definition->handlers[0]->metadata);

        self::assertSame('get', $definition->handlers[1]->name);
        self::assertSame(HandlerDefinition::TYPE_SHARED, $definition->handlers[1]->type);
        self::assertSame('Reads the counter.', $definition->handlers[1]->documentation);
        self::assertSame(['consistency' => 'shared'], $definition->handlers[1]->metadata);
    }

    public function testReflectsWorkflowDocumentationAndMetadata(): void
    {
        $definition = ServiceReflector::fromObject(new ReflectorMetadataWorkflowFixture());

        self::assertSame('MetaCheckout', $definition->name);
        self::assertSame(ServiceDefinition::TYPE_WORKFLOW, $definition->type);
        self::assertSame('Checkout workflow.', $definition->documentation);
        self::assertSame(['domain' => 'orders'], $definition->metadata);

        self::assertCount(2, $definition->handlers);
        self::assertSame('run', $definition->handlers[0]->name);
        self::assertSame(HandlerDefinition::TYPE_WORKFLOW, $definition->handlers[0]->type);
        self::assertSame('Runs checkout.', $definition->handlers[0]->documentation);
        self::assertSame(['stage' => 'run'], $definition->handlers[0]->metadata);

        self::assertSame('status', $definition->handlers[1]->name);
        self::assertSame(HandlerDefinition::TYPE_SHARED, $definition->handlers[1]->type);
        self::assertSame('Reads checkout status.', $definition->handlers[1]->documentation);
        self::assertSame(['stage' => 'query'], $definition->handlers[1]->metadata);
    }
}
