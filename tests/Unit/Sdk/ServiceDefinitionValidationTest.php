<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\HandlerDefinition;
use Restate\ServiceDefinition;
use Restate\ServiceType;
use Restate\HandlerType;

final class ServiceDefinitionValidationTest extends TestCase
{
    public function testDirectServiceDefinitionAcceptsValidServiceHandler(): void
    {
        $definition = new ServiceDefinition('Greeter', [self::handler('greet')]);

        self::assertSame(ServiceType::Service, $definition->type);
        self::assertSame('greet', $definition->handlers[0]->name);
    }

    public function testHandlerDefinitionRejectsInvalidHandlerName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Restate handler name invalid-name.');

        self::handler('invalid-name');
    }

    public function testServiceDefinitionRejectsInvalidServiceName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Restate service name invalid name.');

        new ServiceDefinition('invalid name', []);
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

        new ServiceDefinition('Greeter', [self::handler('greet', HandlerType::Shared)]);
    }

    public function testVirtualObjectDefinitionAcceptsExclusiveAndSharedHandlers(): void
    {
        $definition = new ServiceDefinition('Counter', [
            self::handler('add', HandlerType::Exclusive),
            self::handler('get', HandlerType::Shared),
        ], ServiceType::VirtualObject);

        self::assertSame(ServiceType::VirtualObject, $definition->type);
        self::assertSame(HandlerType::Exclusive, $definition->handlers[0]->type);
        self::assertSame(HandlerType::Shared, $definition->handlers[1]->type);
    }

    public function testVirtualObjectDefinitionRejectsMissingHandlerType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Virtual Object Counter/get handler type must be EXCLUSIVE or SHARED.');

        new ServiceDefinition('Counter', [self::handler('get')], ServiceType::VirtualObject);
    }

    public function testVirtualObjectDefinitionRejectsWorkflowHandlerType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Virtual Object Counter/run handler type must be EXCLUSIVE or SHARED.');

        new ServiceDefinition('Counter', [self::handler('run', HandlerType::Workflow)], ServiceType::VirtualObject);
    }

    public function testWorkflowDefinitionAcceptsRunAndSharedHandlers(): void
    {
        $definition = new ServiceDefinition('Checkout', [
            self::handler('run', HandlerType::Workflow),
            self::handler('approve', HandlerType::Shared),
        ], ServiceType::Workflow);

        self::assertSame(ServiceType::Workflow, $definition->type);
        self::assertSame(HandlerType::Workflow, $definition->handlers[0]->type);
        self::assertSame(HandlerType::Shared, $definition->handlers[1]->type);
    }

    public function testWorkflowDefinitionRejectsMissingRunHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow Checkout must define a run handler.');

        new ServiceDefinition('Checkout', [self::handler('approve', HandlerType::Shared)], ServiceType::Workflow);
    }

    #[DataProvider('nonWorkflowRunTypeProvider')]
    public function testWorkflowDefinitionRejectsNonWorkflowRunHandler(?HandlerType $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow Checkout/run handler must be a workflow handler.');

        new ServiceDefinition('Checkout', [self::handler('run', $type)], ServiceType::Workflow);
    }

    /** @return iterable<string, array{?HandlerType}> */
    public static function nonWorkflowRunTypeProvider(): iterable
    {
        yield 'missing type' => [null];
        yield 'shared' => [HandlerType::Shared];
        yield 'exclusive' => [HandlerType::Exclusive];
    }

    public function testWorkflowDefinitionRejectsNonSharedNonRunHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow Checkout/approve handler must be shared.');

        new ServiceDefinition('Checkout', [
            self::handler('run', HandlerType::Workflow),
            self::handler('approve', HandlerType::Exclusive),
        ], ServiceType::Workflow);
    }

    #[DataProvider('reservedWorkflowHandlerNameProvider')]
    public function testWorkflowDefinitionRejectsReservedHandlerNames(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow handler {$name} is reserved by Restate.");

        new ServiceDefinition('Checkout', [
            self::handler('run', HandlerType::Workflow),
            self::handler($name, HandlerType::Shared),
        ], ServiceType::Workflow);
    }

    /** @return iterable<string, array{string}> */
    public static function reservedWorkflowHandlerNameProvider(): iterable
    {
        yield 'workflow submit' => ['workflowSubmit'];
        yield 'workflow attach' => ['workflowAttach'];
        yield 'workflow output' => ['workflowOutput'];
    }

    private static function handler(string $name, ?HandlerType $type = null): HandlerDefinition
    {
        return new HandlerDefinition($name, static function (Context $ctx): void {
        }, $type);
    }
}
