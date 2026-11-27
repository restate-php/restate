# Named Signals

## Status

Accepted. Respects the [Public API Boundary](0016-public-api-boundary.md).

## Context

Restate delivers named signals to an invocation: another handler resolves or rejects a signal by the target's invocation id and the signal's name, and the target waits for it like for any other durable result. Two facts settle whether the SDK exposes them:

- **The official SDKs expose named signals as public API** (read in `restatedev/sdk-typescript` `492920d`, `sdk-java` `6b0a96c`, `sdk-python` `377d634`):
  - TypeScript: `ctx.signal<T>(name, serde?)` returns a `RestatePromise<T>` usable in the combinators; `ctx.invocation(id)` returns an `InvocationReference` whose `signal(name, serde?)` is a `SignalReference` with `resolve(payload?)` and `reject(reason: string | TerminalError)`.
  - Java: `ctx.signal(name, Class|TypeTag)` returns a `DurableFuture<T>`; `InvocationHandle.signal(name)` returns a `SignalHandle` with `resolve(TypeTag, payload)` and `reject(String reason)`.
  - Python: `ctx.signal(name, serde)` returns a `RestateDurableFuture`; `ctx.resolve_signal(invocation_id, name, value)` and `ctx.reject_signal(invocation_id, name, message, code=500)`.
- **The conformance suite requires them**: `restatedev/e2e` v2.3 has 12 signal cases (`Signals.*`, `SignalsFuzz.randomAwaitsAndSignals`, and the `signal*` / `mixed*` cases of `Combinators`), driven through `VirtualObjectCommandInterpreter`'s `createSignal` awaitable and `TestUtilsService.resolveSignal` / `rejectSignal`.

`restate-sdk-shared-core` 8.x provides the operations: `create_signal_handle(name) -> NotificationHandle` (journals nothing; maps the handle to `NotificationId::SignalName`) and `sys_complete_signal(target_invocation_id, name, NonEmptyValue)` (journals a `SendSignalCommandMessage` with `SignalId::Name`; failure metadata needs v6 like every other failure). Repeated deliveries of one signal are governed by `VMOptions::signal_delivery`; the default `SignalDelivery::Queued` keeps every delivery and hands them out in journal order, one per handle (sdk-shared-core#99), which is what `Signals.sameSignalDeliveredTwiceWhileAwaitingSomethingElse` checks. Neither operation checks the protocol version: named signals (`SignalId::Name` in `SignalNotificationMessage`, `SendSignalCommandMessage`, `SuspensionMessage.waiting_named_signals`) are part of every protocol version shared-core speaks (v5 to v8), and on v7 a suspension or awaiting-on tree carries them in `Future.waiting_named_signals`.

## Decision

### Public API

Java's shape, which matches the PHP API already in place (`Context::invocation()` returns an `InvocationHandle`; awakeables and promises put the serde on `resolve()`):

```php
// Wait: a DurableFuture, usable in every combinator.
$approved = $ctx->signal('approved')->awaitResult();
$winner = $ctx->select(['signal' => $ctx->signal('approved'), 'timeout' => $ctx->sleepFuture(60_000)]);

// Send, from any handler, to an invocation by id (or a handle from send()).
$ctx->invocation($invocationId)->signal('approved')->resolve(true);
$ctx->invocation($invocationId)->signal('approved')->reject(new TerminalError('Request denied', 403));
```

- `Context::signal(string $name, Serde|string|null $serde = null): DurableFuture` — the next delivery of the signal to this invocation, decoded with the serde or the context's default serde (and the Journal Value Codec); a rejection fails it with its `TerminalError` (code, message, metadata). Waiting journals nothing.
- `InvocationHandle::signal(string $name): SignalHandle` — the signal of that invocation. It needs the invocation id: a handle from `send()` waits for the id (like `cancel()`), and a handle without a known id (attached by idempotency key or workflow) throws a `\RuntimeException` when the signal is sent.
- `SignalHandle` (public interface, implementation `Internal\Context\SignalHandleImpl`): `resolve(mixed $value = null, Serde|string|null $serde = null): void` (default serde: the context's default serde, not the output serde the handle was created with) and `reject(TerminalError $failure): void`. Sending journals a command and does not wait for the target.
- An empty signal name is an `\InvalidArgumentException` before anything reaches the backend (the core also rejects it as `invalid_argument`).

Not mirrored: TypeScript's `reject(string)` and Python's message-and-code rejection (PHP's `rejectAwakeable()` and `DurablePromise::reject()` take a `TerminalError`, and so does this; the default `TerminalError` code is 500, as TypeScript and Python use), Python's context-level `resolve_signal` / `reject_signal` (the handle is the one way, as in Java and TypeScript), and a `SignalDelivery::Overwrite` option.

### Semantics

- Signals are scoped to an invocation and identified by name; they need no registration. A delivery that arrives before the handler waits is kept (in the journal) until a `signal()` call takes it. Each `signal()` call takes one delivery, in the order Restate delivered them, so delivering the same signal twice is seen twice.
- A signal future is an ordinary `DurableFuture` over a handle on the native await tree: `all`, `race`, `any`, `allSettled`, `select`, `waitCompleted` combine it with calls, timers, awakeables and promises, and a v7 suspension reports the signal names in the await point.
- Unlike an awakeable, a signal has no id to hand out: the sender needs the target's invocation id (`$ctx->request()->id`) and the name.

### Backend

The layers below the public API:

| Layer | Added |
| --- | --- |
| Core (`restate_php_core::Vm`) | `sys_signal(name) -> Handle`, `complete_signal_success(invocation_id, name, payload)`, `complete_signal_failure(invocation_id, name, TerminalFailure)`; `validation::signal_name` |
| Extension (`Restate\Extension\Vm`) | `sysSignal(string $name): int`, `completeSignalSuccess(string $invocation_id, string $name, string $payload): void`, `completeSignalFailure(string $invocation_id, string $name, int $code, string $message, array $metadata): void` |
| Backend Contract (`Internal\InvocationOperations`) | `sysSignal(string $name): int`, `sysResolveSignal(string $invocationId, string $name, string $payload): void`, `sysRejectSignal(string $invocationId, string $name, int $code, string $message, array $metadata = []): void` |

### Server and protocol requirements

None beyond the SDK's own (Restate 1.8): named signals are part of every protocol version shared-core speaks. The integration suite's `SignalTest` runs them against Restate 1.8.1 (`just test integration php-cli`). Failure metadata on a rejection needs v6, like every terminal failure with metadata.

## Consequences

- The 12 signal cases of the conformance suite pass.
- Test doubles implementing `Context` or `InvocationHandle` implement `signal()` too (the compatibility promise lets SDK-implemented interfaces grow, [Public API Boundary](0016-public-api-boundary.md#compatibility-promise)).
