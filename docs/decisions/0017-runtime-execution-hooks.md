# Runtime Execution Hooks

## Status

Accepted. Builds on [Suspension Is Not an Exception](0013-suspension-is-not-an-exception.md) and [Exception Hierarchy](0015-exception-hierarchy.md); respects the [Public API Boundary](0016-public-api-boundary.md).

## Context

Users need to run code around every handler attempt and every `ctx.run` side effect (tracing, metrics, logging, per-invocation scoping) and to classify their own exceptions as terminal or retryable without wrapping every handler in `try`/`catch`. Tracing (OpenTelemetry, for example) is to be built on these hooks, so they must give a tracer what it needs: the W3C trace context Restate sends with each attempt, the invocation id and target, the replay state, and a way to tell a suspension from a failure.

What the official SDKs do (read in `restatedev/sdk-typescript` `492920d`, `sdk-java` and `sdk-python`):

- **TypeScript** (`hooks.ts`): `HooksProvider = (ctx: {request}) => {interceptor?: {handler?(next), run?(name, next)}}`, configured per service and per handler (`[...service.hooks, ...handler.hooks]`; endpoint `defaultServiceOptions` are replaced, not merged, when a service sets its own). The first provider is the outermost. The handler interceptor runs on every attempt, including replays; the run interceptor only when the run is not replayed. `next` returns `void`: interceptors cannot change results, but can throw another error. Swallowing a handler error is not guarded (the invocation then succeeds with no output). Suspension rejects `next` with a 599 error (`isSuspendedError()`), and the attempt ends as a suspension whatever the interceptor does. `asTerminalError?: (error) => TerminalError | undefined` (handler or service option) classifies the error after the interceptors, and errors thrown by `ctx.run` closures.
- **Java**: `HandlerInterceptor.aroundHandler(Context(request, attemptHeaders), Next)` and `RunInterceptor.aroundRun(Context(request, runName), Next)`, factories per service (`HandlerRunner.Options`), first is outermost, the run interceptor is never called on replay. Swallowing yields a null result. No `asTerminalError`; interceptors rethrow as `TerminalException`. The OpenTelemetry module is an interceptor pair: extracts the parent from the attempt headers, span `attempt <target>` around `next`, span `run (<name>)` around each executed run.
- **Python**: `invocation_context_managers` per service and handler (no `ctx.run` hook, no error mapper).

## Decision

### Interceptors

Two public interfaces, Java's shape with TypeScript's semantics:

```php
interface HandlerInterceptor
{
    /** @param \Closure(): void $next */
    public function interceptHandler(HandlerExecution $execution, \Closure $next): void;
}

interface RunInterceptor
{
    /** @param \Closure(): void $next */
    public function interceptRun(RunExecution $execution, \Closure $next): void;
}
```

