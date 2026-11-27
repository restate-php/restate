<?php

declare(strict_types=1);

// FrankenPHP worker mode (docker/frankenphp/conformance-worker.Caddyfile), the shape docs/serving.md documents: the
// endpoint is built once per worker and serves every request the worker handles.

if (!function_exists('frankenphp_handle_request') || ($_SERVER['FRANKENPHP_WORKER'] ?? null) === null) {
    http_response_code(500);
    header('content-type: text/plain');
    echo "worker.php must run as a FrankenPHP worker script (frankenphp { worker … }), not as a classic script.\n";

    return;
}

$endpoint = require __DIR__ . '/endpoint.php';

$handler = static function () use ($endpoint): void {
    $endpoint->handle($_SERVER, (string) file_get_contents('php://input'));
};

while (frankenphp_handle_request($handler)) {
    gc_collect_cycles();
}
