<?php

declare(strict_types=1);

use Restate\Context;
use Restate\DurableFuture;
use Restate\DurableFutureAggregateException;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Payload;
use Restate\TerminalError;
use Restate\VirtualObject;

// VirtualObjectCommandInterpreter from the upstream SDK test suite (restatedev/e2e, sdk-tests v2.3,
// contracts/VirtualObjectCommandInterpreter.kt).
//
// An awaitable command becomes one of:
// - ['future' => DurableFuture, 'map' => callable(mixed): string] for awakeables, signals and sleeps;
// - ['value' => string] or ['failure' => TerminalError] for runs. The PHP SDK has no run future: ctx.run executes
//   the closure when it is called, so a run's outcome is known when the command is created (in command order, as
//   the journal records it). Combinators treat it as a future that completed before any of the others.
//
// The await commands map onto the SDK's combinators: awaitAny / awaitFirstCompleted onto Context::select (the
// first-completed tree), awaitAnySuccessful / awaitFirstSucceededOrAllFailed onto Context::any,
// awaitAllSucceededOrFirstFailed onto Context::all, awaitAllCompleted onto Context::allSettled.

$awakeableStateKey = static fn (string $key): string => 'awakeable:' . $key;

$createAwakeable = static function (ObjectContext $ctx, string $awakeableKey) use ($awakeableStateKey): DurableFuture {
    $awakeable = $ctx->awakeableFuture();
    $ctx->set($awakeableStateKey($awakeableKey), $awakeable['id']);

    return $awakeable['future'];
};

$asString = static fn (mixed $value): string => is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);

$runOutcome = static function (Context $ctx, string $name, callable $callback): array {
    try {
        return ['value' => (string) $ctx->run($name, $callback)];
    } catch (TerminalError $e) {
        return ['failure' => $e];
    }
};

$awaitable = static function (ObjectContext $ctx, array $command) use ($createAwakeable, $asString, $runOutcome): array {
    $type = (string) ($command['type'] ?? '');

    return match ($type) {
        'createAwakeable' => ['future' => $createAwakeable($ctx, (string) $command['awakeableKey']), 'map' => $asString],
        'sleep' => ['future' => $ctx->sleepFuture((int) $command['timeoutMillis']), 'map' => static fn (): string => 'sleep'],
        'createSignal' => ['future' => $ctx->signal((string) $command['signalName']), 'map' => $asString],
        'runReturns' => $runOutcome($ctx, 'runReturns', static fn (): string => (string) $command['value']),
        'runThrowTerminalException' => $runOutcome($ctx, 'runThrowTerminalException', static function () use ($command): never {
            throw new TerminalError((string) $command['reason']);
        }),
        default => throw new TerminalError("Unsupported awaitable command {$type}.", 501),
    };
};

/** @return array<int, array> */
$awaitables = static fn (ObjectContext $ctx, mixed $commands): array => array_map(
    static fn (mixed $command): array => $awaitable($ctx, is_array($command) ? $command : []),
    is_array($commands) ? array_values($commands) : [],
);

$settled = static function (array $awaitable): string {
    if (array_key_exists('failure', $awaitable)) {
        throw $awaitable['failure'];
    }

    return $awaitable['value'];
};

/** @return array{0: array<int, array>, 1: array<int, DurableFuture>} the known outcomes and the futures, by index */
$partition = static function (array $awaitables): array {
    $outcomes = [];
    $futures = [];
    foreach ($awaitables as $index => $awaitable) {
        if (isset($awaitable['future'])) {
            $futures[$index] = $awaitable['future'];
        } else {
            $outcomes[$index] = $awaitable;
        }
    }

    return [$outcomes, $futures];
};

$awaitOne = static fn (array $awaitable): string => isset($awaitable['future'])
    ? $awaitable['map']($awaitable['future']->awaitResult())
    : $settled($awaitable);

$firstCompleted = static function (ObjectContext $ctx, array $awaitables) use ($partition, $settled, $awaitOne): string {
    [$outcomes, $futures] = $partition($awaitables);
    if ($outcomes !== []) {
        return $settled(reset($outcomes));
    }
    if ($futures === []) {
        throw new TerminalError('No awaitables.', 400);
    }

    return $awaitOne($awaitables[$ctx->select($futures)]);
};

$firstSucceeded = static function (ObjectContext $ctx, array $awaitables) use ($partition): string {
    [$outcomes, $futures] = $partition($awaitables);
    $lastFailure = null;
    foreach ($outcomes as $outcome) {
        if (!array_key_exists('failure', $outcome)) {
            return $outcome['value'];
        }
        $lastFailure = $outcome['failure'];
    }
    if ($futures === []) {
        throw $lastFailure ?? new TerminalError('No awaitables.', 400);
    }

    try {
        $ctx->any($futures);
    } catch (DurableFutureAggregateException $e) {
        // The aggregate itself is not terminal (it would be retried); fail with the last failure to complete.
        $failures = $e->failures();
        $last = end($failures);
        throw $last instanceof TerminalError ? $last : new TerminalError($e->getMessage());
    }

    // Context::any returned the success with the lowest key among the futures completed so far, and nothing
    // was awaited since. Find that key to map the value.
    foreach ($futures as $index => $future) {
        if (!$future->isCompleted()) {
            continue;
        }
        try {
            $value = $future->awaitResult();
        } catch (TerminalError) {
            continue;
        }

        return $awaitables[$index]['map']($value);
    }

    throw new \LogicException('Context::any() returned, but no future succeeded.');
};

