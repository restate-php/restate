<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Restate\Testing\EndpointContainer;
use Restate\Tests\Integration\Support\IntegrationEndpoint;
use Restate\Tests\Integration\Support\IntegrationTestCase;

/**
 * How the server under test (RESTATE_PHP_TEST_SERVER) serves the endpoint: Restate reaches it over the HTTP version it
 * is registered for (h2c for nginx-fpm-h2c and caddy-fpm), what PHP sees of Restate's length-less request bodies, and
 * the endpoint's own answers to direct requests, which a front door must pass on unchanged (no compression of
 * protocol bytes, exact content types).
 */
#[Group('runtime')]
final class ServingTest extends IntegrationTestCase
{
    public function testRestateReachesTheEndpointOverTheRegisteredProtocol(): void
    {
        $endpoint = IntegrationEndpoint::get();
        $http2 = $endpoint instanceof EndpointContainer && $endpoint->http2;

        $settings = json_decode(self::invoke('LargeBodies/settings', null), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($settings);
        self::assertSame($http2 ? 'HTTP/2.0' : 'HTTP/1.1', $settings['protocol']);

        $server = IntegrationEndpoint::server();
        if (isset(IntegrationEndpoint::FPM_SERVERS[$server])) {
            // Restate sends no Content-Length (chunked over HTTP/1.1, DATA frames over h2c); the FastCGI front door
            // buffers the body and sends one, and php-fpm reads no more than CONTENT_LENGTH bytes. (php -S sets none.)
            self::assertIsString($settings['contentLength']);
            self::assertGreaterThan(0, (int) $settings['contentLength']);
            self::assertSame('fpm-fcgi', $settings['sapi']);
            self::assertTrue($settings['opcache'], 'OPcache is off under php-fpm.');
            self::assertFalse($settings['displayErrors']);
            self::assertFalse($settings['enablePostDataReading']);
        }
    }

    public function testDirectRequestsGetTheEndpointsOwnAnswers(): void
    {
        $base = self::restate()->endpointUrl();

        [$status, $headers] = self::request('GET', $base . '/health');
        self::assertSame(200, $status);
        self::assertSame('application/json', $headers['content-type'] ?? null);

        // A front door that compresses (nginx gzip, Caddy encode) must leave the manifest alone.
        [$status, $headers, $body] = self::request('GET', $base . '/discover', ['accept: application/vnd.restate.endpointmanifest.v4+json', 'accept-encoding: gzip']);
        self::assertSame(200, $status);
        self::assertSame('application/vnd.restate.endpointmanifest.v4+json', $headers['content-type'] ?? null);
        self::assertArrayNotHasKey('content-encoding', $headers);
        self::assertStringContainsString('"LargeBodies"', $body);

        [$status] = self::request('GET', $base . '/no/such/path');
        self::assertSame(404, $status);

        // `Content-Type:` with no value: curl sends none (it would add a form content type for a POST body).
        [$status, $headers, $body] = self::request('POST', $base . '/invoke/Greeter/greet', ['Content-Type:'], 'body');
        self::assertSame('application/json', $headers['content-type'] ?? null);
        if (IntegrationEndpoint::server() === 'caddy-fpm') {
            // Caddy's FastCGI transport gives a POST without a content type a form content type, which the VM refuses.
            // Restate always sends its own.
            self::assertSame(500, $status, $body);
            self::assertStringContainsString("unrecognized content-type 'application\\/x-www-form-urlencoded'", $body);
        } else {
            self::assertSame(415, $status, $body);
        }
    }

    /**
     * @param non-empty-string $method
     * @param list<string>     $headers
     *
     * @return array{int, array<string, string>, string} status, response headers (lower-cased names), body
     */
    private static function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $handle = curl_init($url);
        self::assertNotFalse($handle);
        $responseHeaders = [];
        curl_setopt_array($handle, ($body === null ? [] : [CURLOPT_POSTFIELDS => $body]) + [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);
        $response = curl_exec($handle);
        self::assertIsString($response, curl_error($handle));

        return [curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseHeaders, $response];
    }
}
