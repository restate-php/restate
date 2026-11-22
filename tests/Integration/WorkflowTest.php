<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\IntegrationTestCase;

final class WorkflowTest extends IntegrationTestCase
{
    public function testPromisePeekSeesValueOnlyAfterResolve(): void
    {
        self::assertInvocationReturns('null', 'Checkout/peek-order/peekApproval', null);
        self::assertInvocationReturns(
            '{"before":null,"after":{"status":"approved"}}',
            'Checkout/peek-order/peekThenApprove',
            '{"status":"approved"}',
        );
    }

    public function testRunWaitsForApprovalPromise(): void
    {
        $run = HttpClient::startPostJson(self::restate()->ingressUrl() . '/Checkout/order-1/run', '{"item":"book"}', timeout: 30.0);

        self::assertFalse($run->finishesWithin(0.75), 'Checkout workflow run returned before approval');

        self::invoke('Checkout/order-1/approve', '{"status":"approved"}');

        $response = $run->wait();
        self::assertTrue($response->isSuccessful(), "Checkout workflow run failed: HTTP {$response->status} {$response->body}");
        self::assertSame('"approved"', $response->body);
    }

    public function testCancellingAWorkflowBlockedOnAPromiseCompletesItAsCancelled(): void
    {
        $key = 'cancel-blocked-' . bin2hex(random_bytes(4));
        // The run blocks on the `approval` promise, which nobody resolves.
        $sent = self::invoke("Checkout/{$key}/run/send", '{"item":"book"}');
        $invocationId = json_decode($sent, true, flags: JSON_THROW_ON_ERROR)['invocationId'] ?? null;
        self::assertIsString($invocationId, "send returned no invocation id: {$sent}");
        usleep(500_000);

        $cancel = HttpClient::patch(self::restate()->adminUrl() . "/invocations/{$invocationId}/cancel");
        self::assertTrue($cancel->isSuccessful(), "Admin cancel failed: HTTP {$cancel->status} {$cancel->body}");

        $output = HttpClient::get(self::restate()->ingressUrl() . "/restate/workflow/Checkout/{$key}/attach", timeout: 10.0);
        self::assertSame(409, $output->status, "Cancelled workflow expected HTTP 409: {$output->body}");
        self::assertStringContainsString('Invocation cancelled', $output->body);
    }
}
