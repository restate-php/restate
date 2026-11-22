<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark;

use PhpBench\Attributes as Bench;
use Restate\Endpoint;
use Restate\Tests\Benchmark\Support\BenchServices;
use Restate\Tests\Benchmark\Support\Expect;
use Restate\Tests\Support\Workload\InProcessDriver;
use Restate\Tests\Support\Workload\Workload;
use Restate\Tests\Support\Workload\WorkloadRequest;

/**
 * The per-request bootstrap of php-fpm and `php -S`, which build the endpoint for every request: define N services,
 * build the endpoint (registry, handler reflection), then serve one request. Classes are loaded and compiled after the
 * first revolution, as OPcache keeps them across requests in production, so this is the SDK's own construction cost.
 */
#[Bench\Iterations(10)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('microseconds', precision: 2)]
final class ColdEndpointBench
{
    private WorkloadRequest $request;

    /** @param array{services: int} $params */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\ParamProviders('serviceCounts')]
    #[Bench\Revs(50)]
    public function benchColdServe(array $params): void
    {
        $endpoint = Endpoint::builder()->services(BenchServices::definitions($params['services']))->build();
        new InProcessDriver($endpoint)->send($this->request);
    }

    /** @param array{services: int} $params */
    public function setUp(array $params): void
    {
        $start = Workload::start();
        $this->request = new WorkloadRequest('start', '/invoke/Bench0/echo', $start->contentType, $start->invocationId, $start->body, $start->expectedFrames);
        $endpoint = Endpoint::builder()->services(BenchServices::definitions($params['services']))->build();
        Expect::expectedResponse(new InProcessDriver($endpoint)->send($this->request), $this->request);
    }

    /** @return \Generator<string, array{services: int}> */
    public function serviceCounts(): \Generator
    {
        foreach ([1, 20, 100] as $services) {
            yield "services={$services}" => ['services' => $services];
        }
    }
}
