# Durable Futures

A handler often waits for several things at once: two service calls, a call and a timeout, the first of three approvals. Durable futures let you start such operations without waiting, and then wait for them together. This page covers the operations that return futures, the combinators that wait on them, and the timeout and cancellation patterns built from them.

A durable future is a PHP handle for an operation that Restate tracks: a service call, a timer, an awakeable, a signal, invocation output, or a workflow promise. It is not a PHP promise, fiber or event-loop task. Waiting goes through Restate's journal, on one handle or on a combination of handles, so replay stays deterministic.

## Starting Futures

Use the future-returning methods when you want to start several durable operations before blocking for a result:

| Method | Returns |
| --- | --- |
| `$ctx->callFuture(Target $target, mixed $payload, ?string $idempotencyKey = null, $inputSerde = null, $outputSerde = null, array $headers = [], ?string $limitKey = null)` | A future of a request-response call's result. It takes the same arguments as `$ctx->call()`. A runtime client's request does the same with `->callFuture(?CallOptions $options = null)`. |
| `$ctx->sleepFuture(int $delayMillis)` | A durable timer future, which completes with `null` ([Timers and scheduling](timers.md)). |
| `$ctx->awakeableFuture($serde = null)` | `['id' => string, 'future' => DurableFuture]` ([Awakeables](external-events.md#awakeables)). |
| `$ctx->signal(string $name, $serde = null)` | A future of the next delivery of a named signal to this invocation ([Signals](external-events.md#signals)). |
| `$ctx->promiseFuture(string $name, $serde = null)` | A future of a workflow durable promise's value, in the `run` handler and in shared workflow handlers (`WorkflowSharedContext`). |
| `$ctx->promise($name)->future($serde = null)` | The same future, from a `DurablePromise` object ([Workflow durable promises](external-events.md#workflow-durable-promises)). |

The blocking methods, such as `$ctx->call()`, `$ctx->sleep()` and `DurablePromise::value()`, start the same durable operation and wait for its result. The `$serde` arguments take a `Serde` instance or class name; without one, the context's default serializer applies ([Custom serializers](serialization.md#custom-serializers)).

A `DurableFuture` has two methods. `awaitResult()` waits for the operation and returns its result, or throws its failure (a `TerminalError` for a failed operation); a second call returns or throws the same result without waiting again. `isCompleted()` is explained under [Replay Semantics And Scope](#replay-semantics-and-scope).

## Combinators

Every context has PHP equivalents of the concurrency patterns of the official SDKs. They take an array of futures created by that context, keyed as you like:

```php
use Restate\Target;

$futures = [
    'eu' => $ctx->callFuture(Target::service('PriceEu', 'quote'), $order),
    'us' => $ctx->callFuture(Target::service('PriceUs', 'quote'), $order),
];

// Either wait for both quotes ...
$quotes = $ctx->all($futures);   // ['eu' => …, 'us' => …], or throws the first failure

// ... or take whichever arrives first.
$fastest = $ctx->race($futures); // the first result, or its failure
```

| PHP API | Equivalent pattern | Result |
| --- | --- | --- |
| `$ctx->all($futures)` | TypeScript `RestatePromise.all`, Python `gather`, shared-core `AllSucceededOrFirstFailed` | Waits for every future to succeed and returns the values in the original PHP key order. The first failed future throws: the first to complete, or, among futures whose results were already in, the first by key. Empty input returns `[]`. |
| `$ctx->race($futures)` | TypeScript `RestatePromise.race`, Python `select`, shared-core `FirstCompleted` | Waits for the first completed future and returns or throws that future's result. Empty input throws `InvalidArgumentException`, because PHP has no useful pending return value. |
| `$ctx->any($futures)` | TypeScript `RestatePromise.any`, shared-core `FirstSucceededOrAllFailed` | Waits for the first successful future. Failed futures are skipped until one succeeds. If all fail, `DurableFutureAggregateException::failures()` lists the failures by original key, in the order the futures completed. Empty input throws that aggregate exception with no failures. |
| `$ctx->allSettled($futures)` | TypeScript `RestatePromise.allSettled`, shared-core `AllCompleted` | Waits for every future to settle and returns rows shaped like `['status' => 'fulfilled', 'value' => mixed]` or `['status' => 'rejected', 'reason' => Throwable]`. Empty input returns `[]`. |
| `$ctx->select($futures)` | Python `select`, Rust internal select-any | Waits for the first completed future and returns its original PHP array key, without taking its result. Use it when which future won matters more than a combined value. |

`all()`, `allSettled()`, `race()` and `select()` hand the whole set to Restate's shared core as one combinator tree (`AllSucceededOrFirstFailed`, `AllCompleted`, `FirstCompleted`), so the invocation suspends on exactly what it waits for. On Restate 1.8, Restate resumes it only once the combinator can resolve, instead of after every completion. `any()` waits for one completion at a time (a `FirstCompleted` tree per step), because its aggregate lists failures in completion order, and the core cannot report that order once a `FirstSucceededOrAllFailed` tree resolves. In every case PHP evaluates the results itself; the tree only decides when to wake up.

All combinators require futures created by the context they are called on (`DurableFuture` is an interface; the SDK implements it). Duplicate handles are rejected, because a result can be taken only once. `TerminalError` failures keep their status code and metadata, whether they are thrown directly, collected in `allSettled()`, or stored in `DurableFutureAggregateException`.

Combining many futures in one invocation costs more than linear time. Keep it to a few hundred, and fan out wider through child invocations ([Wide Fan-out Costs Quadratic Time per Attempt](../serving.md#wide-fan-out-costs-quadratic-time-per-attempt)).

## Incremental Completion

Use `$ctx->waitCompleted($futures)` when you need one durable wait step and want to keep ownership of the futures. It returns `['completed' => array, 'pending' => array]`, keyed like the input, without taking the completed results. Futures whose result was already taken are returned as completed immediately.

Use `DurableFuturesUnordered` to process results in the order the futures complete:

```php
use Restate\DurableFuturesUnordered;

$unordered = DurableFuturesUnordered::from($ctx, [
    $ctx->sleepFuture(300),
    $ctx->sleepFuture(100),
    $ctx->sleepFuture(200),
]);

while (($next = $unordered->next()) !== null) {
    // $next is ['index' => int, 'value' => mixed]
}
```

| Method | What it does |
| --- | --- |
| `DurableFuturesUnordered::from($ctx, $futures)` / `new DurableFuturesUnordered($ctx)` | Creates a collection over futures of `$ctx`. |
| `push(DurableFuture $future): int` | Adds one future and returns its stable integer index. |
| `pushAll(array $futures): array` | Adds several futures and returns the assigned indexes, keyed like the input array. |
| `next(): ?array` | Selects, awaits, removes and returns one completed result as `['index' => int, 'value' => mixed]`, or throws its failure. Returns `null` when the collection is empty. |
| `isEmpty()`, `count()` | How many futures are left. |

## Timeout And Cancellation Patterns

A timeout is a race between the operation and a timer, with `$ctx->select()` or `$ctx->race()`:

```php
use Restate\Target;
use Restate\TerminalError;

$call = $ctx->callFuture(Target::service('Greeter', 'greet'), 'Mary');
$timeout = $ctx->sleepFuture(5_000);

if ($ctx->select(['call' => $call, 'timeout' => $timeout]) === 'timeout') {
    throw new TerminalError('Timed out', 504);
}

$value = $call->awaitResult();
```

A timeout only stops the wait: the called handler keeps running. [Timeouts](timers.md#timeouts) has more examples.

Cancellation works by invocation id. `$ctx->send()` and `$ctx->sendAfter()` return an `InvocationHandle`, which you can cancel directly once its invocation id is available ([Invocation handles](communication.md#invocation-handles)). Its `output()` waits for the invocation to finish and `peekOutput()` returns `null` while it runs; neither is a `DurableFuture` you can combine. `$ctx->callFuture()` exposes only the result future. If a pattern needs to cancel a still-running invocation, start it or attach to it through a method that gives you an `InvocationHandle` or a known invocation id.

## Replay Semantics And Scope

The PHP SDK does not run durable operations on goroutines, fibers, native promises or a userland event loop. Combinators only coordinate existing durable handles, and wait through Restate's durable await, which suspends deterministically. PHP callbacks never run concurrently, and native async patterns such as `Promise::then()`, JavaScript microtasks, Python `async for` or Rust streams are not emulated.

`DurableFuture::isCompleted()` reports whether this PHP future object has already taken and cached its result in the current attempt. It is not a readiness probe: a future whose operation completed but which nothing awaited yet reports `false`.

## Design notes

- [Named signals](../decisions/0019-named-signals.md)
- [Tree awaits report their completed handles](../decisions/0036-tree-await-completed-handles.md)
- [Shared core](../decisions/0014-shared-core.md)
