<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Workload;

/**
 * Sends requests over HTTP as Restate 1.8 does, many at a time, for soak and load runs: `POST` with the body streamed
 * in chunks and no `Content-Length` (HTTP/1.1 `Transfer-Encoding: chunked`, or HTTP/2 with prior knowledge, h2c), on
 * connections it keeps open (curl_multi's connection cache). {@see HttpDriver} sends one request per connection, which
 * is simpler and fine for a few thousand requests; at a million, its closed connections pile up in TIME_WAIT.
 *
 * A request that fails at the transport level (connection refused or reset, timeout) gets a response with status 0
 * and the curl error as its body, so a soak run counts it instead of stopping.
 */
final class ConcurrentHttpDriver implements WorkloadDriver
{
    private readonly string $baseUrl;

    private readonly \CurlMultiHandle $multi;

    /**
     * @param string $endpointUrl the endpoint as the test process reaches it, with its path if any
     * @param int<1, max> $concurrency requests in flight at once
     * @param ?\Closure(WorkloadRequest): list<array{0: string, 1: string}> $extraHeaders more headers for a request, such
     *                                                                       as Restate's request identity
     */
    public function __construct(
        string $endpointUrl,
        private readonly int $concurrency = 8,
        private readonly bool $http2 = false,
        private readonly int $chunkSize = 64 * 1024,
        private readonly float $timeoutSeconds = 30.0,
        private readonly ?\Closure $extraHeaders = null,
    ) {
        if (!str_starts_with($endpointUrl, 'http://')) {
            throw new \InvalidArgumentException("The endpoint URL must be http://host:port[/path], got {$endpointUrl}.");
        }
        $this->baseUrl = rtrim($endpointUrl, '/');
        $this->multi = curl_multi_init();
        curl_multi_setopt($this->multi, CURLMOPT_PIPELINING, $http2 ? CURLPIPE_MULTIPLEX : CURLPIPE_NOTHING);
        curl_multi_setopt($this->multi, CURLMOPT_MAXCONNECTS, max(1, $concurrency));
    }

    public function __destruct()
    {
        curl_multi_close($this->multi);
    }

    #[\Override]
    public function send(WorkloadRequest $request): WorkloadResponse
    {
        $response = null;
        $this->run([$request], static function (WorkloadRequest $sent, WorkloadResponse $answer) use (&$response): void {
            $response = $answer;
        });

        return $response ?? throw new \LogicException('The request got no response.');
    }

    /**
     * Sends every request, at most `$concurrency` at a time, and hands each response to `$onResponse` as it arrives
     * (with the request's duration in seconds). Returns once every response arrived.
     *
     * @param iterable<WorkloadRequest> $requests
     * @param \Closure(WorkloadRequest, WorkloadResponse, float): void $onResponse
     */
    public function run(iterable $requests, \Closure $onResponse): void
    {
        $pending = (static fn (): \Generator => yield from $requests)();
        /** @var array<int, array{\CurlHandle, WorkloadRequest}> $inFlight */
        $inFlight = [];
        $headers = [];

        $start = function (WorkloadRequest $request) use (&$inFlight, &$headers): void {
            $handle = $this->handle($request, $headers);
            $inFlight[spl_object_id($handle)] = [$handle, $request];
            curl_multi_add_handle($this->multi, $handle);
        };

        while (count($inFlight) < $this->concurrency && $pending->valid()) {
            $start($pending->current());
            $pending->next();
        }

        while ($inFlight !== []) {
            $status = curl_multi_exec($this->multi, $running);
            if ($status !== CURLM_OK) {
                throw new \RuntimeException('curl_multi_exec failed: ' . curl_multi_strerror($status));
            }
            if ($running > 0 && curl_multi_select($this->multi, 1.0) === -1) {
                usleep(1000);
            }

            while (($done = curl_multi_info_read($this->multi)) !== false) {
                $handle = $done['handle'];
                $id = spl_object_id($handle);
                [, $request] = $inFlight[$id];
                unset($inFlight[$id]);

                $response = $done['result'] === CURLE_OK
                    ? new WorkloadResponse(curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $headers[$id] ?? [], (string) curl_multi_getcontent($handle))
                    : new WorkloadResponse(0, [], 'curl error: ' . curl_error($handle));
                $seconds = curl_getinfo($handle, CURLINFO_TOTAL_TIME);
                unset($headers[$id]);
                curl_multi_remove_handle($this->multi, $handle);

                if ($pending->valid()) {
                    $start($pending->current());
                    $pending->next();
                }
                $onResponse($request, $response, $seconds);
            }
        }
    }

    /** @param array<int, list<array{0: string, 1: string}>> $headers where the response headers go, by handle */
    private function handle(WorkloadRequest $request, array &$headers): \CurlHandle
    {
        $handle = curl_init($this->baseUrl . $request->path);
        $id = spl_object_id($handle);
        $headers[$id] = [];
        $offset = 0;
        $body = $request->body;
        $chunkSize = $this->chunkSize;

        $requestHeaders = ['Expect:'];
        foreach ([...$request->headers(), ...($this->extraHeaders === null ? [] : ($this->extraHeaders)($request))] as [$name, $value]) {
            $requestHeaders[] = "{$name}: {$value}";
        }
        if (!$this->http2) {
            $requestHeaders[] = 'Transfer-Encoding: chunked';
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HTTP_VERSION => $this->http2 ? CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE : CURL_HTTP_VERSION_1_1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ($this->timeoutSeconds * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->timeoutSeconds * 1000),
            CURLOPT_READFUNCTION => static function ($curl, $stream, int $length) use (&$offset, $body, $chunkSize): string {
                $chunk = substr($body, $offset, min($length, $chunkSize));
                $offset += strlen($chunk);

                return $chunk;
            },
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $line) use (&$headers, $id): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[$id][] = [strtolower(trim($parts[0])), trim($parts[1])];
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $headers[$id] = [];
                }

                return strlen($line);
            },
        ]);

        return $handle;
    }
}
