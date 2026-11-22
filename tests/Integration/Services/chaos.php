<?php

declare(strict_types=1);

use Restate\Context;
use Restate\InvocationConfiguration;
use Restate\InvocationRetryPolicy;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Payload;
use Restate\RunRetryPolicy;
use Restate\Service;
use Restate\Target;
use Restate\Tests\Integration\Services\ChaosRecorder;
use Restate\Tests\Support\Faults\ProcessFaults;
use Restate\VirtualObject;

/*
 * Services that misbehave on purpose, for failure injection, serving and soak tests. Each handler records its attempts
 * and side effects with a ChaosRecorder (files under RESTATE_PHP_TEST_STATE_DIR, keyed by invocation id), and takes a
 * JSON object. A fault fires while it is armed (ChaosRecorder::armed()): `times` (default: no limit) is how many
 * executions it affects (attempts for a handler-level fault, run executions for a run-level one), and the test can
 * disarm the invocation at any time.
 *
 * Restate retries failed attempts after 100 ms, and pauses an invocation after 5 attempts (so a fault left armed does
 * not retry for ever on the shared server). Resume it with `PATCH /invocations/{id}/resume` once disarmed.
 *
 * Handlers of `Chaos`:
 * - steps {runs = 3, killInStep, times}: `runs` sequential runs `step-1`…, each recording an effect; the run named by
 *   `killInStep` SIGKILLs its worker (ProcessFaults) after recording, while armed. Returns the attempt and every step's
 *   execution number.
 * - slowRun {ms, times}: a run that sleeps `ms` (wall clock) while armed. Returns the attempt and the execution.
 * - busyRun {ms, timeLimit, inHandler, times}: `set_time_limit(timeLimit)` (if given), then a run (whose policy allows
 *   2 attempts) that spins the CPU for `ms` while armed, which is what `max_execution_time` counts (a sleep never trips
 *   it); with `inHandler` the handler spins before the run instead.
 * - noisy {kinds = [echo, deprecated, warning]}: stray output (`echo`), an `E_DEPRECATED` and an `E_USER_WARNING`,
 *   then returns the kinds. Under `display_errors=1` they land in front of the protocol frames.
 * - exitInHandler {times}, exitInRun {times}: `exit(0)` outside any run, or inside a run whose policy allows 2 attempts.
 * - exhaustMemoryInHandler {limit = 32M, times}, exhaustMemoryInRun {limit, times}: lower `memory_limit` and allocate
 *   until PHP's fatal error (with `limit` -1: until the kernel's OOM killer ends the process).
 * - deploy {}: a run `first`, then an awakeable (its id kept as the value `awakeable`), then returns the awakeable's
 *   value. While the test keeps the value `code` = `v2` for the invocation, the handler starts with a sleep instead of
 *   the run: new code whose replay of the old journal is a journal mismatch.
 *
 * Handlers of `ChaosObject` (virtual object; the exclusive handler holds the key's lock while it runs):
 * - add {ms, exitInRun, killInRun, times}: a run `add` that records an effect and, while armed, sleeps `ms`, exits or
 *   kills its worker; then `count` += 1. Returns the new count.
 * - batch {}: in one attempt `set(s, v1)`, two sends to `Counter/<key>/add` (1 and 10) and a run `r` that records an
 *   effect, then returns `get(s)`. Its first attempt answers SetState, two OneWayCalls, Run, ProposeRunCompletion and
 *   Suspension, for tests that cut that response between and inside the frames.
 * - abortable {ms, times}: a run that records `abortable-start`, sleeps `ms` while armed and records `abortable-end`,
 *   under an abort timeout of 1 s (inactivity timeouts do not apply in request/response mode). Returns the run's
 *   execution number.
 * - get: the count (shared).
 *
 * Both keep the journals of completed invocations for an hour, so tests can read them (sys_journal).
 */

$recorder = ChaosRecorder::fromEnvironment();
$retries = new InvocationRetryPolicy(initialIntervalMillis: 100, exponentiationFactor: 1.0, maxAttempts: 5, onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_PAUSE);
$runRetries = RunRetryPolicy::fixedDelay(10, maxAttempts: 2);
$retention = new InvocationConfiguration(journalRetentionMillis: 3_600_000);

/** @param array<array-key, mixed> $request */
$times = static fn (array $request): ?int => isset($request['times']) && is_int($request['times']) ? $request['times'] : null;

/** @param array<array-key, mixed> $request */
$int = static fn (array $request, string $field, int $default): int => isset($request[$field]) && is_int($request[$field]) ? $request[$field] : $default;

