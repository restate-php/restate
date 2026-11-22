<?php

declare(strict_types=1);

use Restate\Context;
use Restate\DurableFutureAggregateException;
use Restate\DurableFuturesUnordered;
use Restate\InvocationConfiguration;
use Restate\InvocationRetryPolicy;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Payload;
use Restate\Service;
use Restate\Target;
use Restate\Tests\Integration\Services\ChaosRecorder;
use Restate\Tests\Integration\Services\ScaleValues;
use Restate\VirtualObject;

/*
 * Services for the scale tests (tests/Integration/Scale): long journals, wide fan-out, wide state and contention. Every
 * handler records its attempts with a ChaosRecorder (keyed by the `x-restate-invocation-id` header), and the end of each
 * attempt, returned or suspended, as the effect `attempt-end`, so a test can tell how many attempts an invocation took
 * and how long each one ran inside the handler. They take a JSON object.
 *
 * Restate retries a failed attempt after 100 ms and pauses the invocation after 20 failed attempts in a row, so a broken
 * case ends instead of retrying for ever.
 *
 * - LongJournal/runs {n, valueBytes = 16}: n sequential runs `step-1`…, each recording an effect and returning
 *   ScaleValues::value(i, valueBytes). Returns the attempt, n, and the xxh128 hash of the values in order.
 * - LongJournal/slowReplay {n, spinMs}: the same with 16-byte values, after spinning the CPU for `spinMs` before every
 *   step, outside the runs, so every replayed step costs `spinMs`. Its abort timeout is 1 s, and it pauses after 3
 *   failed attempts in a row.
 * - FanOut/allCalls {n}, FanOut/selectCalls {n}: n calls to FanOutCallee/echo, combined with all(), or consumed one by
 *   one with select(). Return the values (all) or the keys in the order select() returned them.
 * - FanOut/unorderedSleeps {n}: n sleeps of 0-50 ms (from the context's random), drained from a DurableFuturesUnordered.
 * - FanOut/allAwakeables {n}, FanOut/selectAwakeables {n}, FanOut/anyAwakeables {n}: n awakeables whose ids each
 *   attempt writes to the state directory (ScaleValues::awakeablesFile()), combined with all(), consumed one by one
 *   with select() (returns the keys in the order select() returned them), or combined with any() (returns the
 *   aggregate's failure messages by key, in the order it lists them).
 * - FanOutCallee/echo {caller, i}: records the effect `callee` with `i` under the caller's invocation id, returns i.
 * - WideState (virtual object): fill {count, bytes} sets k0…k{count-1} to ScaleValues::value(i, bytes); readAll
 *   (shared) lists the keys with stateKeys() and reads each, returning the count and ScaleValues::stateHash(); readSome
 *   {keys} (shared) reads the keys given; clearAllThenRead {keys} clears every key, then reads the keys given and lists
 *   the keys left, in the same attempt. Each returns the attempt that completed it.
 * - Ledger (virtual object): add {amount}: get `v`, a run returning it (which ends the attempt), then set `v` to
 *   v + amount; returns the old value. peek (shared): `v`.
 */

$recorder = ChaosRecorder::fromEnvironment();
$retries = new InvocationRetryPolicy(initialIntervalMillis: 100, exponentiationFactor: 1.0, maxAttempts: 20, onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_PAUSE);

/** @param array<array-key, mixed> $request */
$int = static fn (array $request, string $field, int $default): int => isset($request[$field]) && is_int($request[$field]) ? $request[$field] : $default;

/**
 * Runs `$body` as one recorded attempt: records the attempt, and its end as the effect `attempt-end` whether the attempt
 * returns, throws or suspends.
 *
 * @template T
 *
 * @param \Closure(string, int): T $body gets the invocation id and the attempt number
 * @return T
 */
$attempt = static function (Context $ctx, \Closure $body) use ($recorder): mixed {
    $id = ChaosRecorder::invocationId($ctx);
    $attempt = $recorder->attempt($id);

    try {
        return $body($id, $attempt);
    } finally {
        $recorder->effect($id, 'attempt-end', $attempt);
    }
};

$spin = static function (int $milliseconds): int {
    $until = hrtime(true) + $milliseconds * 1_000_000;
    $work = 0;
    while (hrtime(true) < $until) {
        $work = ($work * 31 + 7) % 1_000_003;
    }

    return $work;
};

