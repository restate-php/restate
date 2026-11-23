<?php

/**
 * Stubs for the Restate PHP extension (module `restate`, built from extension/extension).
 *
 * The extension defines everything here; this file only describes it for PHPStan and IDEs and is never loaded at
 * runtime. tests/Unit/Sdk/Backend/ExtensionStubTest.php compares it with the loaded extension, so a change to the
 * extension's classes, methods, parameters or return types must be mirrored here.
 *
 * Everything in `Restate\Extension` is internal to the SDK: `Restate\Internal\Backend\Extension` is its only user, and
 * the shapes below are versioned by contract_version(), not by the package's public API.
 */

declare(strict_types=1);

namespace Restate\Extension;

/**
 * The extension's own version (its crate version, also `phpversion('restate')`; see docs/compatibility.md).
 *
 * @internal
 */
function version(): string
{
}

/**
 * The version of the PHP-facing contract this build implements: the classes, functions and array shapes in this
 * file. The SDK refuses to use an extension whose contract version differs from the one it was written for.
 *
 * @internal
 */
function contract_version(): int
{
}

/**
 * The service protocol versions this build speaks (inclusive): the range discovery advertises.
 *
 * @internal
 *
 * @return array{min: int, max: int}
 */
function protocol_versions(): array
{
}

/**
 * Resource counters for soak tests (tests/Soak), process-wide: `Vm` objects whose core VM is not dropped yet,
 * `IdentityVerifier` objects, and the bytes and allocations of a counting global allocator, which sees the Rust heap
 * that `memory_get_usage()` does not.
 *
 * Optional: only a build with the `diagnostics` cargo feature defines it (no release does). Check with
 * `function_exists()`.
 *
 * @internal
 *
 * @restate-optional diagnostics cargo feature
 *
 * @return array{live_vms: int, live_identity_verifiers: int, rust_live_bytes: int, rust_allocations: int}
 */
function diagnostics(): array
{
}

/**
 * Every failure the extension reports. `getCode()` is the shared-core error code when the failure came from shared-core (for
 * example 415, 570, 571, 598), otherwise 0. A Rust panic is one too (category `internal`, message `Rust panic: …`);
 * the Vm it happened on is poisoned and every later call on that Vm throws an `internal` ExtensionException.
 *
 * @internal
 */
final class ExtensionException extends \RuntimeException
{
    /**
     * invalid_argument, suspended, protocol, journal_mismatch, closed or internal.
     *
     * @var 'invalid_argument'|'suspended'|'protocol'|'journal_mismatch'|'closed'|'internal'
     */
    public $category = 'internal';
}

/**
 * `restate-sdk-shared-core`'s request identity verifier (its `request_identity` feature, the verifier the official
 * TypeScript and Python SDKs use) for a set of identity keys. Built once per endpoint and reused for every request; it
 * holds no per-request state, cannot be cloned or serialized, and is released when PHP frees it.
 *
 * @internal
 */
final class IdentityVerifier
{
    /**
     * @param list<string> $keys identity keys in Restate's `publickeyv1_<base58>` format
     *
     * @throws ExtensionException `invalid_argument` when a key is malformed (an element that is not a string, a missing
     *                         prefix, invalid base58, or not 32 bytes)
     */
    public function __construct(array $keys)
    {
    }

    /**
     * Checks the request's `x-restate-signature-scheme` / `x-restate-jwt-v1` headers (names in any case; values must be
     * valid UTF-8) against the keys for the audience shared-core derives from $path (`/invoke/<Service>/<handler>` or
     * `/discover` after any mount prefix). Without keys every request is accepted.
     *
     * @param list<array{0: string, 1: string}> $headers
     *
     * @return ?string null when the request carries a valid identity, otherwise shared-core's reason
     */
    public function verify(array $headers, string $path): ?string
    {
    }
}

