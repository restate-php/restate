<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\IntegrationTestCase;

final class RequestTest extends IntegrationTestCase
{
    /**
     * Restate passes the idempotency key (and scope and limit key, which the ingress does not set here) from
     * service protocol v7 (Restate 1.8) on; the attempt's content type names the negotiated version.
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
        $protocolVersion = (int) substr($response['contentType'], strlen('application/vnd.restate.invocation.v'));
        self::assertSame($protocolVersion >= 7 ? $idempotencyKey : null, $response['idempotencyKey']);
        self::assertNull($response['scope']);
        self::assertNull($response['limitKey']);
    }
}
