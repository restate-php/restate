<?php

declare(strict_types=1);

use Restate\Context;
use Restate\Service;
use Restate\Target;
use Restate\TerminalError;

// Named signals (SignalTest): handlers that wait for signals, and handlers that resolve or reject them.

return Service::new('Signals')
    ->handler('await', static fn (Context $ctx, string $name): mixed => $ctx->signal($name)->awaitResult())
    // The same signal twice: each delivery is received once, in order.
    ->handler('awaitTwice', static fn (Context $ctx, string $name): array => [
        $ctx->signal($name)->awaitResult(),
        $ctx->signal($name)->awaitResult(),
    ])
    // Every named signal; a rejection fails the whole wait.
    ->handler('awaitAll', static fn (Context $ctx, array $names): array => $ctx->all(array_map($ctx->signal(...), $names)))
    // A signal or a timeout, whichever comes first.
    ->handler('awaitWithTimeout', static function (Context $ctx, array $request): mixed {
        $signal = $ctx->signal($request['name']);
        $winner = $ctx->select(['signal' => $signal, 'timeout' => $ctx->sleepFuture($request['timeoutMillis'])]);

        return $winner === 'signal' ? ['signal' => $signal->awaitResult()] : 'timeout';
    })
    ->handler('resolve', static function (Context $ctx, array $request): null {
        $ctx->invocation($request['invocationId'])->signal($request['name'])->resolve($request['value'] ?? null);

        return null;
    })
    ->handler('reject', static function (Context $ctx, array $request): null {
        $ctx->invocation($request['invocationId'])->signal($request['name'])->reject(new TerminalError($request['message'], $request['code'] ?? 500));

        return null;
    })
    // Signals this invocation, then waits for the signal: a delivery sent before the wait is not lost.
    ->handler('signalItself', static function (Context $ctx, mixed $value): mixed {
        $ctx->invocation($ctx->request()->id)->signal('self')->resolve($value);

        return $ctx->signal('self')->awaitResult();
    })
    // Sends Signals/await, signals the sent invocation through its handle (which waits for its id), and returns its
    // output.
    ->handler('sendAndSignal', static function (Context $ctx, mixed $value): mixed {
        $waiter = $ctx->send(Target::service('Signals', 'await'), 'go');
        $waiter->signal('go')->resolve($value);

        return $waiter->output();
    })
    ->definition();
