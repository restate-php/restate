# Backend Architecture

The SDK (`restate/sdk`, `packages/sdk/`) separates the public service API from the backend that speaks the Restate service protocol. There is one backend, the Extension Backend: the PHP extension, which drives `restate-sdk-shared-core` (pinned `=8.7.2`) compiled into it.

```text
Application code
  -> Endpoint::serve() / Endpoint::psr() / Endpoint::builder()
       -> Extension backend (or BackendUnavailableException)
       -> Invocation (one per request)
            -> Context, DurableFuture, ... through InvocationOperations
```

## Public Boundary

Applications enter through `Endpoint::serve()`, `Endpoint::psr(...)`, or `Endpoint::builder()`. There is no backend selection: `EndpointBuilder::build()` constructs the extension backend, and when the extension is not loaded or fails the version handshake it throws `BackendUnavailableException` with install instructions (`pie install restate/ext-restate`, which enables `extension=restate`, or building from source) and the contract version this package requires. There is no fallback.

The concrete backend classes live under `Restate\Internal\Backend\` and are not public API; [Public API Boundary](../decisions/0016-public-api-boundary.md) defines the boundary and how it is enforced.

## Internal Seam: the Backend Contract

`Restate\Internal\Backend` creates one `Restate\Internal\Backend\Invocation` per request. `Invocation` extends `Restate\Internal\InvocationOperations`, which is what `Context` and the other runtime classes call. Together they are the Backend Contract. The unit tests implement them with `tests/Support/ScriptedInvocation.php` and `FakeBackend.php` (see [Testing](../../TESTING.md#unit-tests)); the extension backend implements them for real traffic.

The contract is typed; no JSON, base64 or `array{type: …}` crosses it:

| Value (`Restate\Internal\Backend\…` unless noted) | Where |
| --- | --- |
| `AwaitResult` (an `AwaitState` — completed, suspended, cancel signal received, execute run with its handle, read from input, waiting pending run; a completed `doAwaitTree()` also carries the tree's handles then completed, `completedHandles`) | `doAwait()`, `doAwaitTree()` |
| `AwaitTree` (a `Restate\Internal\AwaitCombinator` over handles and nested trees; `AwaitCombinator::tree()` builds one) | `doAwaitTree()` |
| `NotificationResult` (a `NotificationKind` — success with its bytes, void, failure with code, message and metadata, invocation id, state keys) | `takeNotificationResult()` |
| `RunCompletionStatus` (retry scheduled, completed) | `proposeRunCompletionRetryable()` |
| `CallTarget` (a public `Restate\Target` plus the callee's headers) | `sysCall()`, `sysSend()` |
| `Restate\AttachTarget`, `Restate\RunRetryPolicy` (public value objects, used as they are) | `sysAttachInvocation()`, `sysGetInvocationOutput()`, run proposals |
| `AwakeableHandle`, `InvocationInput`, `ResponseHead` | `sysAwakeable()`, `input()`, `responseHead()` |

`Backend::protocolVersions()` still returns `array{min: int, max: int}`, which `Internal\Discovery::manifestJson()` takes as is. Failure arguments stay scalars (code, message, metadata map).

`Restate\Internal\ResultDecoder` is the one place that turns what an awaiting operation gets back into what it returns or throws: a suspension is `SuspensionException`, a cancel signal `TerminalError('Invocation cancelled', 409)`, a failure result its `TerminalError`; success bytes go through the Journal Value Codec and the operation's serializer (`json()`: malformed JSON is the operation's failure; `serde()`: the serializer's own exceptions propagate). `Context`, `DurableFuture`, `InvocationHandle`, `Awakeable`, `DurablePromise` and the object contexts all decode through it, and no code matches exception message text.

## Extension Backend

The backend and its core share the Cargo workspace in `extension/` (see [TESTING.md](../../TESTING.md#rust-code)):

| Backend | PHP class | Crate | Loaded with |
| --- | --- | --- | --- |
| PHP extension | `Restate\Internal\Backend\Extension\Backend` | `extension/extension` (`ext-php-rs` 0.16.5; package and module name `restate`) | `extension=…/restate.so`, or `extension=restate` with `restate.so` in `extension_dir` |

### Rust Core

`extension/core` is the single implementation of every operation; the extension crate is glue over it. It is plain Rust with no PHP dependency (`cargo test -p restate-php-core` runs without PHP), and it is the only crate that depends on `restate-sdk-shared-core`.

| Module | Owns |
| --- | --- |
| `vm` | `Vm`: one invocation's `CoreVM`, VM construction and every `sys_*` operation. An operation that produces a result (call, send, attach, sleep, awakeable, signal, run, state get and keys, promise get, peek and complete) returns a handle and never drives the VM itself; `do_await` (one handle) / `do_await_tree` (an `AwaitTree`) drive it and return a `Progress` (a completed tree await carries the tree's handles then completed, depth first: what `is_completed` would report for each, in one call), and `take_notification_result` / `take_run_result` take the result once the handle completed, so a handle survives every progress state (needed for streaming and async runs) |
| `await_tree` | `AwaitTree`: what one await waits for, a handle or a combinator (`FirstCompleted`, `AllCompleted`, `FirstSucceededOrAllFailed`, `AllSucceededOrFirstFailed`) over child trees; shared-core's `UnresolvedFuture` without `Unknown`, validated (every combinator has a child, at most 32 deep). Completion means the tree or a combinator nested in it resolved, and the tree is the await point a v7 suspension reports to Restate |
| `progress` | `Progress<T>`: the typed result of awaiting (completed with a value, suspended, cancel signal received, execute run, read from input, waiting pending run) and how `do_await` responses (`WaitingExternalProgress` split back into the last two) and errors map onto it |
| `notification` | decoding a completed notification's value: `NotificationResult` (success, void, failure, invocation id, state keys) and the run result |
| `run` | `RunRetryOutcome`, and the one place that recognises "retry scheduled" (shared-core, 8.7.2 included, has no structured result for it) |
| `validation` | the rules for values PHP passes in: terminal failure codes (400–599, for handler output, run failures, promise and awakeable rejections), handles, non-negative millisecond durations, empty idempotency keys, non-empty signal names, and `lossy_text`: failure messages and stack traces arrive as bytes and invalid UTF-8 becomes U+FFFD, so a non-UTF-8 message cannot fail the error path |
| `types` | plain argument and result types (`TerminalFailure`, `CallTarget`, `AttachTarget`, `RetryPolicy`, `InvocationInput`, …) |
| `guard` | containing Rust panics: `catch_panic` turns a panic into an `internal` `Error`; `Poisonable` runs operations on a value under it and refuses every operation after one panicked (the extension's `Vm` is one) |
| `identity` | `IdentityVerifier`: shared-core's request identity verifier built from the identity keys, `verify` returning shared-core's reason for a rejection. jsonwebtoken picks the `rust_crypto` provider itself (the only one enabled), so nothing is installed |
| `logs` | capturing shared-core's `tracing` events: a VM created with a `LogLevel` (`Vm::with_log_level`) owns a capture that keeps the events at or above it (never spans) in a bounded buffer. One process-wide subscriber, installed by the first capture as `tracing`'s global default, hands each event to the calling thread's active capture: `Vm::logged` pushes the VM's capture on a thread-local stack while one operation runs. `Vm::take_logs` drains the buffer. No dispatcher per VM, nothing shared between captures or threads |
| `error` | `Error`: a category (`invalid_argument`, `suspended`, `protocol`, `journal_mismatch`, `closed`, `internal`), the shared-core code when there is one, and the message. `invalid_argument` is kept as its own category rather than folded into `internal`: it marks a value the SDK passed that breaks the core contract (an SDK bug), while `internal` is a failure inside shared-core or the bridge (including a Rust panic) |

The extension crate keeps only `ext-php-rs` glue: converting PHP values to and from core types (`convert.rs`) and raising exceptions (`exception.rs`), plus the panic and bailout probes of debug builds (`probe.rs`) and the optional `diagnostics` feature (`diagnostics.rs`).

### Extension Surface

Everything the extension defines lives in the internal namespace `Restate\Extension`. Only `Restate\Internal\Backend\Extension` uses it; `extension/restate.stub.php` describes it for PHPStan and IDEs, and `tests/Unit/Sdk/Backend/ExtensionStubTest.php` fails when the stub and the loaded extension differ.

| Name | What it is |
| --- | --- |
| `Restate\Extension\Vm` | One invocation's VM. `new Vm($headers)` creates it, `new Vm($headers, $log_level)` one that captures shared-core's log events at that `tracing` level for `takeLogs()` (`Extension\Invocation` forwards them to the endpoint's logger after every call, see [SDK Logging](../decisions/0027-sdk-logging.md)); its methods mirror the core `Vm` one to one (`doAwait`, `getState`, `sysCall`, `proposeRunRetryable`, …). The PHP object owns the core VM, and drops it on `free()` or when the object goes away, whichever comes first: `Endpoint::handleRequest()` calls `Invocation::free()` (part of the internal `Invocation` contract) in a `finally` once the response is built, which calls `Vm::free()`, so a long-lived worker releases the core VM promptly even if the handler kept its context or a logger kept an exception whose trace still references the PHP object (later calls throw an `internal` `ExtensionException`); a fatal error leaves it to request shutdown. `Extension\Invocation` never passes the `Vm` as a function argument (its calls reach it through a closure over the invocation), so with `zend.exception_ignore_args` off no exception trace references the PHP object either; `tests/Extension/VmLifetimeTest.php` checks both. There is no registry of VMs, no integer handle and no lock shared between invocations, so concurrent requests in a thread-safe build do not serialize, and nothing can reach a VM it does not hold. `Vm` cannot be cloned or serialized. |
| `Restate\Extension\IdentityVerifier` | sdk-shared-core's request identity verifier (`request_identity` feature, crypto `rust_crypto`) for an endpoint's identity keys: `new IdentityVerifier($keys)` (a malformed key is an `invalid_argument`), `verify($headers, $path)` returns null or shared-core's reason. `Backend::identityVerifier()` builds one when the endpoint is built, and the endpoint reuses it for every request; it holds no per-request state ([Request Identity Through sdk-shared-core](../decisions/0028-request-identity-shared-core.md)). |
| `Restate\Extension\ExtensionException` | The one exception class for every failure, a final subclass of `\RuntimeException`. `category` is the core error category (`invalid_argument`, `suspended`, `protocol`, `journal_mismatch`, `closed`, `internal`); `getCode()` is the shared-core error code (for example 415, 570, 598) or 0. It records file, line and trace like any PHP exception. Argument type errors stay PHP's own `TypeError`. A Rust panic in a `Vm` method or the constructor is caught (`restate_php_core::guard`) and thrown as a `ExtensionException` with category `internal` and message `Rust panic: …`; the `Vm` that panicked is poisoned, and every later call on it throws an `internal` `ExtensionException` naming the original panic, while other `Vm` objects keep working. Only a panic while `ext-php-rs` parses the PHP arguments, before the method body runs, is still `ext-php-rs`'s own `\Error`. |
| `Restate\Extension\version()` | The extension's crate version (also `phpversion('restate')`); [Extension Versions](../compatibility.md). |
| `Restate\Extension\contract_version()` | The Contract Version: the version of this surface, 1 until the first extension release ([Extension Versions](../compatibility.md) says when it is bumped). |
| `Restate\Extension\protocol_versions()` | The service protocol versions the core speaks, `['min' => 5, 'max' => 8]` with shared-core 8.7.2. `Backend::protocolVersions()` returns it, and discovery advertises it. |
| `Restate\Extension\diagnostics()` | Only in builds with the `diagnostics` cargo feature, which no release has: process-wide counts of live VMs and identity verifiers and of the Rust heap, for the soak suite ([TESTING.md](../../TESTING.md#soak-suite)). The stub marks it `@restate-optional`. |

Values cross as PHP values. Payloads are binary strings in both directions. Structured arguments are arrays (headers as `[[name, value], …]`, call and attach targets, retry policies, failure metadata as `[key => value]`), and handles and durations are integers. Structured results are arrays tagged by `type`. No method waits on its own: an operation that produces a result returns an integer handle, `doAwait()` (one handle) / `doAwaitTree()` (a combinator tree, `[combinator, [child, …]]` with handles or trees as children) return `any_completed` or a progress state (`suspended`, `cancel_signal_received`, `execute_run` with a `handle`, …), a completed tree await lists the tree's handles then completed (`['type' => 'any_completed', 'completed' => [handle, …]]`, so a combinator makes one call per await instead of one `isCompleted()` per future), and `takeNotificationResult()` returns the completed result (for example `['type' => 'success', 'value' => …]` or `['type' => 'state_keys', 'keys' => …]`). On the PHP side `Extension\SurfaceValues` turns these arrays into the typed Backend Contract (and a shape it does not know into an `internal` `BackendException`), and `ResultDecoder` maps suspension, cancellation and results for every awaiting operation. There is no JSON or base64 anywhere on this boundary. PHP strings need not be UTF-8, the protocol needs it: failure messages and stack traces cross as bytes and the core substitutes U+FFFD for invalid sequences; request headers (`Internal\HeaderMap::fromServer()`) and failure metadata (`TerminalMetadata::forBackend()`) are substituted the same way on the PHP side before they reach a backend. Other strings (state keys, names, ids, call-target headers) must still be UTF-8. The shapes are spelled out in the stub.

`Restate\Internal\Backend\Extension\Backend` checks the version handshake when it is constructed, which happens before any endpoint uses it: the extension must report exactly `Backend::CONTRACT_VERSION`, or construction fails with a `BackendException` naming the extension version, both contract versions, the package version, and whether to upgrade the extension or the package. `EndpointBuilder::build()` reports that, and a missing extension, as `BackendUnavailableException`. `Extension\Invocation` adapts the surface to the internal `Invocation` contract and rethrows every `ExtensionException` as `BackendException` (same message and code, `category()` as the `BackendErrorCategory` with the extension category's name, the `ExtensionException` as previous). A `BackendException` the PHP adapter raises itself has the category `internal`.

[Single Native Backend](../decisions/0012-single-native-backend.md) says why this is the only backend: there is no pure-PHP or FFI backend, no backend selection, and no install path for serving without the extension.

## Evidence

- `composer test:integration`: the Integration Harness drives real traffic through a real Restate server ([TESTING.md](../../TESTING.md#integration-tests)).
- `composer test:frankenphp` and `composer test:fpm`: the integration suite against the thread-safe extension build under FrankenPHP, and under php-fpm.
- `composer test:upstream`: the upstream SDK test suite ([TESTING.md](../../TESTING.md#upstream-sdk-test-suite)).

[Status](../status.md#evidence) lists every suite. None of this is an official Restate SDK conformance claim ([Conformance](conformance.md#official-conformance)).
