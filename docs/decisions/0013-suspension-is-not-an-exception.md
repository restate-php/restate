# Suspension Is Not an Exception

## Status

Accepted.

## Decision

1. `Restate\Internal\SuspensionException` extends `\Error`, not `\RuntimeException`. A handler's `catch (\Exception $e)` does not see a Suspension. It is `@internal` and `final`. It is an `\Error` named "Exception", as Java's `AbortedExecutionException` is a `Throwable`.
2. Handlers must not catch `\Throwable` (or `\Error`, or `SuspensionException`) around context calls. Catch `TerminalError` (or your own exception types) instead. This is documented in `README.md` ("Error Handling in Handlers").
3. Context operations fail fast once the invocation is suspended. `Endpoint` wraps the backend invocation in `Restate\Internal\SuspensionGuard` before building the handler's `Context`. The guard marks the attempt as suspended when:
   - an await (`doAwait` / `doAwaitTree`) returns `suspended`;
   - a backend operation throws `SuspensionException` (state get, promise get/peek/resolution under lazy state);
   - a `Context::run` retryable failure returns `retry_scheduled` (the core ends the attempt to retry the run later; `Context::run` throws `SuspensionException('Run retry scheduled.')`).

   After that, every journal operation on the context throws a new `SuspensionException` ("The invocation is suspended; no context operation can run in this attempt. Do not catch \Throwable around context calls.") without calling the backend. Operations that do not touch the journal (`randInt`, `headers()`, `isReplaying()`, results of futures that had already completed) still work.
4. `Endpoint` ignores the handler's outcome once the guard saw a Suspension: a returned value is not written, a thrown `TerminalError` is not written as a terminal failure, and any other exception (for example one that wraps the suspension as `previous`) is not reported with `notifyError`. The response carries the suspension the core already wrote.

`Context::run` already rethrows `SuspensionException` raised inside its callback without proposing a completion; that ordering is unchanged and still needed, because its last `catch (\Throwable)` arm (retry the run) would otherwise catch an `\Error`. If a run callback swallows a Suspension and returns, the run's completion proposal hits the guard and the Suspension propagates out of `Context::run`.

## Context

In request/response mode the handler suspends whenever it awaits something not yet in the journal (a sleep, a call, an awakeable): the SDK throws `SuspensionException` to unwind the handler, and `Endpoint` returns the suspension the core wrote. A handler like

```php
try {
    $ctx->sleep(1000);
} catch (\Exception $e) {
    $logger->error('sleep failed', ['e' => $e]);
    return 'fallback';
}
```

would run its error path on every first attempt if the suspension were a `\RuntimeException`. The protocol response would still be a suspension, because the core has already closed the invocation (later writes fail against the closed VM, and those errors would be swallowed too), so the final result looks right; but the catch block's side effects (logging, metrics, files, HTTP calls) run for a failure that did not happen. With a `\RuntimeException`, the integration test `TimerTest::testSleepInsideCatchExceptionStillSuspendsAndResumes` saw exactly that: the handler wrote `Restate\Internal\SuspensionException` to its "caught" file and the invocation still returned `"slept 300ms"`. If the catch block threw a `TerminalError`, `Endpoint` tried to write a terminal failure to the closed VM from inside a `catch` block, and that backend exception escaped `handleRequest()`.

PHP's `\Throwable` has exactly two roots that user classes can extend, `\Exception` and `\Error`, so `\Error` is the only base that `catch (\Exception)` misses. There is no PHP equivalent of Python's `BaseException` that sits outside a catch-all, so the SDK cannot stop `catch (\Throwable)`; the guard makes that case harmless instead of trying to prevent it.

### What the official SDKs do

Read in the official SDK sources (`restatedev/sdk-python` `377d634`, `restatedev/sdk-typescript` `492920d`, `restatedev/sdk-java` `6b0a96c`):

- **Python** — `restate/exceptions.py`: `class SdkInternalBaseException(BaseException)` ("This exception is internal, and you should not catch it"), with `SuspendedException` and `SdkInternalException` under it. Deriving from `BaseException` means `except Exception:` does not catch it; the message tells users not to use bare `except:` and to catch `TerminalError`. `server_context.py` lets `SdkInternalBaseException` pass silently at the top level, re-raises it from `ctx.run` without proposing a completion, and when a handler raises some other exception it walks the `__cause__` chain: if it finds an `SdkInternalBaseException` the attempt ends without reporting an error. `is_internal_exception()` is public. This PHP change is the same idea: `\Error` plays the role of `BaseException`, and the guard plays the role of the cause walk (it covers wrapping and also swallow-and-return).
- **Java/Kotlin** — `dev.restate.sdk.common.AbortedExecutionException extends Throwable` (not `Exception`), with the Javadoc "You MUST NOT catch this exception." It is sneaky-thrown on suspension. Once the state machine is closed, every later operation throws it again (`FfmStateMachine.abortAfterCoreClosed()` / `verifyNotFreed()`, `HandlerContextImpl` fails the returned future with `AbortedExecutionException.INSTANCE`). `ExceptionUtils.containsAbortedExecutionException` searches the cause chain. Same shape as this decision: a non-`Exception` throwable plus fail-fast after closing.
- **TypeScript** — suspension is not thrown into user code at all. The attempt is ended out of band (`ContextImpl.abortAttempt()` rejects `invocationEndPromise`), and the promise the handler awaits stays pending forever (`pendingPromise()`), so no `try`/`catch` in the handler observes it. Operations after the VM closed fail with the core's `CLOSED` code (598) and are logged as "DANGER! The invocation is closed, but some related operation is still running". `isSuspendedError()` exists for interceptors. PHP has no event loop to abandon a call stack on, so it cannot do this; it has to unwind with a throwable.

All three treat suspension as something user code cannot catch with a normal catch-all and make anything after it inert. None lets the handler's own outcome win after a suspension.

### Alternatives considered

- **Keep `\RuntimeException` and only document it.** The common `catch (\Exception)` stays broken.
- **Fail fast with the core's own closed state.** After suspension shared-core rejects every operation with `ClosedError`, but the backends turn that into a `BackendException` (a `\RuntimeException`) that `catch (\Exception)` catches again. The PHP-side guard works the same for the extension backend and the unit-test fakes, and stops the operation before it reaches the backend.
- **Rename to `SuspendedError` or `AbortedExecution`.** Cosmetic: it is internal ([Exception Hierarchy](0015-exception-hierarchy.md)).

## Consequences

- `catch (\Exception)` and `catch (\RuntimeException)` around context calls are safe. `catch (\Throwable)` and `catch (\Error)` are not recommended, but do not corrupt anything: the handler's later context calls throw again and its outcome is ignored. Code inside such a catch block still runs once (the SDK cannot prevent that).
- Tools that treat `\Error` as a programming error (error trackers, `set_exception_handler`) never see it in normal operation: `Endpoint` catches it.
- `Endpoint` builds the handler's context on a `SuspensionGuard`. A `Context` constructed directly (in unit tests) has no guard; only the request path gets fail-fast behaviour.
- Cancellation is not affected: a cancel signal still throws `TerminalError('Invocation cancelled', 409)`, which handlers may catch to run compensation.
- Evidence: `tests/Unit/Sdk/HandlerSuspensionTest.php` (unit) and `tests/Integration/TimerTest.php::testSleepInsideCatchExceptionStillSuspendsAndResumes` (real server).
