<?php

declare(strict_types=1);

/*
 * FrankenPHP worker script for the integration suite's endpoint (RESTATE_PHP_TEST_SERVER=frankenphp-worker,
 * docker/frankenphp/worker.Caddyfile): the documented worker shape. The endpoints are built once per worker
 * (IntegrationEndpoints builds each on first use and keeps it), then every request is handed to them inside
 * frankenphp_handle_request(), with gc_collect_cycles() after each one.
 *
 * Every response carries the worker's identity (WorkerProbe::HEADER_*: a random instance id per worker script run,
 * the process id and the requests this run has served), and `/__worker` answers with the worker's statistics instead
 * of reaching the endpoints. A worker that is not running in FrankenPHP's worker mode refuses to serve at all, so a
 * silent fallback to classic mode fails every request instead of passing the suite.
 *
 * Environment (see TESTING.md):
 * - MAX_REQUESTS                      requests after which the script ends and FrankenPHP restarts it (0: never).
 * - RESTATE_PHP_TEST_SOAK_CANARY      `php` or `vm`: plant a leak after every request, for the soak suite's
 *                                     self-test (tests/Soak).
 */

use Restate\Tests\Integration\Support\IntegrationEndpoints;
use Restate\Tests\Integration\Support\WorkerProbe;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if (!function_exists('frankenphp_handle_request') || ($_SERVER['FRANKENPHP_WORKER'] ?? null) === null) {
    http_response_code(500);
    header('content-type: text/plain');
    echo "worker.php must run as a FrankenPHP worker script (frankenphp { worker … }), not as a classic script.\n";

    return;
}

$probe = WorkerProbe::start();
$endpoints = new IntegrationEndpoints();
$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);

$handler = static function () use ($probe, $endpoints): void {
    $probe->served();
    foreach ($probe->headers() as $name => $value) {
        header("{$name}: {$value}");
    }

    $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
    if (WorkerProbe::isProbe($uri)) {
        $probe->answer($uri);

        return;
    }

    $endpoints->serve($_SERVER, (string) file_get_contents('php://input'));
    $probe->afterRequest();
};

while ($maxRequests === 0 || $probe->requests() < $maxRequests) {
    $keepRunning = frankenphp_handle_request($handler);
    gc_collect_cycles();
    if (!$keepRunning) {
        break;
    }
}
