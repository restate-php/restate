<?php

declare(strict_types=1);

use Restate\Context;
use Restate\InvocationRetryPolicy;
use Restate\RetryableError;
use Restate\RunRetryPolicy;
use Restate\Service;
use Restate\Target;
use Restate\TerminalError;

/*
 * Retry semantics for the integration suite. Each handler takes a request id and appends one line per attempt, the
 * time in milliseconds, to `retries-<sha256(id)>.txt` under RESTATE_PHP_TEST_STATE_DIR, so a test can measure when
 * Restate retried.
 */

$attemptFile = static function (string $id): string {
    $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR');
    if (!is_string($stateDir) || $stateDir === '') {
        $stateDir = dirname(__DIR__, 3) . '/var';
    }
    if (!is_dir($stateDir)) {
        mkdir($stateDir, 0o777, true);
    }

    return $stateDir . '/retries-' . hash('sha256', $id) . '.txt';
};

/** Records an attempt and returns how many there were, this one included. */
$recordAttempt = static function (string $file): int {
    file_put_contents($file, (int) (microtime(true) * 1000) . "\n", FILE_APPEND | LOCK_EX);

    $lines = file($file, FILE_IGNORE_NEW_LINES);

    return $lines === false ? 0 : count($lines);
};

return Service::new('Retries')
    // A run whose first attempt asks for a retry after `retryAfterMillis`, under a policy that retries after 100 ms.
    ->handler('runRetryAfter', static fn (Context $ctx, array $request): int => $ctx->run(
        'rate-limited',
        static function () use ($request, $attemptFile, $recordAttempt): int {
            $attempts = $recordAttempt($attemptFile((string) $request['id']));
            if ($attempts === 1) {
                throw new RetryableError('rate limited', retryAfterMillis: (int) $request['retryAfterMillis']);
            }

            return $attempts;
        },
        RunRetryPolicy::fixedDelay(100, maxAttempts: 5),
    ))
    // A handler whose first attempt asks for a retry after `retryAfterMillis`, under an invocation retry policy that
    // retries after 100 ms.
    ->handler(
        'handlerRetryAfter',
        static function (Context $ctx, array $request) use ($attemptFile, $recordAttempt): int {
            $attempts = $recordAttempt($attemptFile((string) $request['id']));
            if ($attempts === 1) {
                throw new RetryableError('rate limited', retryAfterMillis: (int) $request['retryAfterMillis']);
            }

            return $attempts;
        },
        invocationRetryPolicy: new InvocationRetryPolicy(initialIntervalMillis: 100, exponentiationFactor: 1.0),
    )
    // A run that fails every attempt with a 503 and metadata, under a policy that allows two attempts.
    ->handler('runExhausted', static function (Context $ctx, array $request) use ($attemptFile, $recordAttempt): void {
        $ctx->run(
            'unavailable',
            static function () use ($request, $attemptFile, $recordAttempt): never {
                $attempts = $recordAttempt($attemptFile((string) $request['id']));

                throw new RetryableError("unavailable (attempt {$attempts})", code: 503, metadata: ['retry-reason' => 'maintenance', '503' => 'numeric key']);
            },
            RunRetryPolicy::fixedDelay(10, maxAttempts: 2),
        );
    })
    // Calls runExhausted and reports the terminal failure it observed.
    ->handler('reportRunExhausted', static function (Context $ctx, array $request): array {
        try {
            $ctx->call(Target::service('Retries', 'runExhausted'), $request);
        } catch (TerminalError $e) {
            return ['code' => $e->statusCode(), 'message' => $e->getMessage(), 'metadata' => (object) $e->metadata()];
        }

        throw new TerminalError('runExhausted did not fail.', 500);
    })
    ->definition();
