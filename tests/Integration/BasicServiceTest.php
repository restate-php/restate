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

    /** Discovery advertises an optional input as not required, so Restate accepts a request without a body. */
    public function testAnOptionalInputWithoutABodyIsTheParametersDefault(): void
    {
        self::assertInvocationReturns('"Hello stranger"', 'Greeter/greetOptional', null);
        self::assertInvocationReturns('"Hello Mary"', 'Greeter/greetOptional', '"Mary"');
    }

    public function testAnInputThatNeedsAValueFailsWithoutABody(): void
    {
        $response = self::ingress('Greeter/greet', null);

        self::assertSame(400, $response->status, $response->body);
        self::assertStringContainsString('Greeter/greet requires an input payload, but the request has no body.', $response->body);
    }

    public function testIdempotencyKeyReplaysFirstResponse(): void
    {
        $headers = ['idempotency-key' => 'basic-idempotency'];

        self::assertInvocationReturns('"Hello Mary"', 'Greeter/greet', '"Mary"', $headers);
        self::assertInvocationReturns('"Hello Mary"', 'Greeter/greet', '"Alice"', $headers);
    }
}
