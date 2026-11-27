<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\IntegrationTestCase;
use Restate\Tests\Support\Protocol\FrameCodec;

/**
 * PHP strings need not be UTF-8, the protocol needs it: text that is not UTF-8 is substituted (U+FFFD) on its way to
 * Restate instead of failing the error path. Latin-1 "é" is the single byte "\xE9".
 */
final class NonUtf8Test extends IntegrationTestCase
{
    public function testLatin1TerminalFailureReachesTheCallerSubstitutedWithoutRetry(): void
    {
        $id = bin2hex(random_bytes(8));
        $attemptFile = self::stateDir() . '/payments-latin1-' . hash('sha256', $id) . '.txt';

        $body = self::invoke('Payments/reportLatin1Decline', '{"id":"' . $id . '"}', timeout: 10.0);

        self::assertSame(
            ['code' => 402, 'message' => "Carte refus\u{FFFD}e", 'metadata' => ['raison' => "carte vol\u{FFFD}e"]],
            json_decode($body, true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame("attempt\n", (string) @file_get_contents($attemptFile), 'declineInLatin1 must run exactly once.');
    }

    public function testLatin1TerminalFailureReachesTheIngressSubstituted(): void
    {
        $response = self::ingress('Payments/declineInLatin1', '{"id":"' . bin2hex(random_bytes(8)) . '"}', timeout: 10.0);

        self::assertSame(402, $response->status, "Payments/declineInLatin1 expected HTTP 402: {$response->body}");
        $failure = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($failure);
        self::assertSame("Carte refus\u{FFFD}e", $failure['message'] ?? null);
    }

    /**
     * Request headers reach PHP as raw bytes. Restate itself sends ASCII, so this drives the endpoint directly with a
     * hand-built invocation (start message + input) and a Latin-1 header, as a client in front of it could.
     */
    #[Group('runtime')]
    public function testRequestHeaderThatIsNotUtf8DoesNotFailTheInvocation(): void
    {
        $response = HttpClient::post(
            self::restate()->endpointUrl() . '/invoke/Payments/charge',
            FrameCodec::startFrame('inv-latin1', 1) . FrameCodec::inputCommandFrame('{"amount":100}'),
            [
                'content-type' => 'application/vnd.restate.invocation.v6',
                'x-latin1' => "caf\xE9",
            ],
        );

        self::assertSame(200, $response->status, "Expected the VM's response head, got HTTP {$response->status}: {$response->body}");
        self::assertStringContainsString('Payment declined', $response->body, 'the handler ran and wrote its terminal failure');
    }
}
