<?php

declare(strict_types=1);

use Restate\Context;
use Restate\OnMaxAttempts;
use Restate\PauseError;
use Restate\RetryableError;
use Restate\RunRetryPolicy;
use Restate\Service;

/*
 * Pausing the invocation (PauseTest). Each handler takes a request id, appends one line per attempt to
 * `pauses-<sha256(id)>.txt` under RESTATE_PHP_TEST_STATE_DIR, and pauses (or fails) until the test "fixes" the
 * condition by creating `pauses-<sha256(id)>.fixed` there. It then returns the number of attempts.
 */

$file = static function (string $id, string $extension): string {
    $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR');
    if (!is_string($stateDir) || $stateDir === '') {
        $stateDir = dirname(__DIR__, 3) . '/var';
    }
    if (!is_dir($stateDir)) {
        mkdir($stateDir, 0o777, true);
    }

    return $stateDir . '/pauses-' . hash('sha256', $id) . '.' . $extension;
};

/** Records an attempt; returns whether the condition is fixed and the number of attempts, this one included. */
$attempt = static function (string $id) use ($file): array {
    $attempts = $file($id, 'txt');
    file_put_contents($attempts, (int) (microtime(true) * 1000) . "\n", FILE_APPEND | LOCK_EX);

    $lines = file($attempts, FILE_IGNORE_NEW_LINES);

    return [is_file($file($id, 'fixed')), $lines === false ? 0 : count($lines)];
};

return Service::new('Pauses')
    // The handler itself asks to pause.
    ->handler('handler', static function (Context $ctx, string $id) use ($attempt): int {
        [$fixed, $attempts] = $attempt($id);

        return $fixed ? $attempts : throw new PauseError("handler waits for a fix of {$id}");
    })
    // A run asks to pause; its policy would retry it (and give up after five attempts) instead.
    ->handler('run', static fn (Context $ctx, string $id): int => $ctx->run(
        'approve',
        static function () use ($attempt, $id): int {
            [$fixed, $attempts] = $attempt($id);

            return $fixed ? $attempts : throw new PauseError("run waits for approval of {$id}");
        },
        RunRetryPolicy::fixedDelay(10, maxAttempts: 5),
    ))
    // A run whose policy pauses after two failed attempts.
    ->handler('runExhausted', static fn (Context $ctx, string $id): int => $ctx->run(
        'charge',
        static function () use ($attempt, $id): int {
            [$fixed, $attempts] = $attempt($id);

            return $fixed ? $attempts : throw new RetryableError("unavailable (attempt {$attempts})", code: 503);
        },
        RunRetryPolicy::fixedDelay(10, maxAttempts: 2, onMaxAttempts: OnMaxAttempts::Pause),
    ))
    // A domain exception the service's error mapper turns into a pause.
    ->handler('mapped', static function (Context $ctx, string $id) use ($attempt): int {
        [$fixed, $attempts] = $attempt($id);

        return $fixed ? $attempts : throw new \DomainException("ledger of {$id} is offline");
    })
    ->errorMapper(static fn (\Throwable $e): ?PauseError => $e instanceof \DomainException ? new PauseError("paused: {$e->getMessage()}", $e) : null)
    ->definition();
