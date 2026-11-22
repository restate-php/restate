<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Services;

use Restate\HandlerExecution;
use Restate\HandlerInterceptor;
use Restate\Request;
use Restate\RunExecution;
use Restate\RunInterceptor;
use Restate\Suspension;

/**
 * Records what the Runtime Execution Hooks see, one line per event, to `hooks-<sha256(id)>.txt` under
 * RESTATE_PHP_TEST_STATE_DIR, where the id is the invocation's `restate-php-hook-id` header (HooksTest reads the file).
 */
final class HookRecorder implements HandlerInterceptor, RunInterceptor
{
    public function interceptHandler(HandlerExecution $execution, \Closure $next): void
    {
        self::record($execution->request, 'handler start replaying=' . ($execution->isReplaying() ? 'true' : 'false'));
        try {
            $next();
        } catch (\Throwable $e) {
            self::record($execution->request, Suspension::is($e) ? 'handler suspended' : 'handler failed ' . $e::class);

            throw $e;
        }
        self::record($execution->request, 'handler end');
    }

    public function interceptRun(RunExecution $execution, \Closure $next): void
    {
        self::record($execution->request, "run {$execution->name}");
        $next();
    }

    public static function file(string $stateDir, string $id): string
    {
        return $stateDir . '/hooks-' . hash('sha256', $id) . '.txt';
    }

    private static function record(Request $request, string $event): void
    {
        $id = $request->header('restate-php-hook-id');
        if ($id === null) {
            return;
        }

        $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR');
        if ($stateDir === false || $stateDir === '') {
            $stateDir = dirname(__DIR__, 3) . '/var';
        }
        file_put_contents(self::file($stateDir, $id), $event . "\n", FILE_APPEND | LOCK_EX);
    }
}
