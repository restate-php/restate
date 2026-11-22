<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\Reflection;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\Attributes\Handler;
use Restate\Attributes\Shared;
use Restate\Attributes\Service as ServiceAttribute;
use Restate\Attributes\VirtualObject as VirtualObjectAttribute;
use Restate\Context;
use Restate\HandlerDefinition;
use Restate\Reflection\ServiceReflector;
use Restate\ServiceDefinition;

#[ServiceAttribute('Greeter')]
final class ReflectedGreeter
{
    #[Handler('greet')]
    public function greet(Context $ctx, string $name): string
    {
        return "Hello {$name}";
    }

    #[Handler]
    public function defaultName(Context $ctx, string $name): string
    {
        return $name;
    }
}

#[VirtualObjectAttribute('Counter')]
final class ReflectedCounter
{
    #[Handler('add')]
    public function add(Context $ctx, int $value): int
    {
        return $value;
    }

    #[Handler('get')]
    #[Shared]
    public function get(Context $ctx): int
    {
        return 0;
    }
}

final class NoServiceAttribute
{
    #[Handler('greet')]
    public function greet(): void
    {
    }
}

#[ServiceAttribute('Bad Name')]
final class InvalidServiceName
{
    #[Handler('greet')]
    public function greet(): void
    {
    }
}

#[ServiceAttribute('Greeter')]
final class InvalidHandlerName
{
    #[Handler('bad-name')]
    public function greet(): void
    {
    }
}

#[ServiceAttribute('Greeter')]
final class DuplicateHandlerNames
{
    #[Handler('greet')]
    public function greetOne(Context $ctx): void
    {
    }

    #[Handler('greet')]
    public function greetTwo(Context $ctx): void
    {
    }
}

final class ServiceReflectorTest extends TestCase
{
    public function testReflectsBasicServiceDefinition(): void
    {
        $definition = ServiceReflector::fromObject(new ReflectedGreeter());

        self::assertSame('Greeter', $definition->name);
        self::assertSame(ServiceDefinition::TYPE_SERVICE, $definition->type);
        self::assertCount(2, $definition->handlers);
        self::assertSame('greet', $definition->handlers[0]->name);
        self::assertNull($definition->handlers[0]->type);
        self::assertSame('defaultName', $definition->handlers[1]->name);
        self::assertNull($definition->handlers[1]->type);
    }

    public function testReflectsVirtualObjectDefinitionAndHandlerTypes(): void
    {
        $definition = ServiceReflector::fromObject(new ReflectedCounter());

        self::assertSame('Counter', $definition->name);
        self::assertSame(ServiceDefinition::TYPE_VIRTUAL_OBJECT, $definition->type);
        self::assertCount(2, $definition->handlers);
        self::assertSame('add', $definition->handlers[0]->name);
        self::assertSame(HandlerDefinition::TYPE_EXCLUSIVE, $definition->handlers[0]->type);
        self::assertSame('get', $definition->handlers[1]->name);
        self::assertSame(HandlerDefinition::TYPE_SHARED, $definition->handlers[1]->type);
    }

    public function testReflectedHandlerCallableInvokesObjectMethod(): void
    {
        $definition = ServiceReflector::fromObject(new ReflectedGreeter());
        $handler = $definition->handlers[0];

        self::assertSame('Hello Mary', ($handler->callback)(self::createStub(Context::class), 'Mary'));
    }

    public function testRejectsClassWithoutServiceTypeAttribute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service class must have exactly one Restate service type attribute.');

        ServiceReflector::fromObject(new NoServiceAttribute());
    }

    public function testRejectsInvalidServiceName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Restate service name Bad Name.');

        ServiceReflector::fromObject(new InvalidServiceName());
    }

    public function testRejectsInvalidHandlerName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Restate handler name bad-name.');

        ServiceReflector::fromObject(new InvalidHandlerName());
    }

    public function testRejectsDuplicateHandlerNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler Greeter/greet is already registered.');

        ServiceReflector::fromObject(new DuplicateHandlerNames());
    }
}
