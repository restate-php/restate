<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark\Support;

use Restate\EndpointResponse;
use Restate\Tests\Support\Workload\WorkloadRequest;
use Restate\Tests\Support\Workload\WorkloadResponse;

/**
 * Checks a benchmark's setup once before it is timed: a benchmark whose request went wrong (an error frame, a 500)
 * would time the wrong path and still look fine.
 */
final class Expect
{
    private function __construct()
    {
    }

    public static function expectedResponse(WorkloadResponse $response, WorkloadRequest $request): void
    {
        if (!$response->isExpectedFor($request)) {
            throw new \RuntimeException("Benchmark request {$request->class} got an unexpected response: {$response->describe()}");
        }
    }

    public static function status(int $status, EndpointResponse $response, string $what): void
    {
        if ($response->statusCode !== $status) {
            throw new \RuntimeException("{$what}: expected HTTP {$status}, got {$response->statusCode}: " . substr($response->body, 0, 300));
        }
    }

    public static function same(mixed $expected, mixed $actual, string $what): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException("{$what}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }
}
