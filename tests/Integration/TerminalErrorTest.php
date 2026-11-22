<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Restate\Tests\Integration\Support\IntegrationTestCase;

final class TerminalErrorTest extends IntegrationTestCase
{
    public function testTerminalErrorBecomesTerminalFailure(): void
    {
        $response = self::ingress('Payments/charge', '{"amount":100}');

        self::assertSame(400, $response->status, "Payments/charge expected HTTP 400: {$response->body}");
        self::assertStringContainsString('"code":400', $response->body);
        self::assertStringContainsString('Payment declined', $response->body);
    }

    /**
     * Numeric-looking metadata keys become int keys in PHP arrays; they must survive encoding (an all-numeric map is a PHP
     * list) and decoding (`json_decode` turns "404" into 404) without the failure being rejected and retried.
     *
     * @return iterable<string, array{string}>
     */
    public static function numericMetadataProvider(): iterable
    {
        yield 'numeric and string keys' => ['{"404":"not found","reason":"lost"}'];
        yield 'keys that form a PHP list' => ['{"0":"first","1":"second"}'];
    }

    #[DataProvider('numericMetadataProvider')]
    public function testTerminalFailureWithNumericMetadataKeysReachesTheCallerWithoutRetry(string $metadataJson): void
    {
        $id = bin2hex(random_bytes(8));
        $attemptFile = self::stateDir() . '/payments-decline-' . hash('sha256', $id) . '.txt';

        self::assertInvocationReturns(
            '{"code":402,"message":"Card reported lost","metadata":' . $metadataJson . '}',
            'Payments/reportDecline',
            '{"id":"' . $id . '","metadata":' . $metadataJson . '}',
            timeout: 10.0,
        );
        self::assertSame("attempt\n", (string) @file_get_contents($attemptFile), 'declineWithMetadata must run exactly once.');
    }

    public function testMalformedHandlerInputBecomesTerminal400(): void
    {
        $response = self::ingress('Greeter/greet', '{bad-json');

        self::assertSame(400, $response->status, "Greeter/greet with malformed input expected HTTP 400: {$response->body}");
        self::assertStringContainsString('"code":400', $response->body);
        self::assertStringContainsString('Invalid JSON payload: Syntax error', $response->body);
    }
}
