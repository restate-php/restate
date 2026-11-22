<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Faults;

/**
 * Killing the PHP process that serves the current request, without taking the whole endpoint down.
 *
 * - Under `php -S` with `PHP_CLI_SERVER_WORKERS`, the master process accepts and serves requests too, and a worker
 *   that dies is never replaced: a SIGKILL that lands on the master kills the endpoint, and every SIGKILL shrinks the
 *   pool for good (restart the endpoint after a test that kills, `RestateTestEnvironment::restartEndpoint()`). So a
 *   process kills itself only when its parent is a `php -S` server, which makes it a worker.
 * - Under php-fpm the master never serves requests and replaces a dead worker at once, so a pool worker
 *   (`php-fpm: pool …`) always may; the front door answers the request it was serving with a 502.
 *
 * Anywhere else (the `php -S` master, FrankenPHP, whose PHP threads live in the server's own process) the kill
 * degrades to `exit`: the response ends there, which Restate sees the same way as a dead worker once some output was
 * written, and as an empty `200` otherwise.
 */
final class ProcessFaults
{
    private function __construct()
    {
    }

    /** Whether this process is a worker of a `php -S` server (Linux `/proc`), so a SIGKILL spares the server. */
    public static function isBuiltInServerWorker(): bool
    {
        $status = (string) @file_get_contents('/proc/self/status');
        if (preg_match('/^PPid:\s+(\d+)$/m', $status, $match) !== 1 || (int) $match[1] <= 1) {
            return false;
        }

        $parent = (string) @file_get_contents("/proc/{$match[1]}/cmdline");

        return str_contains(' ' . str_replace("\0", ' ', $parent) . ' ', ' -S ');
    }

    /** Whether this process is a php-fpm pool worker (Linux `/proc`), which its master replaces when it dies. */
    public static function isFpmWorker(): bool
    {
        return str_starts_with((string) @file_get_contents('/proc/self/cmdline'), 'php-fpm: pool ');
    }

    /** Whether this process may SIGKILL itself without taking the endpoint down: a `php -S` or a php-fpm worker. */
    public static function canKillItself(): bool
    {
        return self::isBuiltInServerWorker() || self::isFpmWorker();
    }

    /**
     * SIGKILL this process when that is safe (see the class description), else `exit`. Flush the output first if
     * the client should see what was written so far.
     */
    public static function killWorkerOrExit(): never
    {
        $pid = getmypid();
        if ($pid !== false && self::canKillItself()) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, 9);
            } else {
                $process = proc_open(['kill', '-9', (string) $pid], [], $pipes);
                if (is_resource($process)) {
                    proc_close($process);
                }
            }
            // The signal is delivered asynchronously; never run on meanwhile.
            sleep(10);
        }

        exit(0);
    }
}
