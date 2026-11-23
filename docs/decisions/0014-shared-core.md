# Shared Core

## Status

Accepted. The current pin is `restate-sdk-shared-core` `=8.7.2`.

## Context

The extension's core crate (`extension/core`) drives [`restate-sdk-shared-core`](https://github.com/restatedev/sdk-shared-core), the protocol state machine the official SDKs share ([Single Native Backend](0012-single-native-backend.md)). Its major version follows the service protocol version (7.x introduced protocol v7, 8.x v8), and it is released often, sometimes with changes only to features this SDK does not enable. Its integration guide (`docs/sdk-integration.md`) lags the code in places (it still describes `take_output()` returning `Buffer`/`EOF` and a `do_progress` method that is now `do_await`), so the source is the reference. Where shared-core does not offer what the SDK needs, the core works around it, and those workarounds have to be rechecked whenever the pin moves.

## Decision

### Pinning and bumping

shared-core is pinned **exactly** in `extension/core/Cargo.toml`, with the features `sha2_random_seed`, `request_identity` and `rust_crypto` ([Request Identity Through sdk-shared-core](0028-request-identity-shared-core.md)) and no others. Its optional `sql`, `tunnel`, `object_store` and `cloud_keys` features pull in tokio, rustls and storage engines and stay off. An exact pin keeps a shared-core release from changing protocol behaviour behind a rebuild.

To bump it:

1. Read the release notes and the source diff between the two tags, not only the guide: the `VM` trait and `CoreVM`, `VMOptions`, `Error`, `RetryPolicy`, the await types, `src/vm/` and the generated protocol module.
2. Recheck every workaround below against the new source, and run its pinning tests. Remove a workaround only when shared-core now does the job, and update the version the code comments name.
3. If the generated protocol module changed, replace the test copy (`extension/core/tests/vm/protocol/`, see `tests/vm/protocol.rs`) with the new version's file.
4. Confirm that `extension/Cargo.lock` changes only in the shared-core entry, or account for every new dependency.
5. Run `composer check`, the integration suite against Restate 1.8 and 1.7, the FrankenPHP suite and the upstream suite ([TESTING.md](../../TESTING.md)), and update the row in [Extension Versions](../compatibility.md).

A bump that changes no API the core uses needs no code change; that was the case from 8.6.0 to 8.7.2, where the new code (`ExplicitRecoveryVM`, which needs a bidirectional stream, and the `sql` feature) is unreachable from the core.

### Protocol versions

shared-core 8.x speaks service protocol v5 to v8. Discovery advertises the range the core reports (`Backend::protocolVersions()`, answered by `Restate\Extension\protocol_versions()`), not a hard-coded one. Restate accepts a deployment whose range overlaps its own and picks the highest version both speak: Restate 1.8 negotiates **v7**, Restate 1.7 **v6**. v8 is advertised, but no released server speaks it; its only addition, `ephemeral_state_get`, is a command the SDK never issues, so nothing on the SDK's path changes with it.

What each version adds on the SDK's path: v6 carries terminal-failure metadata and takes the random seed from Restate. v7 adds the pause behaviour and `OnMaxAttempts::Pause`, scope and limit key on calls, sends and attach targets, the scope, limit key and idempotency key in the invocation input, acknowledged run completions (`ProposeRunCompletionAckMessage`), suspensions and `AwaitingOnMessage` carrying the await tree, and the pause-or-fail option on journal mismatch. shared-core refuses a v7-only feature on v6 with 573 "unsupported feature"; it does not degrade.

The endpoint manifest is negotiated like the TypeScript SDK does it: the newest version from v5 down to v2 that the request's `Accept` header names, `415` when it names none. v5 adds one optional field, `eagerStateKeysWhitelist` ([Selective State Preloading](0020-selective-state-preloading.md)); Restate 1.8 accepts v1–v5, 1.7 v1–v4.

### Server compatibility

- **Restate 1.8 is the minimum supported server:** protocol v7 and manifest v5 by default. Its ingress drops client-supplied `x-restate-*` headers; the only `x-restate-*` headers the SDK relies on are those Restate itself sends to the endpoint (request identity, invocation id). The integration suite runs against 1.8.1 (`RestateContainer::DEFAULT_IMAGE`), and the upstream suite (`restatedev/e2e` `sdk-tests` v2.3) only against 1.8, since it needs 1.8's admin API.
- **Restate 1.7 is best-effort:** protocol v6 (v7 only with `RESTATE_EXPERIMENTAL_ENABLE_PROTOCOL_V7=true`), manifest up to v4. On v6 the input's scope, limit key and idempotency key are absent, and every v7-only feature fails the attempt with 573 and a message naming the operation and the Restate version it needs (pause: [Pause](0021-pause.md); scope and limit key: [Scope and Limit Key](0024-scope-and-limit-key.md)); a list of preloaded state keys fails registration ([Selective State Preloading](0020-selective-state-preloading.md)). Everything else works; `composer test:integration:restate-1.7` runs the integration suite against 1.7.14 as the regression check for protocol v6.
- **Restate 1.6 and older** negotiate v6 and manifest v4 too, but are not tested.

### Run completion heuristic

`propose_run_completion` returns `VMResult<()>`, not an outcome. For a retryable run failure, the `ProposeRunCompletion` transition asks the run's retry policy what comes next:

- **retry:** it sets the error's next retry delay and related command and returns `Err` with **the submitted error itself**, same code and message;
- **pause** (a policy with `OnMaxAttempts::Pause`, v7): it sets the error's behaviour to pause and returns the submitted error the same way;
- **give up:** it records a terminal failure with the error's code, message and metadata as the run's result and returns `Ok(())`.

