<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark;

use PhpBench\Attributes as Bench;
use Restate\Endpoint;
use Restate\ServiceDefinition;
use Restate\Tests\Benchmark\Support\BenchServices;
use Restate\Tests\Benchmark\Support\Expect;

/**
 * Discovery: the manifest Restate asks for when a deployment is registered, with every handler's JSON Schema inferred
 * from its PHP types. Measured on a fresh endpoint each time (registration under php-fpm builds one per request).
 */
#[Bench\Iterations(10)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('microseconds', precision: 2)]
final class DiscoveryBench
{
    private const string ACCEPT = 'application/vnd.restate.endpointmanifest.v4+json';

    /** @var list<ServiceDefinition> */
    private array $services = [];

    /**
     * The manifest of the integration suite's endpoint: about 30 services and 150 handlers of every kind.
     */
    #[Bench\BeforeMethods('setUpIntegrationServices')]
    #[Bench\Revs(20)]
    public function benchManifest(): void
    {
        self::discover($this->services);
    }

    public function setUpIntegrationServices(): void
    {
        /** @var list<ServiceDefinition> $services */
        $services = require dirname(__DIR__) . '/Integration/Support/services.php';
        $this->services = $services;
        Expect::status(200, self::discover($this->services), 'discovery');
    }

    /**
     * JSON Schema inference: one service whose handlers take and return a DTO three levels deep with 20 fields.
     */
    #[Bench\BeforeMethods('setUpDtoService')]
    #[Bench\Revs(200)]
    public function benchSchemaInference(): void
    {
        self::discover($this->services);
    }

    public function setUpDtoService(): void
    {
        $this->services = BenchServices::definitions(1);
        $response = self::discover($this->services);
        Expect::status(200, $response, 'discovery');
        if (!str_contains($response->body, '"postalCode"')) {
            throw new \RuntimeException('The manifest has no schema inferred from the Order DTO: ' . substr($response->body, 0, 300));
        }
    }

    /** @param list<ServiceDefinition> $services */
    private static function discover(array $services): \Restate\EndpointResponse
    {
        return Endpoint::builder()->services($services)->build()->handleHttpRequest('/discover', [['accept', self::ACCEPT]], '');
    }
}
