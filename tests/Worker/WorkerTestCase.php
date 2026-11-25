<?php

declare(strict_types=1);

namespace Restate\Tests\Worker;

use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\IntegrationEndpoint;
use Restate\Tests\Integration\Support\IntegrationTestCase;
use Restate\Tests\Integration\Support\WorkerProbe;

/**
 * Base class of the `worker` suite: tests that only mean something against the integration endpoint in FrankenPHP's
 * worker mode (RESTATE_PHP_TEST_SERVER=frankenphp-worker, `just test integration frankenphp-worker`). They fail, never skip,
 * under any other server.
 *
 * @phpstan-import-type WorkerStats from WorkerProbe
 */
abstract class WorkerTestCase extends IntegrationTestCase
{
    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::assertTrue(IntegrationEndpoint::isWorker(), 'The worker suite needs the worker-mode endpoint: run it with just test integration frankenphp-worker (RESTATE_PHP_TEST_SERVER=frankenphp-worker).');
    }

    /**
     * What the worker that serves this request says about itself (`GET /__worker`).
     *
     * @return WorkerStats
     */
    protected static function workerStats(): array
    {
        $response = HttpClient::get(self::restate()->endpointUrl() . WorkerProbe::PATH);
        self::assertSame(200, $response->status, "GET /__worker: {$response->body}");

        /** @var WorkerStats */
        return json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The worker script runs that recorded themselves in the state directory (WorkerProbe), by instance id.
     *
     * @return array<string, array{instance: string, pid: int, requests: int, startedAt: float}>
     */
    protected static function recordedWorkers(): array
    {
        $workers = [];
        $files = glob(self::stateDir() . '/workers/*.json');
        foreach ($files === false ? [] : $files as $file) {
            /** @var array{instance: string, pid: int, requests: int, startedAt: float} $worker */
            $worker = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $workers[$worker['instance']] = $worker;
        }

        return $workers;
    }
}
