<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\Service;
use Restate\ServiceDefinition;
use Restate\ServiceType;

final class ServiceTest extends TestCase
{
    public function testBuilderCreatesServiceDefinition(): void
    {
        $callback = static fn (Context $ctx, string $name): string => "Hello {$name}";

        $service = Service::new('Greeter')->handler('greet', $callback);
        $definition = $service->definition();

        self::assertSame('Greeter', $definition->name);
        self::assertSame(ServiceType::Service, $definition->type);
        self::assertCount(1, $definition->handlers);
        self::assertSame('greet', $definition->handlers[0]->name);
    }

    public function testBuilderIsImmutable(): void
    {
        $service = Service::new('Greeter');
        $withHandler = $service->handler('greet', static fn (Context $ctx, string $name): string => $name);

        self::assertCount(0, $service->definition()->handlers);
        self::assertCount(1, $withHandler->definition()->handlers);
    }

    public function testDuplicateHandlerNamesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler Greeter/greet is already registered.');

        Service::new('Greeter')
            ->handler('greet', static fn (Context $ctx, string $name): string => $name)
            ->handler('greet', static fn (Context $ctx, string $name): string => $name);
    }
}