/**
 * One invocation's `restate-sdk-shared-core` VM. PHP owns the object; the VM is released by free(), or when PHP frees
 * the object if that comes first. It cannot be cloned or serialized.
 *
 * No operation waits on its own: every operation that produces a result returns a handle. `doAwait()` /
 * `doAwaitTree()` drive the VM and return `any_completed` or a progress state, one of
 * `['type' => 'suspended'|'read_from_input'|'waiting_pending_run'|'cancel_signal_received']` and
 * `['type' => 'execute_run', 'handle' => int]`; the handle stays valid whatever the state. Once it completed,
 * `takeNotificationResult()` (runs: `takeRunResult()`) returns its result. A completed `doAwaitTree()` lists the tree's
 * handles that completed, so a combinator needs no `isCompleted()` call per handle.
 *
 * Every method throws ExtensionException on failure.
 *
 * Failure messages and stack traces (`writeOutputFailure`, `notifyError`, `notifyPause`, `proposeRunFailure`,
 * `proposeRunRetryable`, `proposeRunPause`, `sysRejectPromise`, `completeAwakeableFailure`, `completeSignalFailure`) may be any bytes: invalid UTF-8 becomes U+FFFD. Every other string,
 * including header and metadata keys and values, must be valid UTF-8 (the SDK substitutes before calling).
 *
 * @internal
 *
 * @phpstan-type Headers list<array{0: string, 1: string}>
 * @phpstan-type Metadata array<array-key, string>
 * @phpstan-type Pending array{type: 'suspended'|'read_from_input'|'waiting_pending_run'|'cancel_signal_received'}|array{type: 'execute_run', handle: int}
 * @phpstan-type AwaitTree array{0: 'first_completed'|'all_completed'|'first_succeeded_or_all_failed'|'all_succeeded_or_first_failed', 1: non-empty-list<int|array{0: string, 1: array<array-key, mixed>}>}
 * @phpstan-type Failure array{type: 'failure', code: int, message: string, metadata: Metadata}
 * @phpstan-type CallTarget array{kind: 'service'|'object'|'workflow', service: string, handler: string, key: ?string, scope?: ?string, limit_key?: ?string, headers?: Headers}
 * @phpstan-type AttachTarget array{kind: 'invocation', invocation_id: string}|array{kind: 'workflow', name: string, key: string, scope?: ?string}|array{kind: 'idempotency', service_name: string, service_key: ?string, handler_name: string, idempotency_key: string, scope?: ?string}
 * @phpstan-type RetryPolicy array{type: 'infinite'|'none'}|array{type: 'fixed_delay', interval_millis?: ?int, max_attempts?: ?int, max_duration_millis?: ?int, on_max_attempts?: 'fail'|'pause'}|array{type: 'exponential', initial_interval_millis: int, factor: float|int, max_interval_millis?: ?int, max_attempts?: ?int, max_duration_millis?: ?int, on_max_attempts?: 'fail'|'pause'}
 * @phpstan-type LogRecord array{level: 'error'|'warn'|'info'|'debug'|'trace', target: string, message: string, fields: array<string, string>}
 */
final class Vm
{
    /**
     * @param Headers $headers the request headers
     * @param 'error'|'warn'|'info'|'debug'|'trace'|null $log_level capture shared-core's log events at this `tracing` level
     *                                                       or more severe, from creation on, for takeLogs(); null: none
     */
    public function __construct(array $headers, ?string $log_level = null)
    {
    }

    /**
     * Releases the VM now (journal, buffers, captured logs) rather than when PHP frees this object, which something
     * else may still reference (an exception trace that recorded it as an argument). Every later call throws an
     * `internal` ExtensionException; freeing again does nothing.
     */
    public function free(): void
    {
    }

    /**
     * The shared-core log events captured since the last call (by this constructor or any method), oldest first: the
     * formatted message, the Rust module that logged it, and its other fields formatted. Always empty without a log
     * level. Past 1000 uncollected records, further ones are dropped and reported as one `warn` record.
     *
     * @return list<LogRecord>
     */
    public function takeLogs(): array
    {
    }

    /** @return array{status_code: int, headers: Headers} */
    public function responseHead(): array
    {
    }