- **What a hook sees.** `HandlerExecution` has the invocation's `Restate\Request` (id, target, invocation headers, **attempt headers** — where Restate puts `traceparent`/`tracestate` —, raw body, idempotency key, scope, limit key) and `isReplaying()`, a live view of the handler's replay state (TypeScript's hidden `isProcessing()`, public here). `RunExecution` has the `Request` and the run's name. Interceptors do not get the context: journal operations outside the handler would make the journal depend on the interceptor configuration. The attempt number is not exposed: shared-core does not surface the start message's retry count, and nothing in the official hooks offers it.
- **Configuration.** On the endpoint (`EndpointBuilder::interceptor(...$interceptors)`) and per service (`Service::interceptor()`, `VirtualObject::interceptor()`, `Workflow::interceptor()`, and `ServiceDefinition::withInterceptors()` for services reflected from attributes). One object may implement both interfaces and joins both chains. No per-handler hooks: an interceptor can look at `$execution->request->target->handlerName()`.
- **Order.** Endpoint interceptors wrap service interceptors; within a level the first registered is the outermost (`A before → B before → handler → B after → A after`), as in all three SDKs. Unlike TypeScript, a service's hooks do not replace the endpoint's: endpoint-wide tracing must keep working when a service adds its own interceptor.
- **What `$next` runs.** For a handler: creating the service instance when the class is bound with a factory or container ([Handler Instantiation](0018-handler-instantiation.md)), decoding the input, the handler, encoding the output — so a span covers instantiation and payload mapping failures. For a run: the callback. `$next` returns nothing, so interceptors cannot change a result (as in TypeScript and Java); the value of a run is what its callback returned.
- **Replay.** The handler interceptor runs on every attempt, also on attempts that replay the journal; `isReplaying()` tells them apart (true at the start of a resumed attempt, false once the handler passes the journal's end). The run interceptor runs only when the callback executes, never when the run's result is replayed, so it sees each side effect exactly as often as it happens. `Context::now()` is the run `date.now` and goes through the run interceptors.

### Rules the SDK enforces

Where TypeScript and Java document rules and leave the consequences of breaking them undefined, the chain enforces them (`Restate\Internal\ExecutionHooks`):

1. **`$next` exactly once.** An interceptor that returns without calling `$next` (the handler never ran) or calls it twice fails the attempt with a `\LogicException` naming the interceptor class; the invocation is retried.
2. **Failures cannot be swallowed.** If `$next` threw and the interceptor returns normally, the SDK rethrows what `$next` threw. An interceptor may *replace* a failure by throwing another exception (for example a `TerminalError`), which then is the outcome.
3. **A suspension always wins.** When the invocation suspends, `$next` throws the internal `SuspensionException` (an `\Error`, so `catch (\Exception)` does not see it). Whatever the interceptor then does — return, swallow, rethrow something else — the suspension leaves the chain unchanged, and if the handler itself swallowed the suspension and returned, `$next` still throws one. The context's `SuspensionGuard` already makes anything after a suspension inert and `Endpoint` ignores the outcome of a suspended attempt; the chain adds that no interceptor (or error mapper) sees anything else. `Restate\Suspension::is($e)` (also true for an exception that wraps one as `previous`) lets an interceptor that catches `\Throwable` record a suspension as what it is — the equivalent of TypeScript's `isSuspendedError()` and Python's `is_internal_exception()`.

Rule 2 differs from TypeScript and Java, where swallowing turns a failure into an empty success. That outcome is never what an observer wants, and PHP has no `void` result to write.

### Error mapper

```php
interface ErrorMapper
{
    public function map(\Throwable $error): TerminalError|RetryableError|null;
}
```

Also accepted as a closure. It is asked about every exception that is not already classified — not a `TerminalError`, a `RetryableError`, or a suspension:

- from a handler, **after** the handler interceptors (as TypeScript's `asTerminalError`): it sees what the outermost interceptor threw, and interceptors see the raw exception. A tracer records the domain exception; the mapper decides what Restate does with it.
- from a `ctx.run` callback, after the run interceptors: a `TerminalError` fails the run without retries (its code and metadata are journaled), a `RetryableError` is proposed with its retry-after delay, code and metadata ([Retry-after on `ctx.run`](0014-shared-core.md#retry-after-on-ctxrun)).

Returning `null` keeps the default: the exception is retried (handler: Restate's invocation retry policy; run: the run's retry policy). A mapper may also return a `PauseError`, which pauses the invocation ([Pause](0021-pause.md)); the mapper never sees one. `TerminalError` takes an optional `previous`, so a mapper keeps the original exception. Configuration: `EndpointBuilder::errorMapper()`, `Service::errorMapper()` / `VirtualObject::` / `Workflow::` / `ServiceDefinition::withErrorMapper()`. **A service's mapper is asked first; the endpoint's when the service's returns `null`** (TypeScript resolves handler, then service; it has no endpoint-level mapper beyond defaults that a service option replaces). A mapper that throws fails the attempt with that exception. Without a mapper nothing changes: only `TerminalError` is terminal.

### Tracing

A tracer needs nothing but these hooks, as a `HandlerInterceptor` and `RunInterceptor` (Java's OpenTelemetry module is such a pair): it extracts the parent context from `$execution->request->attemptHeaders` (W3C `traceparent`/`tracestate`), opens an attempt span with the invocation id and target around `$next()`, opens a span around each executed run in `interceptRun`, uses `isReplaying()` to suppress span events during replay, and `Suspension::is()` to end the span without an error status on suspension. This repository ships no tracer.

## Consequences

- New public API: `HandlerInterceptor`, `RunInterceptor`, `HandlerExecution`, `RunExecution`, `ErrorMapper`, `Suspension`; `interceptor()` / `errorMapper()` on `EndpointBuilder`, `Service`, `VirtualObject`, `Workflow`; `ServiceDefinition::$interceptors`, `$errorMapper`, `withInterceptors()`, `withErrorMapper()` (and constructor parameters); `TerminalError`'s `previous`. No internal type appears in a public signature (`ExecutionHooks` and `ClosureErrorMapper` are internal).
- `HandlerInterceptor`, `RunInterceptor` and `ErrorMapper` are interfaces users implement: under the compatibility promise they only change in a release that may break the public API. `HandlerExecution` and `RunExecution` may gain properties or methods.
- With no hooks configured, the chains call the handler and the run callback directly.
- Evidence: `tests/Unit/Sdk/RuntimeExecutionHooksTest.php` (order, request and replay state, swallowing, replacing, `$next` misuse, suspension pass-through, mapping and its precedence), `tests/Unit/Sdk/RunInterceptorTest.php` (executed vs replayed runs, mapping in runs, suspension inside a run), and `tests/Integration/HooksTest.php` against a real server (an interceptor recording every attempt and executed run of a suspending handler; a domain exception mapped to a terminal 422 from a handler and from a run, without retries).
