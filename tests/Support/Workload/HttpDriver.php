<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Workload;

/**
 * Sends requests over HTTP/1.1 the way Restate 1.8 does: `POST` with `Transfer-Encoding: chunked` and no
 * `Content-Length`, the body in chunks. One connection per request (`Connection: close`), so it works against
 * `php -S`, which closes every connection, as well as against servers that keep them open.
 */
final readonly class HttpDriver implements WorkloadDriver
{
    private string $host;

    private int $port;

    private string $basePath;

    /** @var int<1, max> */
    private int $chunkSize;

    /**
     * @param string $endpointUrl the endpoint as the test process reaches it, with its path if any:
     *                            `RestateTestEnvironment::endpointUrl()`
     */
    public function __construct(
        string $endpointUrl,
        int $chunkSize = 64 * 1024,
        private float $timeoutSeconds = 30.0,
    ) {
        $parts = parse_url($endpointUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'http' || !isset($parts['host'], $parts['port'])) {
            throw new \InvalidArgumentException("The endpoint URL must be http://host:port[/path], got {$endpointUrl}.");
        }
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException("The chunk size must be positive, got {$chunkSize}.");
        }

        $this->host = $parts['host'];
        $this->port = $parts['port'];
        $this->basePath = rtrim($parts['path'] ?? '', '/');
        $this->chunkSize = max(1, $chunkSize);
    }

    #[\Override]
    public function send(WorkloadRequest $request): WorkloadResponse
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errorCode, $errorMessage, $this->timeoutSeconds);
        if ($socket === false) {
            throw new \RuntimeException("Cannot connect to {$this->host}:{$this->port}: {$errorMessage}");
        }

        try {
            stream_set_timeout($socket, (int) ceil($this->timeoutSeconds));
            $head = "POST {$this->basePath}{$request->path} HTTP/1.1\r\nHost: {$this->host}:{$this->port}\r\n";
            foreach ($request->headers() as [$name, $value]) {
                $head .= "{$name}: {$value}\r\n";
            }
            self::write($socket, $head . "transfer-encoding: chunked\r\nconnection: close\r\n\r\n");
            // str_split('') is [] (PHP 8.2+): an empty body is just the last chunk.
            foreach (str_split($request->body, $this->chunkSize) as $chunk) {
                self::write($socket, dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n");
            }
            self::write($socket, "0\r\n\r\n");

            $raw = (string) stream_get_contents($socket);
            if (stream_get_meta_data($socket)['timed_out']) {
                throw new \RuntimeException("Timed out after {$this->timeoutSeconds}s waiting for the response to {$request->path}.");
            }
        } finally {
            fclose($socket);
        }

        return self::parse($raw);
    }

    /**
     * An HTTP/1.1 response read to the end of the connection: status, headers, and the body (de-chunked, or cut to
     * `Content-Length`, or everything up to the close).
     */
    public static function parse(string $raw): WorkloadResponse
    {
        $end = strpos($raw, "\r\n\r\n");
        if ($end === false || preg_match('#^HTTP/1\.[01] (\d{3})#', $raw, $status) !== 1) {
            throw new \RuntimeException('Malformed or empty HTTP response: ' . json_encode(substr($raw, 0, 200), JSON_INVALID_UTF8_SUBSTITUTE));
        }

        $headers = [];
        foreach (array_slice(explode("\r\n", substr($raw, 0, $end)), 1) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[] = [strtolower(trim($name)), trim($value)];
        }
        $body = substr($raw, $end + 4);

        $response = new WorkloadResponse((int) $status[1], $headers, $body);
        if (strtolower($response->header('transfer-encoding') ?? '') === 'chunked') {
            return new WorkloadResponse($response->status, $headers, self::dechunk($body));
        }
        $length = $response->header('content-length');

        return $length === null ? $response : new WorkloadResponse($response->status, $headers, substr($body, 0, (int) $length));
    }

    private static function dechunk(string $body): string
    {
        $decoded = '';
        $offset = 0;
        while (($lineEnd = strpos($body, "\r\n", $offset)) !== false) {
            $size = (int) hexdec(trim(explode(';', substr($body, $offset, $lineEnd - $offset))[0]));
            if ($size === 0) {
                break;
            }
            $decoded .= substr($body, $lineEnd + 2, $size);
            $offset = $lineEnd + 2 + $size + 2;
        }

        return $decoded;
    }

    /** @param resource $socket */
    private static function write($socket, string $bytes): void
    {
        while ($bytes !== '') {
            $written = @fwrite($socket, $bytes);
            if ($written === false || $written === 0) {
                throw new \RuntimeException('The endpoint closed the connection while the request was being sent.');
            }
            $bytes = substr($bytes, $written);
        }
    }
}
