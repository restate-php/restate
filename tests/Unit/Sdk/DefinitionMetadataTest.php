<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\HandlerDefinition;
use Restate\InvocationRetryPolicy;
use Restate\Service;
use Restate\ServiceDefinition;
use Restate\VirtualObject;
use Restate\Workflow;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;
use Restate\ServiceType;

final class DefinitionMetadataTest extends TestCase
{
    public function testDirectDefinitionsCarryDocumentationAndMetadata(): void
    {
        $servicePolicy = new InvocationRetryPolicy(initialIntervalMillis: 100);
        $handlerPolicy = new InvocationRetryPolicy(maxAttempts: 3, onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_PAUSE);
        $handler = new HandlerDefinition(
            'greet',
            static fn (Context $ctx, string $name): string => "Hello {$name}",
            documentation: 'Greets a person.',
            metadata: ['owner' => 'sdk'],
            invocationRetryPolicy: $handlerPolicy,
        );

        $service = new ServiceDefinition(
            'Greeter',
            [$handler],
            ServiceType::Service,
            documentation: 'Greeter service.',
            metadata: ['domain' => 'examples'],
            invocationRetryPolicy: $servicePolicy,
        );

        self::assertSame('Greeter service.', $service->documentation);
        self::assertSame(['domain' => 'examples'], $service->metadata);
        self::assertSame($servicePolicy, $service->invocationRetryPolicy);
        self::assertSame('Greets a person.', $service->handlers[0]->documentation);
        self::assertSame(['owner' => 'sdk'], $service->handlers[0]->metadata);
        self::assertSame($handlerPolicy, $service->handlers[0]->invocationRetryPolicy);
    }

    public function testBuildersCarryServiceAndHandlerDocumentationAndMetadata(): void
    {
        $servicePolicy = new InvocationRetryPolicy(initialIntervalMillis: 100);
        $handlerPolicy = new InvocationRetryPolicy(maxAttempts: 3, onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_PAUSE);
        $service = Service::new('Greeter', documentation: 'Greeter service.', metadata: ['domain' => 'examples'], invocationRetryPolicy: $servicePolicy)
            ->handler(
                'greet',
                static fn (Context $ctx, string $name): string => "Hello {$name}",
                documentation: 'Greets a person.',
                metadata: ['owner' => 'sdk'],
                invocationRetryPolicy: $handlerPolicy,
            )
            ->definition();

        self::assertSame('Greeter service.', $service->documentation);
        self::assertSame(['domain' => 'examples'], $service->metadata);
        self::assertSame($servicePolicy, $service->invocationRetryPolicy);
        self::assertSame('Greets a person.', $service->handlers[0]->documentation);
        self::assertSame(['owner' => 'sdk'], $service->handlers[0]->metadata);
        self::assertSame($handlerPolicy, $service->handlers[0]->invocationRetryPolicy);

        $objectPolicy = new InvocationRetryPolicy(maxAttempts: 10);
        $exclusivePolicy = new InvocationRetryPolicy(maxAttempts: 2);
        $sharedPolicy = new InvocationRetryPolicy(maxAttempts: 1);
        $object = VirtualObject::new('Counter', documentation: 'Counter object.', metadata: ['domain' => 'state'], invocationRetryPolicy: $objectPolicy)
            ->handler('add', static fn (Context $ctx, int $amount): int => $amount, documentation: 'Adds value.', metadata: ['mode' => 'write'], invocationRetryPolicy: $exclusivePolicy)
            ->sharedHandler('get', static fn (Context $ctx): int => 1, documentation: 'Reads value.', metadata: ['mode' => 'read'], invocationRetryPolicy: $sharedPolicy)
            ->definition();

        self::assertSame('Counter object.', $object->documentation);
        self::assertSame(['domain' => 'state'], $object->metadata);
        self::assertSame($objectPolicy, $object->invocationRetryPolicy);
        self::assertSame('Adds value.', $object->handlers[0]->documentation);
        self::assertSame(['mode' => 'write'], $object->handlers[0]->metadata);
        self::assertSame($exclusivePolicy, $object->handlers[0]->invocationRetryPolicy);
        self::assertSame('Reads value.', $object->handlers[1]->documentation);
        self::assertSame(['mode' => 'read'], $object->handlers[1]->metadata);
        self::assertSame($sharedPolicy, $object->handlers[1]->invocationRetryPolicy);

        $workflowPolicy = new InvocationRetryPolicy(maxAttempts: 8);
        $runPolicy = new InvocationRetryPolicy(maxAttempts: 4);
        $workflowSharedPolicy = new InvocationRetryPolicy(maxAttempts: 2);
        $workflow = Workflow::new('Checkout', documentation: 'Checkout workflow.', metadata: ['domain' => 'orders'], invocationRetryPolicy: $workflowPolicy)
            ->run(static fn (WorkflowContext $ctx, string $orderId): string => $orderId, documentation: 'Starts checkout.', metadata: ['mode' => 'run'], invocationRetryPolicy: $runPolicy)
            ->sharedHandler('approve', static fn (WorkflowSharedContext $ctx, string $orderId): string => $orderId, documentation: 'Approves checkout.', metadata: ['mode' => 'shared'], invocationRetryPolicy: $workflowSharedPolicy)
            ->definition();

        self::assertSame('Checkout workflow.', $workflow->documentation);
        self::assertSame(['domain' => 'orders'], $workflow->metadata);
        self::assertSame($workflowPolicy, $workflow->invocationRetryPolicy);
        self::assertSame('Starts checkout.', $workflow->handlers[0]->documentation);
        self::assertSame(['mode' => 'run'], $workflow->handlers[0]->metadata);
        self::assertSame($runPolicy, $workflow->handlers[0]->invocationRetryPolicy);
        self::assertSame('Approves checkout.', $workflow->handlers[1]->documentation);
        self::assertSame(['mode' => 'shared'], $workflow->handlers[1]->metadata);
        self::assertSame($workflowSharedPolicy, $workflow->handlers[1]->invocationRetryPolicy);
    }

    public function testServiceDefinitionRejectsNonStringMetadataKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service metadata keys must be strings.');

        new ServiceDefinition('Greeter', [], metadata: [1 => 'sdk']);
    }

    public function testServiceDefinitionRejectsNonStringMetadataValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service metadata values must be strings.');

        new ServiceDefinition('Greeter', [], metadata: ['owner' => 123]);
    }

    public function testHandlerDefinitionRejectsNonStringMetadataKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler metadata keys must be strings.');

        new HandlerDefinition('greet', static function (Context $ctx): void {
        }, metadata: [1 => 'sdk']);
    }

    public function testHandlerDefinitionRejectsNonStringMetadataValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler metadata values must be strings.');

        new HandlerDefinition('greet', static function (Context $ctx): void {
        }, metadata: ['owner' => 123]);
    }
}