$spin = static function (int $milliseconds): int {
    $until = hrtime(true) + $milliseconds * 1_000_000;
    $work = 0;
    while (hrtime(true) < $until) {
        $work = ($work * 31 + 7) % 1_000_003;
    }

    return $work;
};

$exhaustMemory = static function (string $limit): never {
    ini_set('memory_limit', $limit);
    $chunks = [];
    while (true) {
        $chunks[] = str_repeat('x', 1 << 20);
    }
};

$chaos = Service::new('Chaos', invocationRetryPolicy: $retries, invocationConfiguration: $retention)
    ->handler('steps', static function (Context $ctx, array $request) use ($recorder, $times, $int): array {
        $id = ChaosRecorder::invocationId($ctx);
        $attempt = $recorder->attempt($id);
        $killInStep = $int($request, 'killInStep', 0);

        $steps = [];
        for ($step = 1; $step <= $int($request, 'runs', 3); $step++) {
            $steps[] = $ctx->run("step-{$step}", static function () use ($recorder, $id, $step, $attempt, $killInStep, $times, $request): int {
                $execution = $recorder->effect($id, "step-{$step}", $attempt);
                if ($step === $killInStep && $recorder->armed($id, $times($request), $execution)) {
                    ProcessFaults::killWorkerOrExit();
                }

                return $execution;
            });
        }

        return ['attempt' => $attempt, 'steps' => $steps];
    })
    ->handler('slowRun', static function (Context $ctx, array $request) use ($recorder, $times, $int): array {
        $id = ChaosRecorder::invocationId($ctx);
        $attempt = $recorder->attempt($id);
        $execution = $ctx->run('slow', static function () use ($recorder, $id, $attempt, $times, $int, $request): int {
            $execution = $recorder->effect($id, 'slow', $attempt);
            if ($recorder->armed($id, $times($request), $execution)) {
                usleep($int($request, 'ms', 1000) * 1000);
            }

            return $execution;
        });

        return ['attempt' => $attempt, 'execution' => $execution];
    })
    ->handler('busyRun', static function (Context $ctx, array $request) use ($recorder, $times, $int, $spin, $runRetries): array {
        $id = ChaosRecorder::invocationId($ctx);
        $attempt = $recorder->attempt($id);
        if (isset($request['timeLimit']) && is_int($request['timeLimit'])) {
            set_time_limit($request['timeLimit']);
        }
        $inHandler = ($request['inHandler'] ?? false) === true;
        if ($inHandler && $recorder->armed($id, $times($request), $attempt)) {
            $spin($int($request, 'ms', 1000));
        }
        $execution = $ctx->run('busy', static function () use ($recorder, $id, $attempt, $times, $int, $request, $spin, $inHandler): int {
            $execution = $recorder->effect($id, 'busy', $attempt);
            if (!$inHandler && $recorder->armed($id, $times($request), $execution)) {
                $spin($int($request, 'ms', 1000));
            }

            return $execution;
        }, $runRetries);

        return ['attempt' => $attempt, 'execution' => $execution];
    })
    ->handler('noisy', static function (Context $ctx, array $request) use ($recorder): array {
        $recorder->attempt(ChaosRecorder::invocationId($ctx));
        $kinds = isset($request['kinds']) && is_array($request['kinds']) ? array_values(array_filter($request['kinds'], is_string(...))) : ['echo', 'deprecated', 'warning'];
        foreach ($kinds as $kind) {
            match ($kind) {
                'echo' => print "stray output from Chaos/noisy\n",
                // A real E_DEPRECATED (trigger_error() can only raise E_USER_DEPRECATED).
                'deprecated' => current(new \ArrayObject([1])),
                'warning' => trigger_error('Chaos/noisy warns', E_USER_WARNING),
                default => throw new \InvalidArgumentException("Unknown kind {$kind}."),
            };
        }

        return $kinds;
    })
    ->handler('exitInHandler', static function (Context $ctx, array $request) use ($recorder, $times): int {
        $id = ChaosRecorder::invocationId($ctx);
        $attempt = $recorder->attempt($id);
        if ($recorder->armed($id, $times($request), $attempt)) {
            exit(0);
        }

        return $attempt;
    })
    ->handler('exitInRun', static function (Context $ctx, array $request) use ($recorder, $times, $runRetries): int {
        $id = ChaosRecorder::invocationId($ctx);
        $attempt = $recorder->attempt($id);

        return $ctx->run('exit', static function () use ($recorder, $id, $attempt, $times, $request): int {
            $execution = $recorder->effect($id, 'exit', $attempt);
            if ($recorder->armed($id, $times($request), $execution)) {
                exit(0);
            }

            return $execution;
        }, $runRetries);
    })
    ->handler('exhaustMemoryInHandler', static function (Context $ctx, array $request) use ($recorder, $times, $exhaustMemory): int {
        $id = ChaosRecorder::invocationId($ctx);
        $attempt = $recorder->attempt($id);
        if ($recorder->armed($id, $times($request), $attempt)) {
            $exhaustMemory(isset($request['limit']) && is_string($request['limit']) ? $request['limit'] : '32M');
        }

        return $attempt;
    })
    ->handler('exhaustMemoryInRun', static function (Context $ctx, array $request) use ($recorder, $times, $exhaustMemory, $runRetries): int {
        $id = ChaosRecorder::invocationId($ctx);
        $attempt = $recorder->attempt($id);

        return $ctx->run('memory', static function () use ($recorder, $id, $attempt, $times, $request, $exhaustMemory): int {
            $execution = $recorder->effect($id, 'memory', $attempt);
            if ($recorder->armed($id, $times($request), $execution)) {
                $exhaustMemory(isset($request['limit']) && is_string($request['limit']) ? $request['limit'] : '32M');
            }

            return $execution;
        }, $runRetries);
    })
    ->handler('deploy', static function (Context $ctx, mixed $_ = null) use ($recorder): string {
        $id = ChaosRecorder::invocationId($ctx);
        $attempt = $recorder->attempt($id);
        if ($recorder->value($id, 'code') === 'v2') {
            $ctx->sleep(1);
        } else {
            $ctx->run('first', static fn (): int => $recorder->effect($id, 'first', $attempt));
        }
        $awakeable = $ctx->awakeable();
        $recorder->put($id, 'awakeable', $awakeable->id());

        return (string) $awakeable->value();
    }, Payload::empty())
    ->definition();

