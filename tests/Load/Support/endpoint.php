<?php

declare(strict_types=1);

/*
 * Router script of the load-test endpoint (bin/load-test.sh): builds the endpoint for each request, as under FPM or
 * FrankenPHP classic mode, with the identity keys from LOAD_IDENTITY_KEYS (comma-separated; Restate signs every
 * request in a load run), and appends one line per request to LOAD_REQUEST_LOG:
 * `<invocation id or -> <status> <microseconds> <path>`. The runner counts attempts per invocation id and 401s from
 * it, and sums the microseconds (from request start, so autoloading and building the endpoint are included, until the
 * response is ready) as the SDK side of the time split.
 *
 * `GET /load-info` (not a Restate route) answers the PHP settings and OPcache statistics, which the runner records in
 * the result, so a result shows that OPcache was on and the code cached.
 */

use Restate\Endpoint;
use Restate\Tests\Load\Support\LoadServices;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if (($_SERVER['REQUEST_URI'] ?? '') === '/load-info') {
    $opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
    $settings = ['opcache.enable', 'opcache.jit', 'opcache.validate_timestamps', 'display_errors', 'zend.assertions', 'memory_limit', 'enable_post_data_reading'];
    header('content-type: application/json');
    echo json_encode([
        'php' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'zts' => PHP_ZTS,
        'extension' => phpversion('restate'),
        'ini' => array_combine($settings, array_map(ini_get(...), $settings)),
        'opcache' => is_array($opcache) ? ['enabled' => $opcache['opcache_enabled'] ?? null, 'statistics' => $opcache['opcache_statistics'] ?? null] : null,
    ], JSON_THROW_ON_ERROR);

    return;
}

$keys = getenv('LOAD_IDENTITY_KEYS');
$builder = Endpoint::builder()->services(LoadServices::definitions());
if (is_string($keys) && $keys !== '') {
    $builder = $builder->identityKeys(explode(',', $keys));
}

$response = $builder->build()->handleRequest($_SERVER, (string) file_get_contents('php://input'));

$started = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
$micros = is_float($started) ? (int) round((microtime(true) - $started) * 1_000_000) : -1;

http_response_code($response->statusCode);
foreach ($response->headers as [$name, $value]) {
    header("{$name}: {$value}");
}
echo $response->body;

$log = getenv('LOAD_REQUEST_LOG');
if (is_string($log) && $log !== '') {
    $invocationId = $_SERVER['HTTP_X_RESTATE_INVOCATION_ID'] ?? '-';
    $path = $_SERVER['REQUEST_URI'] ?? '/';
    file_put_contents($log, sprintf("%s %d %d %s\n", is_string($invocationId) ? $invocationId : '-', $response->statusCode, $micros, is_string($path) ? $path : '/'), FILE_APPEND);
}