$allSucceeded = static function (ObjectContext $ctx, array $awaitables) use ($partition, $settled): string {
    [$outcomes, $futures] = $partition($awaitables);
    $results = [];
    foreach ($outcomes as $index => $outcome) {
        $results[$index] = $settled($outcome);
    }
    foreach ($ctx->all($futures) as $index => $value) {
        $results[$index] = $awaitables[$index]['map']($value);
    }
    ksort($results);

    return implode('|', $results);
};

$allCompleted = static function (ObjectContext $ctx, array $awaitables) use ($partition): string {
    [$outcomes, $futures] = $partition($awaitables);
    $results = [];
    foreach ($outcomes as $index => $outcome) {
        $results[$index] = array_key_exists('failure', $outcome) ? 'err:' . $outcome['failure']->getMessage() : 'ok:' . $outcome['value'];
    }
    foreach ($ctx->allSettled($futures) as $index => $row) {
        $results[$index] = $row['status'] === 'fulfilled' ? 'ok:' . $awaitables[$index]['map']($row['value']) : 'err:' . $row['reason']->getMessage();
    }
    ksort($results);

    return implode('|', $results);
};

$storedAwakeableId = static function (ObjectSharedContext $ctx, string $awakeableKey) use ($awakeableStateKey): string {
    $id = $ctx->get($awakeableStateKey($awakeableKey));
    if (!is_string($id)) {
        throw new TerminalError('awakeable is not registered yet');
    }

    return $id;
};

$interpretCommand = static function (ObjectContext $ctx, array $command) use ($awaitable, $awaitables, $awaitOne, $firstCompleted, $firstSucceeded, $allSucceeded, $allCompleted, $createAwakeable, $storedAwakeableId, $asString): string {
    $type = (string) ($command['type'] ?? '');

    switch ($type) {
        case 'awaitOne':
            return $awaitOne($awaitable($ctx, is_array($command['command'] ?? null) ? $command['command'] : []));
        case 'awaitAny':
        case 'awaitFirstCompleted':
            return $firstCompleted($ctx, $awaitables($ctx, $command['commands'] ?? []));
        case 'awaitAnySuccessful':
        case 'awaitFirstSucceededOrAllFailed':
            return $firstSucceeded($ctx, $awaitables($ctx, $command['commands'] ?? []));
        case 'awaitAllSucceededOrFirstFailed':
            return $allSucceeded($ctx, $awaitables($ctx, $command['commands'] ?? []));
        case 'awaitAllCompleted':
            return $allCompleted($ctx, $awaitables($ctx, $command['commands'] ?? []));
        case 'awaitAwakeableOrTimeout':
            $awakeable = $createAwakeable($ctx, (string) $command['awakeableKey']);
            $sleep = $ctx->sleepFuture((int) $command['timeoutMillis']);
            if ($ctx->select(['awakeable' => $awakeable, 'sleep' => $sleep]) === 'sleep') {
                throw new TerminalError('await-timeout');
            }

            return $asString($awakeable->awaitResult());
        case 'resolveAwakeable':
            $ctx->resolveAwakeable($storedAwakeableId($ctx, (string) $command['awakeableKey']), $command['value'] ?? null);

            return '';
        case 'rejectAwakeable':
            $ctx->rejectAwakeable($storedAwakeableId($ctx, (string) $command['awakeableKey']), new TerminalError((string) $command['reason']));

            return '';
        case 'getEnvVariable':
            $name = (string) $command['envName'];

            return (string) $ctx->run('getEnvVariable', static function () use ($name): string {
                $value = getenv($name);

                return $value === false ? '' : $value;
            });
        default:
            throw new TerminalError("Unsupported command {$type}.", 501);
    }
};

$results = static function (ObjectSharedContext $ctx): array {
    $results = $ctx->get('results');

    return is_array($results) ? $results : [];
};

return VirtualObject::new('VirtualObjectCommandInterpreter')
    ->handler('interpretCommands', static function (ObjectContext $ctx, array $request) use ($interpretCommand, $results): string {
        $result = '';
        foreach ($request['commands'] ?? [] as $command) {
            $result = $interpretCommand($ctx, is_array($command) ? $command : []);
            $ctx->set('results', [...$results($ctx), $result]);
        }

        return $result;
    })
    ->sharedHandler('resolveAwakeable', static function (ObjectSharedContext $ctx, array $request) use ($storedAwakeableId): null {
        $ctx->resolveAwakeable($storedAwakeableId($ctx, (string) $request['awakeableKey']), $request['value'] ?? null);

        return null;
    })
    ->sharedHandler('rejectAwakeable', static function (ObjectSharedContext $ctx, array $request) use ($storedAwakeableId): null {
        $ctx->rejectAwakeable($storedAwakeableId($ctx, (string) $request['awakeableKey']), new TerminalError((string) $request['reason']));

        return null;
    })
    ->sharedHandler('hasAwakeable', static fn (ObjectSharedContext $ctx, string $awakeableKey): bool => is_string($ctx->get($awakeableStateKey($awakeableKey))))
    // The suite calls getResults without a body.
    ->sharedHandler('getResults', static fn (ObjectSharedContext $ctx, mixed $_ = null): array => $results($ctx), Payload::empty())
    ->definition();
