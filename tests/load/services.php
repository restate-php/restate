<?php

declare(strict_types=1);

/*
 * The services the load tests drive (scenarios.json), served by endpoint.php next to this file:
 *
 * - `LoadNoop/noop(string)`: returns its input; one attempt. The per-request floor (bootstrap, VM, HTTP hop).
 * - `LoadState/update({keys, bytes})` (virtual object): reads `keys` state keys (eager state) and writes each back
 *   with a value of `bytes` bytes; one attempt, a state-heavy start message once the object has state.
 * - `LoadRuns/runs({runs, bytes})`: `runs` sequential runs, each returning `bytes` bytes; runs + 1 attempts, the last
 *   one replaying the whole journal (O(n²) bytes over the invocation). Each run's execution appends
 *   `<invocation id> <step>` to the effects ledger, load-effects.log in LOAD_STATE_DIR (default: the temporary
 *   directory, as endpoint.php's request log). A step that appears twice for one invocation ran twice: a duplicated
 *   side effect, which at-least-once execution allows only when an attempt failed after the run executed. The lines
 *   are short single `write()`s in append mode, so concurrent PHP threads never interleave.
 * - `LoadFanOut/fanOut(n)`: n calls to `LoadNoop/noop` awaited with `all()`.
 *
 * Completed invocations are kept for an hour (journal retention), so k6 can read their retry counts from
 * `sys_invocation` after the run. A failing attempt is retried every 100–1000 ms, at most 50 times.
 */

use Restate\Context;
use Restate\InvocationConfiguration;
use Restate\InvocationRetryPolicy;
use Restate\ObjectContext;
use Restate\Service;
use Restate\ServiceDefinition;
use Restate\VirtualObject;

/** @param array<array-key, mixed> $request */
$int = static function (array $request, string $field): int {
    $value = $request[$field] ?? null;
    if (!is_int($value) || $value < 0) {
        throw new InvalidArgumentException("The request needs a non-negative integer \"{$field}\".");
    }

    return $value;
};
$retries = new InvocationRetryPolicy(initialIntervalMillis: 100, exponentiationFactor: 2.0, maxIntervalMillis: 1000, maxAttempts: 50, onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_KILL);
$retention = new InvocationConfiguration(journalRetentionMillis: 3_600_000);

/** @var list<ServiceDefinition> */
return [
    Service::new('LoadNoop', invocationRetryPolicy: $retries, invocationConfiguration: $retention)
        ->handler('noop', static fn (Context $ctx, string $input): string => $input)
        ->definition(),
    VirtualObject::new('LoadState', invocationRetryPolicy: $retries, invocationConfiguration: $retention)
        ->handler('update', static function (ObjectContext $ctx, array $request) use ($int): int {
            $keys = $int($request, 'keys');
            $value = str_repeat('v', $int($request, 'bytes'));
            $present = 0;
            for ($key = 0; $key < $keys; $key++) {
                if ($ctx->get("k{$key}") !== null) {
                    $present++;
                }
                $ctx->set("k{$key}", $value);
            }

            return $present;
        })
        ->definition(),
    Service::new('LoadRuns', invocationRetryPolicy: $retries, invocationConfiguration: $retention)
        ->handler('runs', static function (Context $ctx, array $request) use ($int): int {
            $runs = $int($request, 'runs');
            $bytes = $int($request, 'bytes');
            $invocationId = $ctx->request()->id;
            $stateDir = getenv('LOAD_STATE_DIR');
            $ledger = (is_string($stateDir) && $stateDir !== '' ? $stateDir : sys_get_temp_dir()) . '/load-effects.log';
            $total = 0;
            for ($step = 1; $step <= $runs; $step++) {
                $total += strlen($ctx->run("step-{$step}", static function () use ($invocationId, $step, $bytes, $ledger): string {
                    file_put_contents($ledger, "{$invocationId} {$step}\n", FILE_APPEND);

                    return str_repeat('r', $bytes);
                }));
            }

            return $total;
        })
        ->definition(),
    Service::new('LoadFanOut', invocationRetryPolicy: $retries, invocationConfiguration: $retention)
        ->handler('fanOut', static function (Context $ctx, int $calls): int {
            $futures = [];
            for ($call = 0; $call < $calls; $call++) {
                $futures[] = $ctx->serviceClient('LoadNoop')->request('noop', "child-{$call}")->callFuture();
            }

            return count($ctx->all($futures));
        })
        ->definition(),
];
