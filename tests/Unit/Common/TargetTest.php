<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Common;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\Target;

final class TargetTest extends TestCase
{
    public function testServiceTargetArray(): void
    {
        self::assertSame(
            ['kind' => 'service', 'service' => 'Greeter', 'handler' => 'greet', 'key' => null, 'scope' => null],
            Target::service('Greeter', 'greet')->toArray(),
        );
    }

    public function testObjectTargetArray(): void
    {
        self::assertSame(
            ['kind' => 'object', 'service' => 'Counter', 'handler' => 'add', 'key' => 'alice', 'scope' => null],
            Target::object('Counter', 'alice', 'add')->toArray(),
        );
    }

    public function testWorkflowTargetArray(): void
    {
        self::assertSame(
            ['kind' => 'workflow', 'service' => 'Checkout', 'handler' => 'run', 'key' => 'order-1', 'scope' => null],
            Target::workflow('Checkout', 'order-1', 'run')->toArray(),
        );
    }

    public function testTargetExposesItsServiceHandlerAndKey(): void
    {
        $object = Target::object('Counter', 'alice', 'add');
        $service = Target::service('Greeter', 'greet');

        self::assertSame(['Counter', 'add', 'alice'], [$object->serviceName(), $object->handlerName(), $object->key()]);
        self::assertSame(['Greeter', 'greet', null], [$service->serviceName(), $service->handlerName(), $service->key()]);
    }

    public function testRejectsEmptyServiceName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Target service name must not be empty.');

        Target::service('', 'greet');
    }

    public function testRejectsInvalidServiceName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Restate service name 1Bad.');

        Target::service('1Bad', 'greet');
    }

    public function testRejectsEmptyHandlerName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Target handler name must not be empty.');

        Target::service('Greeter', '');
    }

    public function testRejectsInvalidHandlerName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Restate handler name bad-name.');

        Target::service('Greeter', 'bad-name');
    }

    public function testRejectsEmptyObjectKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Target key must not be empty.');

        Target::object('Counter', '', 'add');
    }
}
