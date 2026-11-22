<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\IntegrationTestCase;

final class InvocationHandleTest extends IntegrationTestCase
{
    public function testAttachToWorkflowOutput(): void
    {
        self::assertInvocationReturns('"workflow ping"', 'Communicator/callWorkflow', '"n2b-workflow"');
        self::assertInvocationReturns('"workflow ping"', 'Communicator/attachWorkflowOutput', '"n2b-workflow"');
    }

    public function testOutputOfKnownInvocation(): void
    {
        $key = json_encode('n2c-known-output-' . bin2hex(random_bytes(4)), JSON_THROW_ON_ERROR);

        self::assertInvocationReturns('"workflow ping"', 'Communicator/knownInvocationOutput', $key);
    }

    public function testCancellingAnInvocationBlockedOnAttachCompletesItAsCancelled(): void
    {
        $key = 'n2d-attach-cancel-' . bin2hex(random_bytes(4));
        $workflow = self::ingress("Checkout/{$key}/run/send", '{}');
        self::assertTrue($workflow->isSuccessful(), "Checkout/{$key}/run/send failed: HTTP {$workflow->status} {$workflow->body}");

        try {
            // Communicator/attachCheckoutOutput blocks on the workflow, which waits for an approval that has not come.
            $sent = self::invoke('Communicator/attachCheckoutOutput/send', json_encode($key, JSON_THROW_ON_ERROR));
            $invocationId = json_decode($sent, true, flags: JSON_THROW_ON_ERROR)['invocationId'] ?? null;
            self::assertIsString($invocationId, "send returned no invocation id: {$sent}");
            usleep(500_000);

            self::assertInvocationReturns('"cancelled"', 'Communicator/cancelInvocation', json_encode($invocationId, JSON_THROW_ON_ERROR));

            $output = HttpClient::get(self::restate()->ingressUrl() . "/restate/invocation/{$invocationId}/attach", timeout: 10.0);
            self::assertSame(409, $output->status, "Cancelled invocation expected HTTP 409: {$output->body}");
            self::assertStringContainsString('Invocation cancelled', $output->body);
        } finally {
            self::ingress("Checkout/{$key}/approve", '{"status":"approved"}');
        }
    }

    public function testCancelDelayedInvocation(): void
    {
        // Positive control: the same delayed send, not cancelled, does arrive.
        self::assertInvocationReturns(
            '"scheduled"',
            'Communicator/sendCounterDelayed',
            '{"key":"n2c-cancel-control","amount":5,"delay_ms":1000}',
            timeout: 20.0,
        );
        self::waitForCounter('n2c-cancel-control', '5');

        $cancelled = self::invoke('Communicator/cancelDelayedCounter', '{"key":"n2c-cancel","amount":9,"delay_ms":2000}', timeout: 20.0);
        self::assertStringContainsString('"cancelled":true', $cancelled);

        usleep(2_250_000);
        self::assertCounterStays('n2c-cancel', '0');
    }
}
