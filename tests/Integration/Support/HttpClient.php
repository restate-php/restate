<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Support;

/**
 * Minimal curl-based HTTP client for driving Restate and the test endpoint.
 *
 * Transport failures (connection refused, timeout) throw HttpTransportException;
 * any HTTP status, including 4xx/5xx, is returned as an HttpResponse.
 */
final class HttpClient
{
    /** @param array<string, string> $headers */
    public static function get(string $url, array $headers = [], float $timeout = 15.0): HttpResponse
    {
        return self::execute(self::handle('GET', $url, null, $headers, $timeout));
    }

    /**
     * POST a JSON body, like `curl --json`. A null body sends an empty request without a
     * content type, as Restate requires for handlers that declare no input.
     *
     * @param array<string, string> $headers
     */
    public static function postJson(string $url, ?string $json, array $headers = [], float $timeout = 15.0): HttpResponse
    {
        return self::execute(self::handle('POST', $url, $json, self::jsonHeaders($headers, $json !== null), $timeout));
    }

    /**
     * POST raw bytes with exactly the given headers (names and values are sent as given, byte for byte).
     *
     * @param array<string, string> $headers
     */
    public static function post(string $url, string $body, array $headers, float $timeout = 15.0): HttpResponse
    {
        return self::execute(self::handle('POST', $url, $body, $headers, $timeout));
    }

    /**
     * PATCH without a body (for example a Restate admin API action).
     *
     * @param array<string, string> $headers
     */
    public static function patch(string $url, array $headers = [], float $timeout = 15.0): HttpResponse
    {
        return self::execute(self::handle('PATCH', $url, null, $headers, $timeout));
    }

    /**
     * Start a JSON POST without waiting for the response.
     *
     * @param array<string, string> $headers
     */
    public static function startPostJson(string $url, ?string $json, array $headers = [], float $timeout = 15.0): PendingHttpRequest
    {
        return new PendingHttpRequest(self::handle('POST', $url, $json, self::jsonHeaders($headers, $json !== null), $timeout));
    }

    /** @param array<string, string> $headers */
    /**
     * @param non-empty-string $method
     * @param array<string, string> $headers
     */
    private static function handle(string $method, string $url, ?string $body, array $headers, float $timeout): \CurlHandle
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new HttpTransportException("curl_init failed for {$url}");
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_NOPROXY => '*',
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        return $handle;
    }

    public static function response(\CurlHandle $handle, string|false|null $body, int $errno): HttpResponse
    {
        if ($errno !== 0 || !is_string($body)) {
            $error = curl_error($handle);
            if ($error === '') {
                $error = curl_strerror($errno);
            }

            throw new HttpTransportException(sprintf(
                'Request to %s failed: %s',
                curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
                $error ?? 'unknown error',
            ));
        }

        return new HttpResponse(curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function jsonHeaders(array $headers, bool $hasBody): array
    {
        return ($hasBody ? ['content-type' => 'application/json'] : []) + ['accept' => 'application/json'] + $headers;
    }

    private static function execute(\CurlHandle $handle): HttpResponse
    {
        $body = curl_exec($handle);

        return self::response($handle, is_string($body) ? $body : false, curl_errno($handle));
    }
}
