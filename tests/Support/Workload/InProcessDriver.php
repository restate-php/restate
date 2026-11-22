<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Workload;

use Restate\Endpoint;

/**
 * Hands requests straight to `Endpoint::handleHttpRequest()` in this process: no HTTP, no `$_SERVER`, what a
 * benchmark of the SDK's own cost wants. With the extension backend it needs the extension loaded.
 */
final readonly class InProcessDriver implements WorkloadDriver
{
    public function __construct(private Endpoint $endpoint)
    {
    }

    /** An endpoint serving only {@see WorkloadServices}, on the default (extension) backend. */
    public static function forWorkloadServices(): self
    {
        return new self(Endpoint::builder()->services(WorkloadServices::definitions())->build());
    }

    #[\Override]
    public function send(WorkloadRequest $request): WorkloadResponse
    {
        $response = $this->endpoint->handleHttpRequest($request->path, $request->headers(), $request->body);
        $headers = array_map(static fn (array $header): array => [strtolower($header[0]), $header[1]], $response->headers);

        return new WorkloadResponse($response->statusCode, $headers, $response->body);
    }
}
