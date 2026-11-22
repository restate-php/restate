<?php

declare(strict_types=1);

/*
 * The instruction-count backstop of the PHP benchmarks: runs one subject $revs times inside a single array_map() call,
 * so that callgrind, started with --toggle-collect=zif_array_map, counts exactly those revolutions (after two
 * untimed warm-up calls, outside it). Wall-clock benchmarks on shared CI runners vary by 10-20%; instruction counts
 * barely move, so they catch a small regression the timings cannot. bin/bench-compare.sh runs it:
 *
 *   valgrind --tool=callgrind --trace-children=yes --toggle-collect=zif_array_map --callgrind-out-file=… \
 *       php -d extension=… -d opcache.enable_cli=0 tests/Benchmark/callgrind.php <subject> <revs>
 *
 * Subjects: greet-extension, replay-runs:100, cold-serve:20 (the same requests as EndpointBench::benchGreetExtension,
 * EndpointBench::benchReplay with runs=100, and ColdEndpointBench::benchColdServe with services=20). `--list` prints
 * them. Every response is checked once before the measured loop.
 */

use Restate\Endpoint;
use Restate\Tests\Benchmark\Support\BenchServices;
use Restate\Tests\Benchmark\Support\Expect;
use Restate\Tests\Support\Workload\InProcessDriver;
use Restate\Tests\Support\Workload\Workload;
use Restate\Tests\Support\Workload\WorkloadRequest;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$subjects = [
    'greet-extension' => static function (): Closure {
        $driver = InProcessDriver::forWorkloadServices();
        $request = Workload::start();
        Expect::expectedResponse($driver->send($request), $request);

        return static fn () => $driver->send($request);
    },
    'replay-runs:100' => static function (): Closure {
        $driver = InProcessDriver::forWorkloadServices();
        $request = Workload::replayRuns(100);
        Expect::expectedResponse($driver->send($request), $request);

        return static fn () => $driver->send($request);
    },
    'cold-serve:20' => static function (): Closure {
        $start = Workload::start();
        $request = new WorkloadRequest('start', '/invoke/Bench0/echo', $start->contentType, $start->invocationId, $start->body, $start->expectedFrames);
        $serve = static fn () => new InProcessDriver(Endpoint::builder()->services(BenchServices::definitions(20))->build())->send($request);
        Expect::expectedResponse($serve(), $request);

        return $serve;
    },
];

$subject = $argv[1] ?? '';
if ($subject === '--list') {
    echo implode("\n", array_keys($subjects)), "\n";
    exit(0);
}
$revs = (int) ($argv[2] ?? 0);
if (!isset($subjects[$subject]) || $revs < 1) {
    fwrite(STDERR, "Usage: php tests/Benchmark/callgrind.php <subject> <revs> | --list\nSubjects: " . implode(', ', array_keys($subjects)) . "\n");
    exit(2);
}

$run = $subjects[$subject]();
$run();
$run();
array_map(static fn (int $rev) => $run(), range(1, $revs));
