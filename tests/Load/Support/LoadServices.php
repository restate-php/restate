<?php

declare(strict_types=1);

namespace Restate\Tests\Load\Support;

use Restate\Context;
use Restate\InvocationConfiguration;
use Restate\InvocationRetryPolicy;
use Restate\ObjectContext;
use Restate\Service;
use Restate\ServiceDefinition;
use Restate\VirtualObject;

/**
 * The handlers the load tests drive (tests/Load/LoadScenario.php), served by `endpoint.php` next to this file.
 *
 * - `LoadNoop/noop(string)`: returns its input; one attempt. The per-request floor (bootstrap, VM, HTTP hop).
 * - `LoadState/update({keys, bytes})` (virtual object): reads `keys` state keys (eager state) and writes each back
 *   with a value of `bytes` bytes; one attempt, a state-heavy start message once the object has state.
 * - `LoadRuns/runs({runs, bytes})`: `runs` sequential runs, each returning `bytes` bytes; runs + 1 attempts, the last
 *   one replaying the whole journal (O(n²) bytes over the invocation). Each run's execution is recorded in the effects
 *   log (LOAD_EFFECTS_LOG), so duplicated side effects can be counted.
 * - `LoadFanOut/fanOut(n)`: n calls to `LoadNoop/noop` awaited with `all()`.
 *
 * Completed invocations are kept for an hour (journal retention), so the load runner can read their timestamps and
 * retry counts from `sys_invocation` after the run. A failing attempt is retried every 100–1000 ms, at most 50 times.
 */
final class LoadServices
{
    private function __construct()
    {
    }

    /** @return list<ServiceDefinition> */
    public static function definitions(): array
    {
        $retries = new InvocationRetryPolicy(initialIntervalMillis: 100, exponentiationFactor: 2.0, maxIntervalMillis: 1000, maxAttempts: 50, onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_KILL);
        $retention = new InvocationConfiguration(journalRetentionMillis: 3_600_000);

        return [
            Service::new('LoadNoop', invocationRetryPolicy: $retries, invocationConfiguration: $retention)
                ->handler('noop', static fn (Context $ctx, string $input): string => $input)
                ->definition(),
            VirtualObject::new('LoadState', invocationRetryPolicy: $retries, invocationConfiguration: $retention)
                ->handler('update', static function (ObjectContext $ctx, array $request): int {
                    $keys = self::int($request, 'keys');
                    $value = str_repeat('v', self::int($request, 'bytes'));
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
                ->handler('runs', static function (Context $ctx, array $request): int {
                    $runs = self::int($request, 'runs');
                    $bytes = self::int($request, 'bytes');
                    $invocationId = $ctx->request()->id;
                    $total = 0;
                    for ($step = 1; $step <= $runs; $step++) {
                        $total += strlen($ctx->run("step-{$step}", static function () use ($invocationId, $step, $bytes): string {
                            EffectsLog::record($invocationId, $step);

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
    }

    /** @param array<array-key, mixed> $request */
    private static function int(array $request, string $field): int
    {
        $value = $request[$field] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException("The request needs a non-negative integer \"{$field}\".");
        }

        return $value;
    }
}
