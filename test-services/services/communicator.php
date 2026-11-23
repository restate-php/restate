<?php

declare(strict_types=1);

use Restate\AttachTarget;
use Restate\Context;
use Restate\DurableFutureAggregateException;
use Restate\DurableFuturesUnordered;
use Restate\Service;
use Restate\Target;
use Restate\TerminalError;

return Service::new('Communicator')
    ->handler('callGreeter', static fn (Context $ctx, string $name): mixed => $ctx->call(Target::service('Greeter', 'greet'), $name))
    ->handler('callCounter', static fn (Context $ctx, array $request): mixed => $ctx->call(Target::object('Counter', $request['key'], 'add'), $request['amount']))
    ->handler('callWorkflow', static fn (Context $ctx, string $key): mixed => $ctx->call(Target::workflow('EchoWorkflow', $key, 'run'), 'ping'))
    // A handler without output answers with no bytes: the call, and the output of a send's handle, are null.
    ->handler('callVoid', static function (Context $ctx): array {
        $greeter = $ctx->serviceClient('Greeter');

        return [
            'call' => $greeter->request('nothing')->call(),
            'output' => $greeter->request('nothing')->send()->output(),
        ];
    })
    ->handler('sleep', static function (Context $ctx, int $delayMillis): string {
        $ctx->sleep($delayMillis);
        return "slept {$delayMillis}ms";
    })
    // Catches \Exception around a sleep, as handler code often does. The suspension must not land in the catch block;
    // each time it does, the caught class is appended to a file under RESTATE_PHP_TEST_STATE_DIR.
    ->handler('sleepCatchingException', static function (Context $ctx, array $request): string {
        try {
            $ctx->sleep($request['delay_ms']);
        } catch (\Exception $e) {
            $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR') ?: dirname(__DIR__, 2) . '/var';
            if (!is_dir($stateDir)) {
                mkdir($stateDir, 0o777, true);
            }
            file_put_contents($stateDir . '/communicator-caught-' . hash('sha256', (string) $request['id']) . '.txt', $e::class . "\n", FILE_APPEND | LOCK_EX);

            return 'caught ' . $e::class;
        }

        return "slept {$request['delay_ms']}ms";
    })
    ->handler('awaitAwakeable', static function (Context $ctx, array $request): mixed {
        $awakeable = $ctx->awakeable();
        $ctx->send(Target::service('Communicator', 'resolveAwakeable'), [
            'id' => $awakeable->id(),
            'value' => $request['value'],
        ]);
        return $awakeable->value();
    })
    ->handler('resolveAwakeable', static function (Context $ctx, array $request): string {
        $ctx->resolveAwakeable($request['id'], $request['value'] ?? null);
        return 'resolved';
    })
    ->handler('awaitRejectedAwakeable', static function (Context $ctx, array $request): array {
        $awakeable = $ctx->awakeable();
        $ctx->send(Target::service('Communicator', 'rejectAwakeable'), [
            'id' => $awakeable->id(),
            'code' => $request['code'],
            'message' => $request['message'],
        ]);

        try {
            $awakeable->value();
        } catch (TerminalError $exception) {
            return ['code' => $exception->statusCode(), 'message' => $exception->getMessage()];
        }

        throw new \RuntimeException('Expected awakeable rejection.');
    })
    ->handler('rejectAwakeable', static function (Context $ctx, array $request): string {
        $ctx->rejectAwakeable($request['id'], new TerminalError($request['message'], $request['code']));
        return 'rejected';
    })
    ->handler('attachWorkflowOutput', static fn (Context $ctx, string $key): mixed => $ctx->attach(AttachTarget::workflow('EchoWorkflow', $key))->output())
    ->handler('attachCheckoutOutput', static fn (Context $ctx, string $key): mixed => $ctx->attach(AttachTarget::workflow('Checkout', $key))->output())
    ->handler('knownInvocationOutput', static function (Context $ctx, string $key): mixed {
        $handle = $ctx->send(Target::workflow('EchoWorkflow', $key, 'run'), 'ping');

        return $ctx->invocation($handle->id())->output();
    })
    ->handler('selectCallWins', static function (Context $ctx, string $name): array {
        $call = $ctx->callFuture(Target::service('Greeter', 'greet'), $name);
        $sleep = $ctx->sleepFuture(1000);
        $winner = $ctx->select(['call' => $call, 'sleep' => $sleep]);
        return ['winner' => $winner, 'value' => $call->awaitResult()];
    })
    ->handler('futureRaceCallWins', static function (Context $ctx, string $name): array {
        return [
            'value' => $ctx->race([
                'call' => $ctx->callFuture(Target::service('Greeter', 'greet'), $name),
                'sleep' => $ctx->sleepFuture(1000),
            ]),
        ];
    })
    ->handler('selectSleepWins', static function (Context $ctx, mixed $_): array {
        $fast = $ctx->sleepFuture(100);
        $slow = $ctx->sleepFuture(1000);
        $winner = $ctx->select(['fast' => $fast, 'slow' => $slow]);
        $fast->awaitResult();
        return ['winner' => $winner];
    })
    // Combines awakeables with Context::{all,race,any,allSettled}. Each outcome completes its awakeable after
    // `delay_ms` with `ok` (a value) or `fail` (a terminal failure message); an outcome with neither never completes.
    ->handler('combineAwakeables', static function (Context $ctx, array $request): array {
        $futures = [];
        foreach ($request['outcomes'] as $key => $outcome) {
            ['id' => $id, 'future' => $futures[$key]] = $ctx->awakeableFuture();
            if (array_key_exists('ok', $outcome)) {
                $ctx->sendAfter(Target::service('Communicator', 'resolveAwakeable'), ['id' => $id, 'value' => $outcome['ok']], $outcome['delay_ms']);
            } elseif (array_key_exists('fail', $outcome)) {
                $ctx->sendAfter(Target::service('Communicator', 'rejectAwakeable'), ['id' => $id, 'code' => 409, 'message' => $outcome['fail']], $outcome['delay_ms']);
            }
        }

        try {
            return match ($request['combinator']) {
                'all' => ['value' => $ctx->all($futures)],
                'race' => ['value' => $ctx->race($futures)],
                'any' => ['value' => $ctx->any($futures)],
                'allSettled' => ['value' => array_map(
                    static fn (array $row): array => $row['status'] === 'fulfilled' ? ['fulfilled' => $row['value']] : ['rejected' => $row['reason']->getMessage()],
                    $ctx->allSettled($futures),
                )],
            };
        } catch (DurableFutureAggregateException $exception) {
            return ['failures' => array_map(static fn (\Throwable $failure): string => $failure->getMessage(), $exception->failures())];
        } catch (TerminalError $exception) {
            return ['failure' => $exception->getMessage()];
        }
    })
    ->handler('unorderedSleeps', static function (Context $ctx, mixed $_): array {
        $unordered = new DurableFuturesUnordered($ctx);
        $unordered->push($ctx->sleepFuture(300));
        $unordered->push($ctx->sleepFuture(100));
        $unordered->push($ctx->sleepFuture(200));
        return [$unordered->next(), $unordered->next(), $unordered->next(), $unordered->next()];
    })
    ->handler('unorderedSleepsFromCollection', static function (Context $ctx, mixed $_): array {
        $unordered = DurableFuturesUnordered::from($ctx, [
            $ctx->sleepFuture(300),
            $ctx->sleepFuture(100),
            $ctx->sleepFuture(200),
        ]);
        return [$unordered->next(), $unordered->next(), $unordered->next(), $unordered->next()];
    })
    ->handler('randomValues', static function (Context $ctx, mixed $_): array {
        return [
            'seed' => $ctx->randomSeed(),
            'ints' => [$ctx->randInt(1, 100), $ctx->randInt(1, 100)],
            'bytes_hex' => bin2hex($ctx->randBytes(8)),
            'uuids' => [$ctx->uuid(), $ctx->uuid()],
        ];
    })
    ->handler('cancelDelayedCounter', static function (Context $ctx, array $request): array {
        $handle = $ctx->sendAfter(Target::object('Counter', $request['key'], 'add'), $request['amount'], $request['delay_ms']);
        $invocationId = $handle->id();
        $handle->cancel();

        return ['cancelled' => true, 'invocation_id' => $invocationId];
    })
    ->handler('cancelInvocation', static function (Context $ctx, string $invocationId): string {
        $ctx->invocation($invocationId)->cancel();
        return 'cancelled';
    })
    ->handler('sendCounter', static function (Context $ctx, array $request): string {
        $ctx->send(Target::object('Counter', $request['key'], 'add'), $request['amount']);
        return 'sent';
    })
    ->handler('sendCounterDelayed', static function (Context $ctx, array $request): string {
        $ctx->sendAfter(Target::object('Counter', $request['key'], 'add'), $request['amount'], $request['delay_ms']);
        return 'scheduled';
    })
    ->definition();