$chaosObject = VirtualObject::new('ChaosObject', invocationRetryPolicy: $retries, invocationConfiguration: $retention)
    ->handler('add', static function (ObjectContext $ctx, array $request) use ($recorder, $times, $int): int {
        $id = ChaosRecorder::invocationId($ctx);
        $attempt = $recorder->attempt($id);
        $ctx->run('add', static function () use ($recorder, $id, $attempt, $times, $int, $request): int {
            $execution = $recorder->effect($id, 'add', $attempt);
            if ($recorder->armed($id, $times($request), $execution)) {
                usleep($int($request, 'ms', 0) * 1000);
                if (($request['exitInRun'] ?? false) === true) {
                    exit(0);
                }
                if (($request['killInRun'] ?? false) === true) {
                    ProcessFaults::killWorkerOrExit();
                }
            }

            return $execution;
        });

        $count = (int) ($ctx->get('count') ?? 0) + 1;
        $ctx->set('count', $count);

        return $count;
    })
    ->handler('batch', static function (ObjectContext $ctx, mixed $_ = null) use ($recorder): string {
        $id = ChaosRecorder::invocationId($ctx);
        $attempt = $recorder->attempt($id);
        $ctx->set('s', 'v1');
        $ctx->send(Target::object('Counter', $ctx->key(), 'add'), 1);
        $ctx->send(Target::object('Counter', $ctx->key(), 'add'), 10);
        $ctx->run('r', static fn (): int => $recorder->effect($id, 'r', $attempt));

        return (string) $ctx->get('s');
    }, Payload::empty())
    ->handler('abortable', static function (ObjectContext $ctx, array $request) use ($recorder, $times, $int): int {
        $id = ChaosRecorder::invocationId($ctx);
        $attempt = $recorder->attempt($id);

        return $ctx->run('abortable', static function () use ($recorder, $id, $attempt, $times, $int, $request): int {
            $execution = $recorder->effect($id, 'abortable-start', $attempt);
            if ($recorder->armed($id, $times($request), $execution)) {
                usleep($int($request, 'ms', 0) * 1000);
            }
            $recorder->effect($id, 'abortable-end', $attempt);

            return $execution;
        });
    }, invocationConfiguration: new InvocationConfiguration(abortTimeoutMillis: 1000, journalRetentionMillis: 3_600_000))
    ->sharedHandler('get', static fn (ObjectSharedContext $ctx, mixed $_ = null): int => (int) ($ctx->get('count') ?? 0), Payload::empty())
    ->definition();

return [$chaos, $chaosObject];