The error's fields are crate-private, and its public API (`code()`, `message()`, `description()`, `metadata()`) cannot tell the returned error from any other failure. So `run::retryable_outcome` recognises an ended attempt by the submitted code and message coming back. It reports `AttemptEnded` (retry or pause: for the SDK both end the attempt without a run result, and the error message the VM wrote tells Restate which one it is) or `Completed`, and it is the only place this comparison is made. The submitted code is never 570, which shared-core rewrites on v7 (`validation::retryable_failure_code`). The tests in `run.rs`, `tests/vm/run.rs`, `tests/vm/pause.rs` and `tests/vm/v7.rs` pin the behaviour. The heuristic goes away once shared-core reports the outcome itself, for example `propose_run_completion` returning whether it completed the run, scheduled a retry or paused.

### Retry-after on `ctx.run`

A `RetryableError` can ask for a delay before the next attempt. shared-core ignores that for runs: its retry branch overwrites the error's delay override with the interval its policy computes (none for an infinite policy, which means the server's default). It honours the override only through `notify_error`, the handler-level failure, where the endpoint passes the `RetryableError`'s delay unchanged.

`RetryPolicy::next_retry` is stateless: it reads only the policy and the retry count and duration. So the core honours a run's retry-after by proposing that one failure under a substitute policy, `FixedDelay` with the retry-after as interval and the user's policy's attempt and duration limits (`RetryPolicy::with_retry_after` in `types.rs`). The delay changes for that attempt only, and the run still gives up at the same attempt. `tests/vm/run.rs` pins that the written delay is the retry-after, not the policy's interval. This relies on `next_retry` staying stateless.

### Cleared eager state keys

With eager state, shared-core records a cleared key as cleared rather than removing it, and `EagerState::get_keys` still lists it (`src/vm/context.rs`). `Vm::sys_get_state_keys` therefore drops the keys the attempt cleared, and did not set again, before the read started; replay runs the same clears, so a replayed read returns the same keys. With lazy state Restate answers after applying the clears. `tests/vm/state.rs`, `tests/Extension/ExtensionInvocationTest.php` and the integration `VirtualObjectTest` pin it.

### The SDK's own version checks

For a pause, a scoped call or send, and a scoped attach on v6, shared-core's refusal names its internal feature ("error behavior", "scope") and drops the caller's message. The core checks the negotiated version first (`Vm::refuse_pause_below_v7`, `Vm::require_v7`) and fails the attempt with the same 573 and a message naming the operation, the target and the Restate version needed. These are not workarounds for bugs; they stay as long as the messages are better.

### Combinators on the await tree

shared-core awaits a tree (`do_await` with an `UnresolvedFuture`), not a list of handles: `Single`, `FirstCompleted`, `AllCompleted`, `FirstSucceededOrAllFailed` and `AllSucceededOrFirstFailed`, nested freely. On v7 the still-pending part of the tree is what a suspension and `AwaitingOnMessage` carry, so Restate resumes the invocation only when the tree can make progress; v5 and v6 flatten it to every notification in it. The core mirrors it as `AwaitTree` (`Vm::do_await_tree`, which rejects a combinator without children, on which shared-core would either wait forever or resolve at once, and trees nested more than 32 deep), and PHP builds trees only from futures not yet resolved: a handle whose result was taken is unknown to shared-core and would never complete.

shared-core resolves a tree by first checking results already in view, then moving arrived results into view one at a time, in journal order, until the tree or a combinator nested in it resolves (`AnyCompleted` does not mean the root resolved). PHP still evaluates the results itself; the tree decides when the VM reports progress and what Restate waits for:

| PHP | Tree | Why the result is unchanged |
| --- | --- | --- |
| `all()` | `AllSucceededOrFirstFailed` | The tree stops at the first failure in view, so at most one failure is among the newly completed futures, and PHP throws the first failure in key order. |
| `allSettled()` | `AllCompleted` | Every future is awaited; results are in key order. |
| `race()`, `select()`, `waitCompleted()`, `DurableFuturesUnordered::next()` | `FirstCompleted` | PHP takes the first completed future in key order. |
| `any()` | a loop of `FirstCompleted` trees | `DurableFutureAggregateException::failures()` lists failures in completion order, which a `FirstSucceededOrAllFailed` tree cannot report. |

A completed await reports which of the tree's handles it completed ([Tree Awaits Report Their Completed Handles](0036-tree-await-completed-handles.md)). Reducing the whole remaining tree once per result makes wide fan-out quadratic per attempt inside shared-core ([Wide Fan-out](../serving.md#wide-fan-out-costs-quadratic-time-per-attempt)); an upstream fix, [restatedev/sdk-shared-core#122](https://github.com/restatedev/sdk-shared-core/pull/122), is open and not in 8.7.2. `tests/vm/combinators.rs` (v5, v6, v7) and the integration `FutureTest` (Restate 1.8.1 and 1.7.14) cover when each combinator resolves, what a suspension carries, cancellation, and invalid trees.

## Consequences

- Protocol fixes and new protocol features arrive by bumping the pin, after the checks above; a bump never happens implicitly.
- The four workarounds (run completion heuristic, retry-after emulation, cleared eager state keys, own version checks) depend on shared-core internals that are not a documented contract, which is why every bump rechecks them. Asking upstream for a structured run-completion result would remove the heuristic.
- Restate 1.8 is required for the v7 features; on 1.7 they fail visibly instead of degrading.
- In request/response mode the input is closed before the handler runs, so a v7 run completion, which waits for its acknowledgement, still ends the attempt as on v5 and v6.
