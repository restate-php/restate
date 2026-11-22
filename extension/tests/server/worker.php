<?php

declare(strict_types=1);

// A FrankenPHP worker (flake.nix, checks.frankenphp): the script stays loaded, and runs an invocation per request on
// one of PHP's threads.

require __DIR__ . '/invoke.php';

$handler = static function (): void {
    echo restate_invoke($_GET['name'] ?? 'FrankenPHP');
};

while (frankenphp_handle_request($handler)) {
    gc_collect_cycles();
}
