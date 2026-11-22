<?php

declare(strict_types=1);

use Restate\Context;
use Restate\InvocationRetryPolicy;
use Restate\Service;

/*
 * Output printed while the endpoint handles a request (StrayOutputTest, docs/decisions/0032-stray-output.md). `echoes`
 * prints from the handler and from a run, across the suspension the run causes; `warns` turns display_errors on for
 * itself, whatever the harness sets, and raises an E_USER_DEPRECATED and an E_USER_WARNING. Both must complete: the
 * endpoint discards the output and logs it. Three attempts at most, then KILL, so a regression fails instead of
 * retrying forever.
 */

$retryPolicy = new InvocationRetryPolicy(initialIntervalMillis: 100, exponentiationFactor: 1.0, maxAttempts: 3, onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_KILL);

return Service::new('StrayOutput')
    ->handler(
        'echoes',
        static function (Context $ctx, string $token): string {
            echo "handler output {$token}\n";
            $step = $ctx->run('step', static function () use ($token): string {
                echo "run output {$token}\n";

                return 'step done';
            });

            return "{$token}: {$step}";
        },
        invocationRetryPolicy: $retryPolicy,
    )
    ->handler(
        'warns',
        static function (Context $ctx, string $token): string {
            $displayErrors = ini_set('display_errors', '1');
            $errorReporting = error_reporting(E_ALL);
            try {
                trigger_error("deprecated call {$token}", E_USER_DEPRECATED);
                trigger_error("warning {$token}", E_USER_WARNING);
            } finally {
                error_reporting($errorReporting);
                if ($displayErrors !== false) {
                    ini_set('display_errors', $displayErrors);
                }
            }

            return "{$token}: warned";
        },
        invocationRetryPolicy: $retryPolicy,
    );