/** @return \Closure(Context, array<array-key, mixed>): array<string, mixed> */
$steps = static fn (bool $slow) => static function (Context $ctx, array $request) use ($attempt, $recorder, $int, $spin, $slow): array {
    return $attempt($ctx, static function (string $id, int $attempt) use ($ctx, $request, $recorder, $int, $spin, $slow): array {
        $n = $int($request, 'n', 10);
        $valueBytes = $slow ? 16 : $int($request, 'valueBytes', 16);
        $hash = hash_init('xxh128');
        for ($step = 1; $step <= $n; $step++) {
            if ($slow) {
                $spin($int($request, 'spinMs', 100));
            }
            $value = $ctx->run("step-{$step}", static function () use ($recorder, $id, $attempt, $step, $valueBytes): string {
                $recorder->effect($id, "step-{$step}", $attempt);

                return ScaleValues::value($step, $valueBytes);
            });
            if (!is_string($value) || $value !== ScaleValues::value($step, $valueBytes)) {
                throw new \UnexpectedValueException("step-{$step} replayed a different value.");
            }
            hash_update($hash, $value);
        }

        return ['attempt' => $attempt, 'n' => $n, 'hash' => hash_final($hash)];
    });
};

$longJournal = Service::new('LongJournal', invocationRetryPolicy: $retries)
    ->handler('runs', $steps(false))
    // Three failed attempts in a row pause it: once a replay outgrows the abort timeout, every attempt fails.
    ->handler('slowReplay', $steps(true), invocationRetryPolicy: new InvocationRetryPolicy(initialIntervalMillis: 100, exponentiationFactor: 1.0, maxAttempts: 3, onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_PAUSE), invocationConfiguration: new InvocationConfiguration(abortTimeoutMillis: 1000))
    ->definition();

/**
 * @param array<array-key, mixed> $request
 * @return array<int, \Restate\DurableFuture>
 */
$calls = static function (Context $ctx, string $id, array $request) use ($int): array {
    $futures = [];
    for ($i = 0; $i < $int($request, 'n', 10); $i++) {
        $futures[$i] = $ctx->callFuture(Target::service('FanOutCallee', 'echo'), ['caller' => $id, 'i' => $i]);
    }

    return $futures;
};

/**
 * @param array<array-key, mixed> $request
 * @return array<int, \Restate\DurableFuture>
 */
$awakeables = static function (Context $ctx, string $id, array $request) use ($int, $recorder): array {
    $futures = [];
    $ids = [];
    for ($i = 0; $i < $int($request, 'n', 10); $i++) {
        ['id' => $ids[$i], 'future' => $futures[$i]] = $ctx->awakeableFuture();
    }
    // Every attempt writes the same ids: they are derived from the invocation id and the journal.
    $file = ScaleValues::awakeablesFile($recorder->stateDir, $id);
    file_put_contents($file . '.tmp', json_encode($ids, JSON_THROW_ON_ERROR));
    rename($file . '.tmp', $file);

    return $futures;
};

$fanOut = Service::new('FanOut', invocationRetryPolicy: $retries)
    ->handler('allCalls', static fn (Context $ctx, array $request): array => $attempt($ctx, static fn (string $id, int $attempt): array => [
        'attempt' => $attempt,
        'values' => $ctx->all($calls($ctx, $id, $request)),
    ]))
    ->handler('selectCalls', static fn (Context $ctx, array $request): array => $attempt($ctx, static function (string $id, int $attempt) use ($ctx, $request, $calls): array {
        $pending = $calls($ctx, $id, $request);
        $order = [];
        while ($pending !== []) {
            $key = $ctx->select($pending);
            if ($pending[$key]->awaitResult() !== $key) {
                throw new \UnexpectedValueException("FanOutCallee/echo answered call {$key} with another value.");
            }
            $order[] = $key;
            unset($pending[$key]);
        }

        return ['attempt' => $attempt, 'order' => $order];
    }))
    ->handler('unorderedSleeps', static fn (Context $ctx, array $request): array => $attempt($ctx, static function (string $id, int $attempt) use ($ctx, $request, $int): array {
        $sleeps = [];
        for ($i = 0; $i < $int($request, 'n', 10); $i++) {
            $sleeps[] = $ctx->sleepFuture($ctx->randInt(0, 50));
        }
        $unordered = DurableFuturesUnordered::from($ctx, $sleeps);
        $order = [];
        while (($next = $unordered->next()) !== null) {
            $order[] = $next['index'];
        }

        return ['attempt' => $attempt, 'order' => $order];
    }))
    ->handler('allAwakeables', static fn (Context $ctx, array $request): array => $attempt($ctx, static fn (string $id, int $attempt): array => [
        'attempt' => $attempt,
        'values' => $ctx->all($awakeables($ctx, $id, $request)),
    ]))
    ->handler('selectAwakeables', static fn (Context $ctx, array $request): array => $attempt($ctx, static function (string $id, int $attempt) use ($ctx, $request, $awakeables): array {
        $pending = $awakeables($ctx, $id, $request);
        $order = [];
        while ($pending !== []) {
            $key = $ctx->select($pending);
            $pending[$key]->awaitResult();
            $order[] = $key;
            unset($pending[$key]);
        }

        return ['attempt' => $attempt, 'order' => $order];
    }))
    ->handler('anyAwakeables', static fn (Context $ctx, array $request): array => $attempt($ctx, static function (string $id, int $attempt) use ($ctx, $request, $awakeables): array {
        try {
            return ['attempt' => $attempt, 'value' => $ctx->any($awakeables($ctx, $id, $request))];
        } catch (DurableFutureAggregateException $exception) {
            $failures = [];
            foreach ($exception->failures() as $key => $failure) {
                $failures[] = ['key' => $key, 'message' => $failure->getMessage()];
            }

            return ['attempt' => $attempt, 'failures' => $failures];
        }
    }))
    ->definition();

