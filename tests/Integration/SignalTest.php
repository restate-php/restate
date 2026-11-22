<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\HttpResponse;
use Restate\Tests\Integration\Support\IntegrationTestCase;

/**
 * Named signals between invocations (service `Signals` in tests/Integration/Services/signals.php). Named signals exist
 * on every protocol version the SDK speaks (v5 to v7), so this runs unchanged against Restate 1.7 (v6) and 1.8 (v7).
 */
final class SignalTest extends IntegrationTestCase
{
    public function testAWaitingInvocationReceivesTheValueItIsSignalledWith(): void
    {
        $invocationId = self::send('Signals/await', '"approval"');

        self::signal('resolve', $invocationId, 'approval', ['value' => ['approved' => true]]);

        $output = self::attach($invocationId);
        self::assertTrue($output->isSuccessful(), "Signals/await failed: HTTP {$output->status} {$output->body}");
        self::assertSame('{"approved":true}', $output->body);
    }

    public function testARejectedSignalFailsTheWaitingInvocationWithItsTerminalError(): void
    {
        $invocationId = self::send('Signals/await', '"approval"');

        self::signal('reject', $invocationId, 'approval', ['message' => 'denied by policy', 'code' => 409]);

        $output = self::attach($invocationId);
        self::assertSame(409, $output->status, "Signals/await expected HTTP 409: {$output->body}");
        self::assertStringContainsString('denied by policy', $output->body);
    }

    public function testEachDeliveryOfASignalIsReceivedOnceInOrder(): void
    {
        $invocationId = self::send('Signals/awaitTwice', '"step"');

        self::signal('resolve', $invocationId, 'step', ['value' => 'first']);
        self::signal('resolve', $invocationId, 'step', ['value' => 'second']);

        self::assertSame('["first","second"]', self::attach($invocationId)->body);
    }

    public function testSignalsCombineWithTheContextCombinators(): void
    {
        $invocationId = self::send('Signals/awaitAll', '["a","b"]');

        self::signal('resolve', $invocationId, 'b', ['value' => 2]);
        self::signal('resolve', $invocationId, 'a', ['value' => 1]);

        self::assertSame('[1,2]', self::attach($invocationId)->body);
        self::assertInvocationReturns('"timeout"', 'Signals/awaitWithTimeout', '{"name":"never","timeoutMillis":100}', timeout: 20.0);
    }

    public function testASignalSentBeforeTheWaitIsNotLost(): void
    {
        self::assertInvocationReturns('"to myself"', 'Signals/signalItself', '"to myself"', timeout: 20.0);
    }

    public function testASentInvocationIsSignalledThroughItsHandle(): void
    {
        self::assertInvocationReturns('"through the handle"', 'Signals/sendAndSignal', '"through the handle"', timeout: 20.0);
    }

    /** Sends an invocation through the ingress and returns its id. */
    private static function send(string $path, string $json): string
    {
        $sent = self::invoke("{$path}/send", $json);
        $invocationId = json_decode($sent, true, flags: JSON_THROW_ON_ERROR)['invocationId'] ?? null;
        self::assertIsString($invocationId, "send returned no invocation id: {$sent}");

        return $invocationId;
    }

    /** @param array<string, mixed> $fields */
    private static function signal(string $operation, string $invocationId, string $name, array $fields): void
    {
        self::invoke("Signals/{$operation}", json_encode(['invocationId' => $invocationId, 'name' => $name] + $fields, JSON_THROW_ON_ERROR));
    }

    private static function attach(string $invocationId): HttpResponse
    {
        return HttpClient::get(self::restate()->ingressUrl() . "/restate/invocation/{$invocationId}/attach", timeout: 20.0);
    }
}
