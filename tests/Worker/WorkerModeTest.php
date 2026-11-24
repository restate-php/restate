<?php

declare(strict_types=1);

namespace Restate\Tests\Worker;

use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\WorkerProbe;
use Restate\Tests\Support\Workload\HttpDriver;
use Restate\Tests\Support\Workload\Workload;

/**
 * Guards against a silent fallback to classic mode: the worker suite runs after the integration suite on the same
 * shared endpoint (`bin/test-frankenphp.sh --worker`), and these tests check that it really was served by long-lived
 * worker script runs, each answering many requests from endpoints it built once, in one FrankenPHP process.
 */
final class WorkerModeTest extends WorkerTestCase
{
    private const int LONG_LIVED = 50;

    public function testTheEndpointIsServedByLongLivedWorkersInOneProcess(): void
    {
        // Normally the integration suite already made some worker serve hundreds of requests. Run alone, top it up.
        for ($i = 0; $i < 400 && max([0, ...array_column(self::recordedWorkers(), 'requests')]) <= self::LONG_LIVED; ++$i) {
            HttpClient::get(self::restate()->endpointUrl() . '/health');
        }

        $workers = self::recordedWorkers();
        self::assertNotEmpty($workers, 'No worker script recorded itself: the endpoint is not running worker.php in worker mode.');
        self::assertGreaterThan(self::LONG_LIVED, max(array_column($workers, 'requests')), 'No worker script run served more than ' . self::LONG_LIVED . ' requests: ' . json_encode($workers));
        self::assertCount(1, array_unique(array_column($workers, 'pid')), 'Every worker thread runs in the one FrankenPHP process: ' . json_encode($workers));
        self::assertTrue(self::workerStats()['threadSafe']);
    }

    public function testEveryResponseNamesTheWorkerThatServedIt(): void
    {
        $driver = new HttpDriver(self::restate()->endpointUrl());
        $last = [];
        $pid = null;
        for ($i = 0; $i < 40; ++$i) {
            $request = Workload::start(invocationId: 'inv_1workload' . bin2hex(random_bytes(8)));
            $response = $driver->send($request);
            self::assertTrue($response->isExpectedFor($request), $response->describe());

            $instance = $response->header(WorkerProbe::HEADER_INSTANCE);
            $requests = (int) $response->header(WorkerProbe::HEADER_REQUESTS);
            self::assertIsString($instance, 'The response does not name its worker.');
            // One FrankenPHP process (the container's first, or the devenv process), whichever worker thread answers.
            $pid ??= $response->header(WorkerProbe::HEADER_PID);
            self::assertIsString($pid, 'The response does not name its process.');
            self::assertSame($pid, $response->header(WorkerProbe::HEADER_PID), 'Every worker thread runs in the one FrankenPHP process.');
            self::assertGreaterThan($last[$instance] ?? 0, $requests, "Worker {$instance}'s request counter did not grow.");
            $last[$instance] = $requests;
        }
        self::assertLessThanOrEqual(count(self::recordedWorkers()), count($last));
    }

    /**
     * The FrankenPHP image ships no php.ini, so PHP displays errors (`display_errors=STDOUT`): every test of the
     * integration suite, StrayOutputTest and the noisy chaos handlers included, ran with displayed warnings and
     * deprecations, which the endpoint's output capture keeps out of the responses (docs/decisions/0032-stray-output.md).
     */
    public function testTheSuiteRanWithDisplayErrorsOn(): void
    {
        $ini = self::workerStats()['ini'];

        self::assertSame('1', $ini['display_errors']);
        self::assertSame('0', $ini['log_errors']);
    }
}
