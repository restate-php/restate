<?php

/*
 * FrankenPHP's worker-mode function, for PHPStan (tests/Integration/Support/worker.php calls it). FrankenPHP defines
 * it; this file is never loaded.
 */

/**
 * Waits for the next request, runs `$callback` with its superglobals, output and headers, and returns false once the
 * worker should stop (FrankenPHP is shutting down, or restarting the worker).
 *
 * @param callable(): mixed $callback
 */
function frankenphp_handle_request(callable $callback): bool
{
}