$fanOutCallee = Service::new('FanOutCallee', invocationRetryPolicy: $retries)
    ->handler('echo', static function (Context $ctx, array $request) use ($recorder, $int): int {
        $i = $int($request, 'i', -1);
        $recorder->effect(is_string($request['caller'] ?? null) ? $request['caller'] : 'unknown', 'callee', $i);

        return $i;
    })
    ->definition();

$wideState = VirtualObject::new('WideState', invocationRetryPolicy: $retries)
    ->handler('fill', static fn (ObjectContext $ctx, array $request): array => $attempt($ctx, static function (string $id, int $attempt) use ($ctx, $request, $int): array {
        $count = $int($request, 'count', 10);
        $bytes = $int($request, 'bytes', 16);
        for ($i = 0; $i < $count; $i++) {
            $ctx->set(ScaleValues::stateKey($i), ScaleValues::value($i, $bytes));
        }

        return ['attempt' => $attempt, 'count' => $count];
    }))
    // Every key and its value, hashed in key order (ScaleValues::stateHash()).
    ->sharedHandler('readAll', static fn (ObjectSharedContext $ctx, mixed $_ = null): array => $attempt($ctx, static function (string $id, int $attempt) use ($ctx): array {
        $values = [];
        foreach ($ctx->stateKeys() as $key) {
            $values[$key] = $ctx->get($key);
        }

        return ['attempt' => $attempt, 'count' => count($values), 'hash' => ScaleValues::stateHash($values)];
    }), Payload::empty())
    ->sharedHandler('readSome', static fn (ObjectSharedContext $ctx, array $request): array => $attempt($ctx, static function (string $id, int $attempt) use ($ctx, $request): array {
        $values = [];
        foreach (is_array($request['keys'] ?? null) ? $request['keys'] : [] as $key) {
            $values[] = $ctx->get((string) $key);
        }

        return ['attempt' => $attempt, 'values' => $values];
    }))
    // Clears every key, then reads the keys given and lists the keys left, all in the same attempt.
    ->handler('clearAllThenRead', static fn (ObjectContext $ctx, array $request): array => $attempt($ctx, static function (string $id, int $attempt) use ($ctx, $request): array {
        $ctx->clearAll();
        $values = [];
        foreach (is_array($request['keys'] ?? null) ? $request['keys'] : [] as $key) {
            $values[] = $ctx->get((string) $key);
        }

        return ['attempt' => $attempt, 'values' => $values, 'keys' => $ctx->stateKeys()];
    }))
    ->definition();

$ledger = VirtualObject::new('Ledger', invocationRetryPolicy: $retries)
    ->handler('add', static fn (ObjectContext $ctx, array $request): int => $attempt($ctx, static function (string $id, int $attempt) use ($ctx, $request, $int): int {
        $old = (int) ($ctx->get('v') ?? 0);
        // Ends the attempt: the next one must take `v` from the journal, not from the state Restate sends then.
        $audited = $ctx->run('audit', static fn (): int => $old);
        $ctx->set('v', $audited + $int($request, 'amount', 1));

        return $old;
    }))
    ->sharedHandler('peek', static fn (ObjectSharedContext $ctx, mixed $_ = null): int => (int) ($ctx->get('v') ?? 0), Payload::empty())
    ->definition();

return [$longJournal, $fanOut, $fanOutCallee, $wideState, $ledger];
