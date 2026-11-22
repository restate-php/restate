<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark;

use CuyZ\Valinor\MapperBuilder;
use CuyZ\Valinor\Normalizer\Format;
use CuyZ\Valinor\NormalizerBuilder;
use PhpBench\Attributes as Bench;
use Restate\Bridge\Symfony\SymfonyJsonPayloadAdapter;
use Restate\Bridge\Valinor\ValinorJsonPayloadAdapter;
use Restate\Endpoint;
use Restate\JsonPayloadOptions;
use Restate\Tests\Benchmark\Support\BenchServices;
use Restate\Tests\Benchmark\Support\Dto\Order;
use Restate\Tests\Benchmark\Support\Expect;
use Restate\Tests\Benchmark\Support\ScriptedBackend;
use Restate\Tests\Support\Workload\InProcessDriver;
use Restate\Tests\Support\Workload\Workload;
use Restate\Tests\Support\Workload\WorkloadRequest;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * DTO mapping: a handler takes an {@see Order} (three levels, 20 fields) and returns it, so the input is hydrated and
 * the output normalized, on a scripted invocation (no VM). Per mapper: the built-in one, Symfony's serializer and
 * Valinor (without a cache); cold builds the mapper and the endpoint for every request (php-fpm, `php -S`), warm
 * reuses them (a long-lived worker).
 */
#[Bench\Iterations(10)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('microseconds', precision: 2)]
final class JsonPayloadBench
{
    private ScriptedBackend $backend;

    private InProcessDriver $driver;

    private WorkloadRequest $request;

    /** @param array{mapper: string, warm: bool} $params */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\ParamProviders('cases')]
    #[Bench\Revs(100)]
    public function benchMapOrder(array $params): void
    {
        $driver = $params['warm'] ? $this->driver : $this->driver($params['mapper']);
        $driver->send($this->request);
    }

    /** @param array{mapper: string, warm: bool} $params */
    public function setUp(array $params): void
    {
        $this->backend = new ScriptedBackend(Order::exampleJson());
        $start = Workload::start();
        $this->request = new WorkloadRequest('place', '/invoke/Bench0/place', $start->contentType, $start->invocationId, $start->body, []);
        $this->driver = $this->driver($params['mapper']);

        Expect::same(200, $this->driver->send($this->request)->status, "{$params['mapper']} mapping status");
        Expect::same(json_decode(Order::exampleJson(), true), json_decode($this->backend->lastOutput(), true), "{$params['mapper']} mapping output");
    }

    /** @return \Generator<string, array{mapper: string, warm: bool}> */
    public function cases(): \Generator
    {
        foreach (['default', 'symfony', 'valinor'] as $mapper) {
            yield "{$mapper},cold" => ['mapper' => $mapper, 'warm' => false];
            yield "{$mapper},warm" => ['mapper' => $mapper, 'warm' => true];
        }
    }

    private function driver(string $mapper): InProcessDriver
    {
        return new InProcessDriver(Endpoint::create($this->backend, BenchServices::definitions(1), jsonPayloads: self::options($mapper)));
    }

    private static function options(string $mapper): JsonPayloadOptions
    {
        return match ($mapper) {
            'default' => JsonPayloadOptions::defaults(),
            'symfony' => JsonPayloadOptions::defaults()->withPayloadAdapter(new SymfonyJsonPayloadAdapter(self::symfonySerializer())),
            'valinor' => JsonPayloadOptions::defaults()->withPayloadAdapter(new ValinorJsonPayloadAdapter(
                new MapperBuilder()->mapper(),
                new NormalizerBuilder()->normalizer(Format::array()),
            )),
            default => throw new \InvalidArgumentException("Unknown mapper {$mapper}"),
        };
    }

    private static function symfonySerializer(): Serializer
    {
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());

        return new Serializer([
            new ObjectNormalizer($metadataFactory, new MetadataAwareNameConverter($metadataFactory), propertyTypeExtractor: new ReflectionExtractor()),
        ]);
    }
}