    public function notifyInput(string $bytes): void
    {
    }

    public function notifyInputClosed(): void
    {
    }

    public function isReadyToExecute(): bool
    {
    }

    public function isReplaying(): bool
    {
    }

    /**
     * The invocation's input; `random_seed` is the unsigned 64-bit seed reinterpreted as a signed integer. `scope`,
     * `limit_key` and `idempotency_key` are what the invocation was called with; Restate sends them from protocol v7
     * on, so they are always null on v5 and v6.
     *
     * @return array{invocation_id: string, random_seed: int, key: string, headers: Headers, input: string, scope: ?string, limit_key: ?string, idempotency_key: ?string}
     */
    public function input(): array
    {
    }

    public function writeOutputSuccess(string $bytes): void
    {
    }

    /** @param Metadata $metadata */
    public function writeOutputFailure(int $code, string $message, array $metadata): void
    {
    }

    /**
     * Reports a retryable failure with `$code` (500 unless 400–599 and not 570); `$retry_after_millis` asks Restate to
     * wait that long before the next attempt. The protocol's error message carries no metadata.
     */
    public function notifyError(int $code, string $message, string $stacktrace, ?int $retry_after_millis = null): void
    {
    }

    /**
     * Ends the attempt asking Restate to pause the invocation instead of retrying it. Needs protocol v7: below it the
     * attempt fails with 573 (unsupported feature) instead, which Restate retries.
     */
    public function notifyPause(string $message, string $stacktrace): void
    {
    }

    public function end(): void
    {
    }

    /** The next chunk of the response body, or null once it is complete. */
    public function takeOutput(): ?string
    {
    }

    /** @return array{type: 'any_completed'}|Pending */
    public function doAwait(int $handle): array
    {
    }

    /**
     * Awaits a combinator tree: `[combinator, [child, …]]`, each child a handle or another tree (at most 32
     * combinators deep; every combinator needs a child). `first_completed` resolves when a child completes,
     * `all_completed` when every child did, `first_succeeded_or_all_failed` on the first success or once every child
     * failed, `all_succeeded_or_first_failed` once every child succeeded or on the first failure. `any_completed`
     * means the tree, or a combinator nested in it, resolved; `completed` then lists the tree's handles that are
     * completed, depth first and each as often as it occurs, as `isCompleted()` on each would report right after the
     * await. Results are processed one at a time in arrival order, and processing stops once the tree resolves.
     *
     * @param AwaitTree $tree
     *
     * @return array{type: 'any_completed', completed: list<int>}|Pending
     */
    public function doAwaitTree(array $tree): array
    {
    }

    /**
     * Whether an await has processed this handle's result and the result has not been taken yet. A taken or unknown
     * handle is not completed.
     */
    public function isCompleted(int $handle): bool
    {
    }

    /**
     * The result of a completed handle (anything but a run): a call, send (`invocation_id`), attach, get-output,
     * sleep, awakeable, state get (`void`: not set), state keys, promise get, peek (`void`: not completed yet) or
     * completion (`void`, or a failure when the promise was already completed).
     *
     * @return array{type: 'success', value: string}|array{type: 'void'}|Failure|array{type: 'invocation_id', invocation_id: string}|array{type: 'state_keys', keys: list<string>}
     */
    public function takeNotificationResult(int $handle): array
    {
    }

    public function sysRun(string $name): int
    {
    }

    /** The success payload of a completed run. */
    public function takeRunResult(int $handle): string
    {
    }

    /** @param RetryPolicy|null $retry_policy null means infinite */
    public function proposeRunSuccess(int $handle, string $bytes, ?array $retry_policy = null): void
    {
    }

    /**
     * @param Metadata $metadata
     * @param RetryPolicy|null $retry_policy null means infinite
     */
    public function proposeRunFailure(int $handle, int $code, string $message, array $metadata, ?array $retry_policy = null): void
    {
    }

