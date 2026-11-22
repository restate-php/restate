<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\IntegrationTestCase;

final class BasicServiceTest extends IntegrationTestCase
{
    public function testGreetReturnsGreeting(): void
    {
        self::assertInvocationReturns('"Hello Mary"', 'Greeter/greet', '"Mary"');
    }

    public function testIdempotencyKeyReplaysFirstResponse(): void
    {
        $headers = ['idempotency-key' => 'basic-idempotency'];

        self::assertInvocationReturns('"Hello Mary"', 'Greeter/greet', '"Mary"', $headers);
        self::assertInvocationReturns('"Hello Mary"', 'Greeter/greet', '"Alice"', $headers);
    }
}
