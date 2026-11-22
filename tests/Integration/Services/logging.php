<?php

declare(strict_types=1);

use Restate\Context;
use Restate\InvocationRetryPolicy;
use Restate\Service;

/*
 * The SDK's logging (LoggingTest). `failOnce` takes a request id, logs through the handler's logger, journals a run,
 * and fails its first attempt with a plain exception; the retry replays the run and returns the invocation id. Attempts
 * are counted in `logging-<sha256(id)>.txt` under RESTATE_PHP_TEST_STATE_DIR.
 */

$attemptFile = static function (string $id): string {
    $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR');
    if (!is_string($stateDir) || $stateDir === '') {
        $stateDir = dirname(__DIR__, 3) . '/var';
    }
    if (!is_dir($stateDir)) {
        mkdir($stateDir, 0o777, true);
    }

    return $stateDir . '/logging-' . hash('sha256', $id) . '.txt';
};

return Service::new('Logging')
    ->handler(
        'failOnce',
        static function (Context $ctx, string $id) use ($attemptFile): string {
            // Logged by the first attempt only: the retry replays up to the run.
            $ctx->logger()->info('failOnce started', ['request' => $id]);
            $ctx->run('step', static fn (): string => 'done');

            $file = $attemptFile($id);
            file_put_contents($file, "attempt\n", FILE_APPEND | LOCK_EX);
            $attempts = file($file);
            if ($attempts === false || count($attempts) === 1) {
                throw new \RuntimeException("first attempt of {$id} fails");
            }

            return $ctx->request()->id;
        },
        invocationRetryPolicy: new InvocationRetryPolicy(initialIntervalMillis: 100, exponentiationFactor: 1.0),
    );
