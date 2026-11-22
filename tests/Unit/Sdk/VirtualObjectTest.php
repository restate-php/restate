<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\HandlerDefinition;
use Restate\ServiceDefinition;
use Restate\VirtualObject;

final class VirtualObjectTest extends TestCase
{
    public function testBuilderCreatesVirtualObjectDefinition(): void
    {
        $add = static fn (Context $ctx, int $amount): int => $amount;
        $get = static fn (Context $ctx): int => 1;

        $definition = VirtualObject::new('Counter')
            ->handler('add', $add)
            ->sharedHandler('get', $get)
            ->definition();

        self::assertSame('Counter', $definition->name);
        self::assertSame(ServiceDefinition::TYPE_VIRTUAL_OBJECT, $definition->type);
        self::assertCount(2, $definition->handlers);
        self::assertSame('add', $definition->handlers[0]->name);
        self::assertSame(HandlerDefinition::TYPE_EXCLUSIVE, $definition->handlers[0]->type);
        self::assertSame('get', $definition->handlers[1]->name);
        self::assertSame(HandlerDefinition::TYPE_SHARED, $definition->handlers[1]->type);
    }

    public function testDuplicateHandlerNamesAreRejectedAcrossExclusiveAndSharedHandlers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler Counter/add is already registered.');

        VirtualObject::new('Counter')
            ->handler('add', static fn (Context $ctx): int => 1)
            ->sharedHandler('add', static fn (Context $ctx): int => 1);
    }

    public function testBuilderIsImmutable(): void
    {
        $object = VirtualObject::new('Counter');
        $withExclusiveHandler = $object->handler('add', static fn (Context $ctx): int => 1);
        $withSharedHandler = $withExclusiveHandler->sharedHandler('get', static fn (Context $ctx): int => 1);

        self::assertCount(0, $object->definition()->handlers);
        self::assertCount(1, $withExclusiveHandler->definition()->handlers);
        self::assertCount(2, $withSharedHandler->definition()->handlers);
    }
}
