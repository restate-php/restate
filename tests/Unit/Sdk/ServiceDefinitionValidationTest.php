<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\HandlerDefinition;
use Restate\ServiceDefinition;

final class ServiceDefinitionValidationTest extends TestCase
{
    public function testDirectServiceDefinitionAcceptsValidServiceHandler(): void
    {
        $definition = new ServiceDefinition('Greeter', [self::handler('greet')]);

        self::assertSame(ServiceDefinition::TYPE_SERVICE, $definition->type);
        self::assertSame('greet', $definition->handlers[0]->name);
    }

    public function testHandlerDefinitionRejectsInvalidHandlerName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Restate handler name invalid-name.');

        self::handler('invalid-name');
    }

    public function testHandlerDefinitionRejectsInvalidHandlerType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Restate handler type UNKNOWN.');

        self::handler('greet', 'UNKNOWN');
    }

    public function testServiceDefinitionRejectsInvalidServiceName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Restate service name invalid name.');

        new ServiceDefinition('invalid name', []);
    }

    public function testServiceDefinitionRejectsInvalidServiceType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Restate service type UNKNOWN.');

        new ServiceDefinition('Greeter', [], 'UNKNOWN');
    }

    public function testServiceDefinitionRejectsNonHandlerEntries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service handlers must be HandlerDefinition instances.');

        new \ReflectionClass(ServiceDefinition::class)->newInstanceArgs(['Greeter', ['greet']]);
    }

    public function testServiceDefinitionRejectsDuplicateHandlerNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler Greeter/greet is already registered.');

        new ServiceDefinition('Greeter', [self::handler('greet'), self::handler('greet')]);
    }

    public function testServiceDefinitionRejectsTypedHandlersForService(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service Greeter/greet handler type must not be set.');

        new ServiceDefinition('Greeter', [self::handler('greet', HandlerDefinition::TYPE_SHARED)]);
    }

    public function testVirtualObjectDefinitionAcceptsExclusiveAndSharedHandlers(): void
    {
        $definition = new ServiceDefinition('Counter', [
            self::handler('add', HandlerDefinition::TYPE_EXCLUSIVE),
            self::handler('get', HandlerDefinition::TYPE_SHARED),
        ], ServiceDefinition::TYPE_VIRTUAL_OBJECT);

        self::assertSame(ServiceDefinition::TYPE_VIRTUAL_OBJECT, $definition->type);
        self::assertSame(HandlerDefinition::TYPE_EXCLUSIVE, $definition->handlers[0]->type);
        self::assertSame(HandlerDefinition::TYPE_SHARED, $definition->handlers[1]->type);
    }

    public function testVirtualObjectDefinitionRejectsMissingHandlerType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Virtual Object Counter/get handler type must be EXCLUSIVE or SHARED.');

        new ServiceDefinition('Counter', [self::handler('get')], ServiceDefinition::TYPE_VIRTUAL_OBJECT);
    }

    public function testVirtualObjectDefinitionRejectsWorkflowHandlerType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Virtual Object Counter/run handler type must be EXCLUSIVE or SHARED.');

        new ServiceDefinition('Counter', [self::handler('run', HandlerDefinition::TYPE_WORKFLOW)], ServiceDefinition::TYPE_VIRTUAL_OBJECT);
    }

    public function testWorkflowDefinitionAcceptsRunAndSharedHandlers(): void
    {
        $definition = new ServiceDefinition('Checkout', [
            self::handler('run', HandlerDefinition::TYPE_WORKFLOW),
            self::handler('approve', HandlerDefinition::TYPE_SHARED),
        ], ServiceDefinition::TYPE_WORKFLOW);

        self::assertSame(ServiceDefinition::TYPE_WORKFLOW, $definition->type);
        self::assertSame(HandlerDefinition::TYPE_WORKFLOW, $definition->handlers[0]->type);
        self::assertSame(HandlerDefinition::TYPE_SHARED, $definition->handlers[1]->type);
    }

    public function testWorkflowDefinitionRejectsMissingRunHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow Checkout must define a run handler.');

        new ServiceDefinition('Checkout', [self::handler('approve', HandlerDefinition::TYPE_SHARED)], ServiceDefinition::TYPE_WORKFLOW);
    }

    #[DataProvider('nonWorkflowRunTypeProvider')]
    public function testWorkflowDefinitionRejectsNonWorkflowRunHandler(?string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow Checkout/run handler must be a workflow handler.');

        new ServiceDefinition('Checkout', [self::handler('run', $type)], ServiceDefinition::TYPE_WORKFLOW);
    }

    /** @return iterable<string, array{?string}> */
    public static function nonWorkflowRunTypeProvider(): iterable
    {
        yield 'missing type' => [null];
        yield 'shared' => [HandlerDefinition::TYPE_SHARED];
        yield 'exclusive' => [HandlerDefinition::TYPE_EXCLUSIVE];
    }

    public function testWorkflowDefinitionRejectsNonSharedNonRunHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow Checkout/approve handler must be shared.');

        new ServiceDefinition('Checkout', [
            self::handler('run', HandlerDefinition::TYPE_WORKFLOW),
            self::handler('approve', HandlerDefinition::TYPE_EXCLUSIVE),
        ], ServiceDefinition::TYPE_WORKFLOW);
    }

    #[DataProvider('reservedWorkflowHandlerNameProvider')]
    public function testWorkflowDefinitionRejectsReservedHandlerNames(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow handler {$name} is reserved by Restate.");

        new ServiceDefinition('Checkout', [
            self::handler('run', HandlerDefinition::TYPE_WORKFLOW),
            self::handler($name, HandlerDefinition::TYPE_SHARED),
        ], ServiceDefinition::TYPE_WORKFLOW);
    }

    /** @return iterable<string, array{string}> */
    public static function reservedWorkflowHandlerNameProvider(): iterable
    {
        yield 'workflow submit' => ['workflowSubmit'];
        yield 'workflow attach' => ['workflowAttach'];
        yield 'workflow output' => ['workflowOutput'];
    }

    private static function handler(string $name, ?string $type = null): HandlerDefinition
    {
        return new HandlerDefinition($name, static function (Context $ctx): void {
        }, $type);
    }
}
