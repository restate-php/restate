<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark\Support;

use Restate\Context;
use Restate\Service;
use Restate\ServiceDefinition;
use Restate\Tests\Benchmark\Support\Dto\Order;

/**
 * Services the benchmarks build endpoints from, as an application defines them: `Bench0` … `Bench{n-1}`, each with
 *
 * - `echo(string): string`, which returns its input;
 * - `place(Order): Order`, which returns its input DTO (mapped in and out);
 * - `count(int): int`, which adds one.
 */
final class BenchServices
{
    private function __construct()
    {
    }

    /** @return list<ServiceDefinition> */
    public static function definitions(int $count): array
    {
        $services = [];
        for ($i = 0; $i < $count; $i++) {
            $services[] = Service::new("Bench{$i}")
                ->handler('echo', static fn (Context $ctx, string $input): string => $input)
                ->handler('place', static fn (Context $ctx, Order $order): Order => $order)
                ->handler('count', static fn (Context $ctx, int $value): int => $value + 1)
                ->definition();
        }

        return $services;
    }
}
