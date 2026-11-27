<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Support\IntegrationTestCase;

#[Group('runtime')]
final class RequestTest extends IntegrationTestCase
{
    /**
     * Restate passes the idempotency key (and scope and limit key, which the ingress does not set here) to the handler.
     */
    public function testHandlerSeesTheRequestItWasInvokedWith(): void
    {
        $idempotencyKey = 'restate-php-request-' . bin2hex(random_bytes(6));

        $response = json_decode(
            self::invoke('RequestInfo/alice/describe', '{"hello":"world"}', ['idempotency-key' => $idempotencyKey, 'restate-php-test' => 'header-value']),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($response);
        self::assertIsString($response['id']);
        self::assertStringStartsWith('inv_', $response['id']);
        self::assertSame(['service' => 'RequestInfo', 'handler' => 'describe', 'key' => 'alice'], $response['target']);
        self::assertSame('header-value', $response['header']);
        self::assertSame('{"hello":"world"}', $response['body']);
        self::assertIsString($response['contentType']);
        self::assertMatchesRegularExpression('/^application\/vnd\.restate\.invocation\.v(\d+)$/', $response['contentType']);
        self::assertSame($idempotencyKey, $response['idempotencyKey']);
        self::assertNull($response['scope']);
        self::assertNull($response['limitKey']);
    }
}
