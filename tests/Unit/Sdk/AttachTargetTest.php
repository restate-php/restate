<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\AttachTarget;
use Restate\Target;

final class AttachTargetTest extends TestCase
{
    public function testInvocationIdTargetArray(): void
    {
        self::assertSame(
            ['kind' => 'invocation', 'invocation_id' => 'inv-1'],
            AttachTarget::invocationId('inv-1')->toArray(),
        );
    }

    public function testWorkflowTargetArray(): void
    {
        self::assertSame(
            ['kind' => 'workflow', 'name' => 'Checkout', 'key' => 'order-1', 'scope' => null],
            AttachTarget::workflow('Checkout', 'order-1')->toArray(),
        );
    }

    public function testServiceIdempotencyTargetArray(): void
    {
        self::assertSame(
            ['kind' => 'idempotency', 'service_name' => 'Greeter', 'service_key' => null, 'handler_name' => 'greet', 'idempotency_key' => 'idem-1', 'scope' => null],
            AttachTarget::idempotency(Target::service('Greeter', 'greet'), 'idem-1')->toArray(),
        );
    }

    public function testObjectIdempotencyTargetArray(): void
    {
        self::assertSame(
            ['kind' => 'idempotency', 'service_name' => 'Counter', 'service_key' => 'alice', 'handler_name' => 'add', 'idempotency_key' => 'idem-2', 'scope' => null],
            AttachTarget::idempotency(Target::object('Counter', 'alice', 'add'), 'idem-2')->toArray(),
        );
    }

    public function testRejectsEmptyInvocationId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invocation ID must not be empty.');

        AttachTarget::invocationId('');
    }

    public function testRejectsEmptyIdempotencyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Idempotency key must not be empty.');

        AttachTarget::idempotency(Target::service('Greeter', 'greet'), '');
    }
}
