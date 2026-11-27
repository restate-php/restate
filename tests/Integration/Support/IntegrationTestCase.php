<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Support;

use PHPUnit\Framework\TestCase;
use Restate\Testing\EndpointContainer;
use Restate\Testing\PHPUnit\InteractsWithRestate;
use Restate\Testing\PhpCliServer;
use Restate\Testing\RestateContainer;
use Restate\Testing\RestateProcess;

/**
 * Base class for tests that drive the shared Restate server and PHP endpoint over HTTP. The environment is the public
 * test harness ({@see InteractsWithRestate}) serving {@see IntegrationEndpoint} with the Restate of
 * {@see IntegrationRestate}; failing tests dump the Restate log and the endpoint log written during the test.
 */
abstract class IntegrationTestCase extends TestCase
{
    use InteractsWithRestate;

    #[\Override]
    protected static function restateEndpoint(): PhpCliServer|EndpointContainer
    {
        return IntegrationEndpoint::get();
    }

    /** Replaces the trait's RestateContainer::default() (so no #[\Override]: a trait method is not a parent's). */
    protected static function restateContainer(): RestateContainer|RestateProcess
    {
        return IntegrationRestate::get();
    }

    /** Scratch directory shared with the endpoint (RESTATE_PHP_TEST_STATE_DIR). */
    protected static function stateDir(): string
    {
        return IntegrationEndpoint::stateDir();
    }

    /**
     * POST a JSON payload (null: no body) to the Restate ingress, returning any HTTP status.
     *
     * @param array<string, string> $headers
     */
    protected static function ingress(string $path, ?string $json, array $headers = [], float $timeout = 15.0): HttpResponse
    {
        return HttpClient::postJson(self::restate()->ingressUrl() . '/' . ltrim($path, '/'), $json, $headers, $timeout);
    }

    /**
     * Invoke a handler through the ingress, assert it succeeded (`curl -f`), and return the body.
     *
     * @param array<string, string> $headers
     */
    protected static function invoke(string $path, ?string $json, array $headers = [], float $timeout = 15.0): string
    {
        $response = self::ingress($path, $json, $headers, $timeout);
        self::assertTrue(
            $response->isSuccessful(),
            sprintf('Failed to invoke %s: HTTP %d %s', $path, $response->status, $response->body),
        );

        return $response->body;
    }

    /** @param array<string, string> $headers */
    protected static function assertInvocationReturns(string $expected, string $path, ?string $json, array $headers = [], float $timeout = 15.0): void
    {
        self::assertSame($expected, self::invoke($path, $json, $headers, $timeout), "Unexpected response from {$path}");
    }

    /**
     * Send an invocation (`…/send`) without waiting for it, and return its id.
     *
     * @param array<string, string> $headers for example an `idempotency-key`
     */
    protected static function sendInvocation(string $path, ?string $json, array $headers = []): string
    {
        $sent = self::invoke(rtrim($path, '/') . '/send', $json, $headers);
        $invocationId = json_decode($sent, true, flags: JSON_THROW_ON_ERROR)['invocationId'] ?? null;
        self::assertIsString($invocationId, "send returned no invocation id: {$sent}");

        return $invocationId;
    }

    /** Wait for an invocation's result (`GET /restate/invocation/{id}/attach`). */
    protected static function attachInvocation(string $invocationId, float $timeout = 20.0): HttpResponse
    {
        return HttpClient::get(self::restate()->ingressUrl() . "/restate/invocation/{$invocationId}/attach", timeout: $timeout);
    }

    /**
     * The invocation's `sys_invocation` row ({@see \Restate\Testing\RestateTestEnvironment::invocationRow()}),
     * asserting Restate has one.
     *
     * @return array<string, mixed>
     */
    protected static function invocationRow(string $invocationId, string ...$columns): array
    {
        $row = self::restate()->invocationRow($invocationId, ...$columns);
        self::assertNotNull($row, "Restate has no sys_invocation row for {$invocationId}.");

        return $row;
    }

    /**
     * Restate's journal events of the invocation of the given type (`TransientError`, `Paused`, …), oldest first.
     * Restate records a transient error when an attempt fails, though not every repetition of the same error.
     *
     * @return list<array<string, mixed>>
     */
    protected static function journalEvents(string $invocationId, string $type): array
    {
        return self::restate()->query(sprintf(
            "SELECT event_type, event_json, appended_at FROM sys_journal_events WHERE id = '%s' AND event_type = '%s' ORDER BY appended_at",
            $invocationId,
            $type,
        ));
    }

    /** Poll `Counter/{key}/get` until it returns the expected value (40 x 0.25 s). */
    protected static function waitForCounter(string $key, string $expected): void
    {
        $last = '<none>';

        for ($i = 0; $i < 40; $i++) {
            try {
                $response = self::ingress("Counter/{$key}/get", null, timeout: 5.0);
                if ($response->isSuccessful() && $response->body === $expected) {
                    self::assertSame($expected, $response->body);

                    return;
                }
                $last = $response->body;
            } catch (HttpTransportException $exception) {
                $last = $exception->getMessage();
            }
            usleep(250_000);
        }

        self::fail("Counter/{$key}/get did not become {$expected}; last response was {$last}");
    }

    /** Assert `Counter/{key}/get` keeps returning the expected value (12 x 0.25 s). */
    protected static function assertCounterStays(string $key, string $expected): void
    {
        for ($i = 0; $i < 12; $i++) {
            $response = self::ingress("Counter/{$key}/get", null, timeout: 5.0);
            self::assertTrue($response->isSuccessful(), "Failed to read Counter/{$key}/get while checking it stays {$expected}");
            self::assertSame($expected, $response->body, "Counter/{$key}/get changed from {$expected}");
            usleep(250_000);
        }
    }
}
