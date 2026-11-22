# Durable Futures

Durable futures are PHP handles for Restate-backed operations such as service calls, timers, awakeables, signals, invocation output, and workflow promises. They are not PHP promises, fibers, or event-loop tasks. Waiting uses the backend durable await, on one handle or on a tree of combinators over handles, so replay stays deterministic.

## Starting Futures

Use the future-returning APIs when multiple durable operations should be coordinated before blocking for a result:

- `Context::callFuture(Target $target, mixed $payload, ?string $idempotencyKey = null, $inputSerde = null, $outputSerde = null, array $headers = [], ?string $limitKey = null)` starts a request/response call and returns its result future; it takes the same arguments as `Context::call()`. A runtime client's request does the same with `->callFuture(?CallOptions $options = null)`.
- `Context::sleepFuture(int $delayMillis)` starts a durable timer future.
- `Context::awakeableFuture($serde = null)` returns `['id' => string, 'future' => DurableFuture]`.
- `Context::signal(string $name, $serde = null)` returns a future of the next delivery of a named signal to this invocation ([Named Signals](decisions/0019-named-signals.md)).
- `WorkflowSharedContext::promiseFuture(string $name, $serde = null)` returns a workflow durable-promise value future, in the `run` handler and in shared workflow handlers.
- `DurablePromise::future($serde = null)` returns the same future from a promise object (`$ctx->promise($name)`).

The blocking methods, such as `Context::call()`, `Context::sleep()` and `DurablePromise::value()`, start the same durable operation and wait for its result. The `$serde` arguments take a `Serde` instance or class name; without one, the context's default serde applies.

## Combinators

Every context exposes PHP equivalents for the official SDK concurrency patterns, over futures it created:

| PHP API | Equivalent pattern | Result |
| --- | --- | --- |
| `$ctx->all($futures)` | TypeScript `RestatePromise.all`, Python `gather`, shared-core `AllSucceededOrFirstFailed` | Waits for every future to succeed and returns values in the original PHP key order. The first failed future throws: the first to complete, or, among futures whose results were already in, the first by key. Empty input returns `[]`. |
| `$ctx->race($futures)` | TypeScript `RestatePromise.race`, Python `select`, shared-core `FirstCompleted` | Waits for the first completed future and returns or throws that future's result. Empty input throws `InvalidArgumentException` because PHP has no useful pending return value. |
| `$ctx->any($futures)` | TypeScript `RestatePromise.any`, shared-core `FirstSucceededOrAllFailed` | Waits for the first successful future. Failed futures are skipped until one succeeds. If all fail, `DurableFutureAggregateException::failures()` exposes the failures by original key, in the order the futures completed. Empty input throws that aggregate exception with no failures. |
| `$ctx->allSettled($futures)` | TypeScript `RestatePromise.allSettled`, shared-core `AllCompleted` | Waits for every future to settle and returns rows shaped like `['status' => 'fulfilled', 'value' => mixed]` or `['status' => 'rejected', 'reason' => Throwable]`. Empty input returns `[]`. |
| `$ctx->select($futures)` | Python `select`, Rust internal select-any | Waits for the first completed future and returns the original PHP array key without consuming the result. Use this when the winner identity matters more than a combined value. |

`all()`, `allSettled()`, `race()` and `select()` hand the whole set to the core as one combinator tree (`AllSucceededOrFirstFailed`, `AllCompleted`, `FirstCompleted`), so the invocation suspends on exactly what it waits for: on protocol v7 (Restate 1.8) Restate resumes it only once the combinator can resolve, instead of after every completion. `any()` awaits one completion at a time (a `FirstCompleted` tree per step), because its aggregate lists failures in completion order and the VM cannot report that order after a `FirstSucceededOrAllFailed` tree resolves. PHP evaluates every result itself; the tree only decides when to wake up.

All combinators require futures created by the context they are called on (`DurableFuture` is an interface; the SDK implements it). Duplicate handles are rejected because notification results are one-shot. `TerminalError` failures keep their status code and metadata when propagated directly, collected in `allSettled()`, or stored in `DurableFutureAggregateException`.

## Incremental Completion

Use `$ctx->waitCompleted($futures)` when you need one durable wait step and want to keep ownership of the futures. It returns `['completed' => array, 'pending' => array]` without consuming completed results. Already consumed futures are returned as completed immediately.

Use `DurableFuturesUnordered` when you want to process results as futures complete:

```php
$unordered = DurableFuturesUnordered::from($ctx, [
    $ctx->sleepFuture(300),
    $ctx->sleepFuture(100),
    $ctx->sleepFuture(200),
]);

while (($next = $unordered->next()) !== null) {
    // $next is ['index' => int, 'value' => mixed]
}
```

`push()` assigns stable integer indexes. `pushAll()` adds several futures and returns the assigned indexes keyed like the input array. `next()` selects, awaits, removes, and returns one completed result. It returns `null` when the collection is empty.

## Timeout And Cancellation Patterns

Timeout races use `$ctx->select()` or `$ctx->race()` with a timer future:

```php
$call = $ctx->callFuture(Target::service('Greeter', 'greet'), 'Mary');
$timeout = $ctx->sleepFuture(5_000);

if ($ctx->select(['call' => $call, 'timeout' => $timeout]) === 'timeout') {
    throw new TerminalError('Timed out', 504);
}

$value = $call->awaitResult();
```

Cancellation is invocation-id based. `Context::send()` and `Context::sendAfter()` return `InvocationHandle`, which can be cancelled directly once its invocation ID is available. Its `output()` waits for the invocation to finish and `peekOutput()` returns null while it runs; neither is a `DurableFuture` to combine. `Context::callFuture()` exposes the request/response result future only; if a pattern needs cancellation of a still-running invocation, start or attach to an invocation through APIs that provide an `InvocationHandle` or a known invocation ID.

## Replay Semantics And Scope

The PHP SDK does not run durable operations on PHP goroutines, fibers, native promises, or a userland event loop. Combinators only coordinate existing durable handles and wait through the core's durable await, which suspends deterministically. PHP callbacks are not executed concurrently, and native async patterns such as `Promise::then()`, JavaScript microtasks, Python `async for`, or Rust streams are not emulated.

`DurableFuture::isCompleted()` reports whether this PHP future object has already consumed and cached its result in the current attempt. It is not a non-consuming backend readiness probe.