    /**
     * Once the policy is exhausted the failure is the run's terminal result with $code (500 unless it is 400–599 and
     * not 570) and $metadata — or, under a policy with `on_max_attempts: 'pause'`, the invocation pauses (protocol v7;
     * below it a retryable failure under such a policy fails with 573). $retry_after_millis replaces the policy's delay
     * before the next attempt. `attempt_ended`: the run did not complete and the attempt must stop (a retry was
     * scheduled or, under a pausing policy, the invocation pauses; the two cannot be told apart).
     *
     * @param Metadata $metadata
     * @param RetryPolicy|null $retry_policy null means infinite
     *
     * @return array{type: 'attempt_ended'|'completed'}
     */
    public function proposeRunRetryable(int $handle, int $code, string $message, array $metadata, int $attempt_duration_millis, ?array $retry_policy = null, ?int $retry_after_millis = null): array
    {
    }

    /**
     * Ends the run's attempt, and the invocation's, asking Restate to pause the invocation instead of retrying; the run
     * executes again once the invocation is resumed. Needs protocol v7: below it this throws (573) and the attempt
     * fails for Restate to retry.
     */
    public function proposeRunPause(int $handle, string $message, int $attempt_duration_millis): void
    {
    }

    /** Starts a state read; the result is `success` or `void`. */
    public function sysGetState(string $key): int
    {
    }

    /** Starts reading the state keys; the result is `state_keys`. */
    public function sysGetStateKeys(): int
    {
    }

    public function setState(string $key, string $bytes): void
    {
    }

    public function clearState(string $key): void
    {
    }

    public function clearAllState(): void
    {
    }

    /** Starts a promise get; the result is `success`, `void` or a failure once the promise is completed. */
    public function sysGetPromise(string $key): int
    {
    }

    /** Starts a promise peek; the result is `void` while the promise is not completed. */
    public function sysPeekPromise(string $key): int
    {
    }

    /** Starts resolving a promise; the result is `void`, or a failure when it was already completed. */
    public function sysResolvePromise(string $key, string $bytes): int
    {
    }

    /**
     * Starts rejecting a promise; completes like sysResolvePromise().
     *
     * @param Metadata $metadata
     */
    public function sysRejectPromise(string $key, int $code, string $message, array $metadata): int
    {
    }

    /**
     * Starts a call. A `scope` or `limit_key` on the target needs protocol v7: below it the attempt fails with 573 and
     * this throws (`protocol`); an empty one, or a limit key without a scope, throws `invalid_argument`.
     *
     * @param CallTarget $target
     */
    public function sysCall(array $target, string $payload, ?string $idempotency_key = null): int
    {
    }

    /**
     * Starts a one-way send; scope and limit key as for sysCall().
     *
     * @param CallTarget $target
     */
    public function sysSend(array $target, string $payload, ?int $delay_millis = null, ?string $idempotency_key = null): int
    {
    }

    /**
     * Starts attaching to an invocation. A `scope` on a workflow or idempotency target needs protocol v7, as for
     * sysCall().
     *
     * @param AttachTarget $target
     */
    public function sysAttachInvocation(array $target): int
    {
    }

    /** @param AttachTarget $target */
    public function sysGetInvocationOutput(array $target): int
    {
    }

    public function sysCancelInvocation(string $invocation_id): void
    {
    }

    public function sysSleep(int $delay_millis): int
    {
    }

    /** @return array{id: string, handle: int} */
    public function sysAwakeable(): array
    {
    }

    public function completeAwakeableSuccess(string $id, string $payload): void
    {
    }

    /** @param Metadata $metadata */
    public function completeAwakeableFailure(string $id, int $code, string $message, array $metadata): void
    {
    }

    /**
     * A handle on the next delivery of the named signal to this invocation, taken in delivery order. Journals nothing.
     * An empty name is an `invalid_argument`.
     */
    public function sysSignal(string $name): int
    {
    }

    public function completeSignalSuccess(string $invocation_id, string $name, string $payload): void
    {
    }

    /** @param Metadata $metadata */
    public function completeSignalFailure(string $invocation_id, string $name, int $code, string $message, array $metadata): void
    {
    }
}
