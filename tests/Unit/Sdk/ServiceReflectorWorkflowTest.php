<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Attributes\Handler;
use Restate\Attributes\Input;
use Restate\Attributes\Output as OutputAttribute;
use Restate\Attributes\Service as ServiceAttribute;
use Restate\Attributes\Shared;
use Restate\Attributes\Workflow as WorkflowAttribute;
use Restate\HandlerDefinition;
use Restate\Reflection\ServiceReflector;
use Restate\ServiceDefinition;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

#[WorkflowAttribute('ReflectedCheckout')]
final class ReflectorWorkflowFixture
{
    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    #[Handler]
    #[Input(schema: ['type' => 'object'], required: true)]
    public function run(WorkflowContext $ctx, array $order): array
    {
        return ['context' => $ctx::class, 'order' => $order];
    }

    #[Handler('approve')]
    #[Shared]
    #[OutputAttribute(empty: true)]
    public function approve(WorkflowSharedContext $ctx): null
    {
        return null;
    }
}

#[WorkflowAttribute('RenamedRun')]
final class ReflectorWorkflowRenamedRunFixture
{
    #[Handler('run')]
    public function execute(WorkflowContext $ctx): string
    {
        return 'started';
    }
}

#[WorkflowAttribute('MissingRun')]
final class ReflectorWorkflowMissingRunFixture
{
    #[Handler('status')]
    #[Shared]
    public function status(WorkflowSharedContext $ctx): string
    {
        return 'waiting';
    }
}

#[WorkflowAttribute('SharedRun')]
final class ReflectorWorkflowSharedRunFixture
{
    #[Handler]
    #[Shared]
    public function run(WorkflowContext $ctx): string
    {
        return 'started';
    }
}

#[WorkflowAttribute('NonSharedHandler')]
final class ReflectorWorkflowNonSharedHandlerFixture
{
    #[Handler]
    public function run(WorkflowContext $ctx): string
    {
        return 'started';
    }

    #[Handler('approve')]
    public function approve(WorkflowSharedContext $ctx): null
    {
        return null;
    }
}

#[WorkflowAttribute('DuplicateRun')]
final class ReflectorWorkflowDuplicateRunFixture
{
    #[Handler]
    public function run(WorkflowContext $ctx): string
    {
        return 'started';
    }

    #[Handler('run')]
    public function execute(WorkflowContext $ctx): string
    {
        return 'started';
    }
}

#[ServiceAttribute('MultiType')]
#[WorkflowAttribute('MultiType')]
final class ReflectorWorkflowMultipleTypeFixture
{
    #[Handler]
    public function run(WorkflowContext $ctx): string
    {
        return 'started';
    }
}

final class ServiceReflectorWorkflowTest extends TestCase
{
    public function testReflectsWorkflowHandlersAndPayloadMetadata(): void
    {
        $definition = ServiceReflector::fromObject(new ReflectorWorkflowFixture());

        self::assertSame('ReflectedCheckout', $definition->name);
        self::assertSame(ServiceDefinition::TYPE_WORKFLOW, $definition->type);
        self::assertCount(2, $definition->handlers);

        self::assertSame('run', $definition->handlers[0]->name);
        self::assertSame(HandlerDefinition::TYPE_WORKFLOW, $definition->handlers[0]->type);
        self::assertSame([
            'required' => true,
            'contentType' => 'application/json',
            'jsonSchema' => ['type' => 'object'],
        ], $definition->handlers[0]->inputPayload->inputManifest());

        self::assertSame('approve', $definition->handlers[1]->name);
        self::assertSame(HandlerDefinition::TYPE_SHARED, $definition->handlers[1]->type);
        self::assertSame(['setContentTypeIfEmpty' => false], $definition->handlers[1]->outputPayload->outputManifest());
    }

    public function testHandlerNameOverrideCanDefineWorkflowRun(): void
    {
        $definition = ServiceReflector::fromObject(new ReflectorWorkflowRenamedRunFixture());

        self::assertSame('run', $definition->handlers[0]->name);
        self::assertSame(HandlerDefinition::TYPE_WORKFLOW, $definition->handlers[0]->type);
    }

    public function testMissingRunHandlerIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow MissingRun must define a run handler.');

        ServiceReflector::fromObject(new ReflectorWorkflowMissingRunFixture());
    }

    public function testSharedRunHandlerIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow SharedRun/run handler must not be shared.');

        ServiceReflector::fromObject(new ReflectorWorkflowSharedRunFixture());
    }

    public function testNonRunWorkflowHandlerMustBeShared(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow NonSharedHandler/approve handler must be shared.');

        ServiceReflector::fromObject(new ReflectorWorkflowNonSharedHandlerFixture());
    }

    #[DataProvider('reservedWorkflowHandlerNameProvider')]
    public function testReservedWorkflowSharedHandlerNamesAreRejected(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow handler {$name} is reserved by Restate.");

        ServiceReflector::fromObject(self::reservedSharedHandlerFixture($name));
    }

    public function testDuplicateWorkflowHandlerNamesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler DuplicateRun/run is already registered.');

        ServiceReflector::fromObject(new ReflectorWorkflowDuplicateRunFixture());
    }

    public function testMultipleServiceTypeAttributesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service class must have exactly one Restate service type attribute.');

        ServiceReflector::fromObject(new ReflectorWorkflowMultipleTypeFixture());
    }

    /** @return iterable<string, array{string}> */
    public static function reservedWorkflowHandlerNameProvider(): iterable
    {
        yield 'workflow submit' => ['workflowSubmit'];
        yield 'workflow attach' => ['workflowAttach'];
        yield 'workflow output' => ['workflowOutput'];
    }

    private static function reservedSharedHandlerFixture(string $handlerName): object
    {
        $class = self::ensureDynamicFixture(__NAMESPACE__ . '\\ReflectorReserved' . ucfirst($handlerName) . 'Fixture', <<<PHP
            namespace Restate\Tests\Unit\Sdk;

            #[\Restate\Attributes\Workflow('Reserved{$handlerName}')]
            final class ReflectorReserved{$handlerName}Fixture
            {
                #[\Restate\Attributes\Handler]
                public function run(\Restate\WorkflowContext \$ctx): string
                {
                    return 'started';
                }

                #[\Restate\Attributes\Handler('{$handlerName}')]
                #[\Restate\Attributes\Shared]
                public function reserved(\Restate\WorkflowSharedContext \$ctx): null
                {
                    return null;
                }
            }
            PHP);

        return new $class();
    }

    /**
     * @return class-string
     */
    private static function ensureDynamicFixture(string $class, string $source): string
    {
        if (!class_exists($class, false)) {
            eval($source);
        }

        if (!class_exists($class, false)) {
            throw new \RuntimeException("Failed to define {$class}.");
        }

        return $class;
    }
}
