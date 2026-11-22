<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\ProxiedTestCase;
use Restate\Tests\Support\Faults\FaultProxy;

/**
 * The request side stalls or breaks off, through a FaultProxy: the body stops after 16 bytes for 3 s, and
 * a body cut in half but delivered as a complete request (a `Content-Length` of half the journal).
 */
#[Group('failure-nightly')]
final class RequestStallTest extends ProxiedTestCase
{
    public function testAStalledRequestBodyDelaysTheAttemptWithoutARetry(): void
    {
        self::proxy()->fault(FaultProxy::STALL_REQUEST, milliseconds: 3000);
        $key = 'stall-' . bin2hex(random_bytes(6));
        $id = self::sendInvocation("ChaosObject/{$key}/batch", null);

        self::assertSame('"v1"', self::attachInvocation($id, 30.0)->body);
        self::assertSame(['stall-request', 'pass'], array_column(self::connectionsOf($id), 'mode'));
        self::assertCount(1, self::chaos()->effects($id, 'r'));
        self::assertCount(2, self::chaos()->attempts($id));
        self::waitForCounter($key, '11');
    }

    /**
     * More stalled requests than the endpoint has workers: the server reads request bodies before it runs PHP
     * (`php -S` in its event loop, nginx and Caddy buffer them for php-fpm), so no worker waits on a stalled body and
     * the endpoint keeps answering.
     */
    public function testStalledRequestsDoNotExhaustThePool(): void
    {
        self::proxy()->fault(FaultProxy::STALL_REQUEST, connections: 12, milliseconds: 3000);
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = self::sendInvocation('Greeter/greet', "\"stall-{$i}\"");
        }

        // While the 12 bodies are stalled, the endpoint (reached directly) still answers, more than once.
        $deadline = microtime(true) + 1.5;
        do {
            $health = HttpClient::get(self::restate()->endpointUrl() . '/health', timeout: 1.0);
            self::assertSame(200, $health->status);
        } while (microtime(true) < $deadline);
        foreach ($ids as $i => $id) {
            self::assertSame("\"Hello stall-{$i}\"", self::attachInvocation($id, 30.0)->body);
        }
    }

    /**
     * Half the journal as a complete request: the SDK cannot start the attempt (the input frames are incomplete), and
     * says so; Restate retries, and the next attempt gets the whole body.
     */
    public function testAHalfBodyIsAFailedAttemptAndTheRetrySucceeds(): void
    {
        self::proxy()->fault(FaultProxy::TRUNCATE_REQUEST);
        $id = self::sendInvocation('Greeter/greet', '"half"');

        // Greeter has Restate's default policy, which waits 500 ms before the retry: long enough to see the failure.
        $failure = self::waitForRow($id, static fn (array $row): bool => is_string($row['last_failure'] ?? null) && $row['last_failure'] !== '', what: 'a recorded failure');
        self::assertStringContainsString('[571 Protocol violation] The input was closed while still waiting to receive all journal to replay', (string) $failure['last_failure']);
        self::assertSame('"Hello half"', self::attachInvocation($id, 30.0)->body);
        self::assertSame(['truncate-request', 'pass'], array_column(self::connectionsOf($id), 'mode'));
    }
}
