//! The PHP extension: `ext-php-rs` glue over `restate-php-core`.
//!
//! The module is named `restate` (the package name; `extension=restate`).
//!
//! Everything PHP sees lives in the internal `Restate\Extension` namespace:
//!
//! - `Restate\Extension\Vm` owns one invocation's core [`Vm`]. PHP owns the
//!   object; the VM is dropped by `free()`, or when PHP frees the object if
//!   that comes first. There is no registry, no integer handle and no lock
//!   shared between invocations. Created with a log level, it captures
//!   shared-core's `tracing` events while its methods run, for `takeLogs()`;
//!   the capture is the VM's own buffer, active on the calling thread for the
//!   call (see `restate_php_core::logs`).
//! - `Restate\Extension\IdentityVerifier` owns shared-core's request identity
//!   verifier for an endpoint's identity keys, built once and reused for
//!   every request ([`PhpIdentityVerifier`]).
//! - `Restate\Extension\ExtensionException` is the one exception class every
//!   failure is thrown as (see [`exception`]), Rust panics included: see
//!   [`PhpVm`] for the panic guard and [`probe`] for how tests trigger it.
//!   The two handshake functions below cannot panic.
//! - `Restate\Extension\version()` and
//!   `Restate\Extension\contract_version()` are the version handshake the
//!   Composer package checks before it uses the extension.
//! - `Restate\Extension\protocol_versions()` is the service protocol range
//!   discovery advertises (from v7, Restate 1.8).
//! - `Restate\Extension\diagnostics()`, only with the `diagnostics` cargo
//!   feature: resource counters for soak tests (see [`diagnostics`]).
//!
//! Values cross as PHP values: payloads as binary strings, structured
//! arguments and results as PHP arrays ([`convert`]). No operation logic lives
//! here; validation, VM driving and notification decoding are in the core.
//! `restate.stub.php` in the extension directory describes this surface
//! for PHPStan and IDEs, and a test compares it with the loaded extension.

mod convert;
#[cfg(feature = "diagnostics")]
mod diagnostics;
mod exception;
mod probe;

use convert::Array;
use exception::ExtensionResult;
use ext_php_rs::binary::Binary;
use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use ext_php_rs::types::ZendHashTable;
use restate_php_core::guard::{catch_panic, Poisonable};
use restate_php_core::{validation, Error, IdentityVerifier, Vm};

/// The version of the PHP-facing contract: the classes, functions, argument
/// and result shapes in this crate. It stays 1 until the first extension
/// release. After that, the first incompatible change to any of them since
/// the last release bumps it, together with `Backend::CONTRACT_VERSION` in
/// `packages/sdk/src/Internal/Backend/Extension/Backend.php` and the extension
/// version (RELEASING.md, "Bumping versions").
pub const CONTRACT_VERSION: i64 = 1;

/// The extension's own version (its crate version, also
/// `phpversion('restate')`); see docs/compatibility.md.
#[php_function]
#[php(name = "Restate\\Extension\\version")]
pub fn version() -> &'static str {
    env!("CARGO_PKG_VERSION")
}

/// The contract version this build implements; see [`CONTRACT_VERSION`].
#[php_function]
#[php(name = "Restate\\Extension\\contract_version")]
pub fn contract_version() -> i64 {
    CONTRACT_VERSION
}

/// The service protocol versions the core speaks, inclusive:
/// `['min' => int, 'max' => int]`. Discovery advertises this range.
#[php_function]
#[php(name = "Restate\\Extension\\protocol_versions")]
pub fn protocol_versions() -> ExtensionResult<Array> {
    catch_panic(|| convert::protocol_versions(restate_php_core::protocol_versions()))
}

/// shared-core's request identity verifier for a set of identity keys, owned
/// by the PHP object. The endpoint builds one and reuses it for every request
/// it serves (in a worker, for the worker's lifetime); it holds no
/// per-request state. Both methods run under [`catch_panic`]; `verify` takes
/// the verifier by shared reference, so a panic leaves nothing half updated
/// and there is no poisoning.
#[php_class]
#[php(name = "Restate\\Extension\\IdentityVerifier")]
#[php(flags = ClassFlags::Final)]
pub struct PhpIdentityVerifier {
    verifier: IdentityVerifier,
    #[cfg(feature = "diagnostics")]
    _live: diagnostics::Live,
}

#[php_impl]
impl PhpIdentityVerifier {
    /// A verifier for these keys (`['publickeyv1_…', …]`); a malformed key is
    /// an `invalid_argument`.
    pub fn __construct(keys: &ZendHashTable) -> ExtensionResult<Self> {
        catch_panic(|| {
            Ok(Self {
                verifier: IdentityVerifier::new(&convert::identity_keys(keys)?)?,
                #[cfg(feature = "diagnostics")]
                _live: diagnostics::Live::identity_verifier(),
            })
        })
    }

    /// `null` when the request with these headers (`[[name, value], …]`) for
    /// this path carries a valid identity, otherwise shared-core's reason.
    pub fn verify(&self, headers: &ZendHashTable, path: String) -> ExtensionResult<Option<String>> {
        catch_panic(|| Ok(self.verifier.verify(&convert::headers(headers)?, &path)))
    }
}

/// One invocation's VM, owned by the PHP object.
///
/// Panic guard: the body of every method, argument conversions included,
/// runs inside [`PhpVm::with`] (the constructor inside [`catch_panic`]), so
/// a Rust panic becomes a `ExtensionException` with category `internal` instead
/// of unwinding towards PHP. A VM that panicked mid-operation may be half
/// updated, so it is poisoned: every later call on the same object fails with
/// an `internal` `ExtensionException` naming the original panic. Other VMs are
/// not affected. What runs before a method body — `ext-php-rs` parsing the
/// PHP arguments into Rust types — is guarded by `ext-php-rs` itself, which
/// turns a panic there into a PHP `\Error`. `probe` makes these paths
/// testable in debug builds.
#[php_class]
#[php(name = "Restate\\Extension\\Vm")]
#[php(flags = ClassFlags::Final)]
pub struct PhpVm {
    /// `None` once `free()` released the VM.
    vm: Option<Poisonable<Vm>>,
    /// Counts the VM as live until it is released.
    #[cfg(feature = "diagnostics")]
    live: Option<diagnostics::Live>,
}

impl PhpVm {
    /// Runs one method body on the VM, under the panic guard; fails on a
    /// freed VM.
    fn with<T>(
        &mut self,
        operation: impl FnOnce(&mut Vm) -> ExtensionResult<T>,
    ) -> ExtensionResult<T> {
        match &mut self.vm {
            Some(vm) => vm.with(|vm| vm.logged(operation)),
            None => Err(Error::internal("Restate VM has already been freed.").into()),
        }
    }
}

#[php_impl]
impl PhpVm {
    /// Creates the VM for a request with these headers (`[[name, value], …]`).
    /// With a `log_level` (`error`, `warn`, `info`, `debug`, `trace`) it
    /// captures shared-core's log events at or above it, from its creation
    /// on, for `takeLogs()`; without one it captures nothing.
    pub fn __construct(
        headers: &ZendHashTable,
        log_level: Option<String>,
    ) -> ExtensionResult<Self> {
        catch_panic(|| {
            let headers = convert::headers(headers)?;
            let log_level = convert::log_level(log_level)?;
            Ok(Self {
                vm: Some(Poisonable::new(Vm::with_log_level(headers, log_level)?)),
                #[cfg(feature = "diagnostics")]
                live: Some(diagnostics::Live::vm()),
            })
        })
    }

    /// Drops the VM now, with its journal, buffers and log capture, instead
    /// of when PHP frees this object: something else may still hold the
    /// object (an exception trace that recorded it as an argument, say).
    /// Every later call throws an `internal` `ExtensionException`; freeing
    /// again does nothing. A poisoned VM is freed too.
    pub fn free(&mut self) -> ExtensionResult<()> {
        let vm = self.vm.take();
        #[cfg(feature = "diagnostics")]
        let live = self.live.take();
        catch_panic(|| {
            drop(vm);
            #[cfg(feature = "diagnostics")]
            drop(live);
            Ok(())
        })
    }

    /// The shared-core log events captured since the last call, oldest
    /// first; always empty for a VM created without a log level.
    pub fn take_logs(&mut self) -> ExtensionResult<Array> {
        self.with(|vm| convert::log_records(vm.take_logs()))
    }

    pub fn response_head(&mut self) -> ExtensionResult<Array> {
        self.with(|vm| convert::response_head(&vm.response_head()))
    }

    pub fn notify_input(&mut self, bytes: Binary<u8>) -> ExtensionResult<()> {
        self.with(|vm| {
            vm.notify_input(convert::bytes(bytes));
            Ok(())
        })
    }

    pub fn notify_input_closed(&mut self) -> ExtensionResult<()> {
        self.with(|vm| {
            vm.notify_input_closed();
            Ok(())
        })
    }

    pub fn is_ready_to_execute(&mut self) -> ExtensionResult<bool> {
        self.with(|vm| Ok(vm.is_ready_to_execute()?))
    }

    pub fn is_replaying(&mut self) -> ExtensionResult<bool> {
        self.with(|vm| Ok(vm.is_replaying()))
    }

    pub fn input(&mut self) -> ExtensionResult<Array> {
        self.with(|vm| convert::input(&vm.input()?))
    }

    pub fn write_output_success(&mut self, bytes: Binary<u8>) -> ExtensionResult<()> {
        self.with(|vm| Ok(vm.write_output_success(convert::bytes(bytes))?))
    }

    pub fn write_output_failure(
        &mut self,
        code: i64,
        message: Binary<u8>,
        metadata: &ZendHashTable,
    ) -> ExtensionResult<()> {
        self.with(|vm| {
            let failure = convert::terminal_failure(code, message, metadata)?;
            Ok(vm.write_output_failure(failure)?)
        })
    }

    /// Reports a retryable failure with `code` (500 unless 400–599 and not
    /// 570); `retry_after_millis` asks Restate to wait that long before the
    /// next attempt.
    pub fn notify_error(
        &mut self,
        code: i64,
        message: Binary<u8>,
        stacktrace: Binary<u8>,
        retry_after_millis: Option<i64>,
    ) -> ExtensionResult<()> {
        self.with(|vm| {
            let retry_after = retry_after_millis
                .map(|millis| validation::non_negative_millis(millis, "Retry-after delay"))
                .transpose()?;
            vm.notify_error(code, Vec::from(message), Vec::from(stacktrace), retry_after);
            Ok(())
        })
    }

    /// Ends the attempt asking Restate to pause the invocation instead of
    /// retrying it.
    pub fn notify_pause(
        &mut self,
        message: Binary<u8>,
        stacktrace: Binary<u8>,
    ) -> ExtensionResult<()> {
        self.with(|vm| {
            vm.notify_pause(Vec::from(message), Vec::from(stacktrace));
            Ok(())
        })
    }

    pub fn end(&mut self) -> ExtensionResult<()> {
        self.with(|vm| Ok(vm.end()?))
    }

    /// The next chunk of the response body, or `null` once it is complete.
    pub fn take_output(&mut self) -> ExtensionResult<Option<Binary<u8>>> {
        self.with(|vm| Ok(vm.take_output().map(|chunk| convert::binary(&chunk))))
    }

    // --- Progress

    pub fn do_await(&mut self, handle: i64) -> ExtensionResult<Array> {
        self.with(|vm| {
            let handle = convert::handle(handle)?;
            convert::await_outcome(vm.do_await(handle)?)
        })
    }

    /// Awaits a combinator tree (`[combinator, [child, …]]`, see
    /// [`convert::await_tree`]). `any_completed` means the tree or a
    /// combinator in it resolved, and lists under `completed` the tree's
    /// handles that then are (what `is_completed` reports for each).
    pub fn do_await_tree(&mut self, tree: &ZendHashTable) -> ExtensionResult<Array> {
        self.with(|vm| {
            let tree = convert::await_tree(tree)?;
            convert::await_tree_outcome(vm.do_await_tree(tree)?)
        })
    }

    pub fn is_completed(&mut self, handle: i64) -> ExtensionResult<bool> {
        self.with(|vm| Ok(vm.is_completed(convert::handle(handle)?)))
    }

    pub fn take_notification_result(&mut self, handle: i64) -> ExtensionResult<Array> {
        self.with(|vm| {
            let handle = convert::handle(handle)?;
            convert::notification_result(vm.take_notification_result(handle)?)
        })
    }

    // --- Run

    pub fn sys_run(&mut self, name: String) -> ExtensionResult<i64> {
        self.with(|vm| {
            probe::trip(&name);
            Ok(i64::from(vm.sys_run(name)?))
        })
    }

    /// The success payload of a completed run.
    pub fn take_run_result(&mut self, handle: i64) -> ExtensionResult<Binary<u8>> {
        self.with(|vm| {
            let handle = convert::handle(handle)?;
            Ok(convert::binary(&vm.take_run_result(handle)?))
        })
    }

    pub fn propose_run_success(
        &mut self,
        handle: i64,
        bytes: Binary<u8>,
        retry_policy: Option<&ZendHashTable>,
    ) -> ExtensionResult<()> {
        self.with(|vm| {
            let handle = validation::run_completion_handle(handle)?;
            let retry_policy = convert::retry_policy(retry_policy)?;
            Ok(vm.propose_run_success(handle, convert::bytes(bytes), retry_policy)?)
        })
    }

    pub fn propose_run_failure(
        &mut self,
        handle: i64,
        code: i64,
        message: Binary<u8>,
        metadata: &ZendHashTable,
        retry_policy: Option<&ZendHashTable>,
    ) -> ExtensionResult<()> {
        self.with(|vm| {
            let failure = convert::terminal_failure(code, message, metadata)?;
            let handle = validation::run_completion_handle(handle)?;
            let retry_policy = convert::retry_policy(retry_policy)?;
            Ok(vm.propose_run_failure(handle, failure, retry_policy)?)
        })
    }

    /// Proposes a retryable failure; once the policy is exhausted it is the
    /// run's terminal failure with this code (500 unless 400–599 and not
    /// 570) and metadata.
    // One PHP method argument each, as for `propose_run_failure` plus the
    // attempt duration and retry-after.
    #[allow(clippy::too_many_arguments)]
    pub fn propose_run_retryable(
        &mut self,
        handle: i64,
        code: i64,
        message: Binary<u8>,
        metadata: &ZendHashTable,
        attempt_duration_millis: i64,
        retry_policy: Option<&ZendHashTable>,
        retry_after_millis: Option<i64>,
    ) -> ExtensionResult<Array> {
        self.with(|vm| {
            let failure = convert::retryable_failure(code, message, metadata)?;
            let handle = validation::run_completion_handle(handle)?;
            let attempt_duration = validation::non_negative_millis(
                attempt_duration_millis,
                "Run completion attempt duration",
            )?;
            let retry_policy = convert::retry_policy(retry_policy)?;
            let retry_after = retry_after_millis
                .map(|millis| {
                    validation::non_negative_millis(millis, "Run completion retry-after delay")
                })
                .transpose()?;
            convert::run_retry(vm.propose_run_retryable(
                handle,
                failure,
                attempt_duration,
                retry_after,
                retry_policy,
            )?)
        })
    }

    /// Ends the run's attempt, and the invocation's, asking Restate to pause
    /// the invocation instead of retrying. The run executes again once the
    /// invocation is resumed.
    pub fn propose_run_pause(
        &mut self,
        handle: i64,
        message: Binary<u8>,
        attempt_duration_millis: i64,
    ) -> ExtensionResult<()> {
        self.with(|vm| {
            let handle = validation::run_completion_handle(handle)?;
            let attempt_duration = validation::non_negative_millis(
                attempt_duration_millis,
                "Run completion attempt duration",
            )?;
            Ok(vm.propose_run_pause(handle, Vec::from(message), attempt_duration)?)
        })
    }

    // --- State

    /// Starts a state read; await the handle, then take its notification
    /// result (`success` or `void`).
    pub fn sys_get_state(&mut self, key: String) -> ExtensionResult<i64> {
        self.with(|vm| Ok(i64::from(vm.sys_get_state(key)?)))
    }

    /// Starts reading the state keys; the notification result is
    /// `state_keys`.
    pub fn sys_get_state_keys(&mut self) -> ExtensionResult<i64> {
        self.with(|vm| Ok(i64::from(vm.sys_get_state_keys()?)))
    }

    pub fn set_state(&mut self, key: String, bytes: Binary<u8>) -> ExtensionResult<()> {
        self.with(|vm| Ok(vm.set_state(key, convert::bytes(bytes))?))
    }

    pub fn clear_state(&mut self, key: String) -> ExtensionResult<()> {
        self.with(|vm| Ok(vm.clear_state(key)?))
    }

    pub fn clear_all_state(&mut self) -> ExtensionResult<()> {
        self.with(|vm| Ok(vm.clear_all_state()?))
    }

    // --- Promises

    /// Starts a promise get; the notification result is `success`, `void` or
    /// `failure`.
    pub fn sys_get_promise(&mut self, key: String) -> ExtensionResult<i64> {
        self.with(|vm| Ok(i64::from(vm.sys_get_promise(key)?)))
    }

    /// Starts a promise peek; the notification result is `void` while the
    /// promise is not completed.
    pub fn sys_peek_promise(&mut self, key: String) -> ExtensionResult<i64> {
        self.with(|vm| Ok(i64::from(vm.sys_peek_promise(key)?)))
    }

    /// Starts resolving a promise; the notification result is `void`, or a
    /// `failure` when the promise was already completed.
    pub fn sys_resolve_promise(&mut self, key: String, bytes: Binary<u8>) -> ExtensionResult<i64> {
        self.with(|vm| {
            Ok(i64::from(
                vm.sys_resolve_promise(key, convert::bytes(bytes))?,
            ))
        })
    }

    /// Starts rejecting a promise; completes like `sysResolvePromise()`.
    pub fn sys_reject_promise(
        &mut self,
        key: String,
        code: i64,
        message: Binary<u8>,
        metadata: &ZendHashTable,
    ) -> ExtensionResult<i64> {
        self.with(|vm| {
            let failure = convert::terminal_failure(code, message, metadata)?;
            Ok(i64::from(vm.sys_reject_promise(key, failure)?))
        })
    }

    // --- Communication

    pub fn sys_call(
        &mut self,
        target: &ZendHashTable,
        payload: Binary<u8>,
        idempotency_key: Option<String>,
    ) -> ExtensionResult<i64> {
        self.with(|vm| {
            let target = convert::call_target(target)?;
            Ok(i64::from(vm.sys_call(
                target,
                convert::bytes(payload),
                idempotency_key,
            )?))
        })
    }

    pub fn sys_send(
        &mut self,
        target: &ZendHashTable,
        payload: Binary<u8>,
        delay_millis: Option<i64>,
        idempotency_key: Option<String>,
    ) -> ExtensionResult<i64> {
        self.with(|vm| {
            let target = convert::call_target(target)?;
            let delay = delay_millis
                .map(|millis| validation::non_negative_millis(millis, "Send delay"))
                .transpose()?;
            Ok(i64::from(vm.sys_send(
                target,
                convert::bytes(payload),
                delay,
                idempotency_key,
            )?))
        })
    }

    pub fn sys_attach_invocation(&mut self, target: &ZendHashTable) -> ExtensionResult<i64> {
        self.with(|vm| {
            let target = convert::attach_target(target)?;
            Ok(i64::from(vm.sys_attach_invocation(target)?))
        })
    }

    pub fn sys_get_invocation_output(&mut self, target: &ZendHashTable) -> ExtensionResult<i64> {
        self.with(|vm| {
            let target = convert::attach_target(target)?;
            Ok(i64::from(vm.sys_get_invocation_output(target)?))
        })
    }

    pub fn sys_cancel_invocation(&mut self, invocation_id: String) -> ExtensionResult<()> {
        self.with(|vm| Ok(vm.sys_cancel_invocation(invocation_id)?))
    }

    // --- Timers and awakeables

    pub fn sys_sleep(&mut self, delay_millis: i64) -> ExtensionResult<i64> {
        self.with(|vm| {
            let delay = validation::non_negative_millis(delay_millis, "Sleep delay")?;
            Ok(i64::from(vm.sys_sleep(delay)?))
        })
    }

    pub fn sys_awakeable(&mut self) -> ExtensionResult<Array> {
        self.with(|vm| convert::awakeable(&vm.sys_awakeable()?))
    }

    pub fn complete_awakeable_success(
        &mut self,
        id: String,
        payload: Binary<u8>,
    ) -> ExtensionResult<()> {
        self.with(|vm| Ok(vm.complete_awakeable_success(id, convert::bytes(payload))?))
    }

    pub fn complete_awakeable_failure(
        &mut self,
        id: String,
        code: i64,
        message: Binary<u8>,
        metadata: &ZendHashTable,
    ) -> ExtensionResult<()> {
        self.with(|vm| {
            let failure = convert::terminal_failure(code, message, metadata)?;
            Ok(vm.complete_awakeable_failure(id, failure)?)
        })
    }

    // --- Named signals

    pub fn sys_signal(&mut self, name: String) -> ExtensionResult<i64> {
        self.with(|vm| Ok(i64::from(vm.sys_signal(name)?)))
    }

    pub fn complete_signal_success(
        &mut self,
        invocation_id: String,
        name: String,
        payload: Binary<u8>,
    ) -> ExtensionResult<()> {
        self.with(|vm| {
            Ok(vm.complete_signal_success(invocation_id, name, convert::bytes(payload))?)
        })
    }

    pub fn complete_signal_failure(
        &mut self,
        invocation_id: String,
        name: String,
        code: i64,
        message: Binary<u8>,
        metadata: &ZendHashTable,
    ) -> ExtensionResult<()> {
        self.with(|vm| {
            let failure = convert::terminal_failure(code, message, metadata)?;
            Ok(vm.complete_signal_failure(invocation_id, name, failure)?)
        })
    }
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    with_diagnostics(
        module
            .startup_function(exception::register)
            .class::<PhpIdentityVerifier>()
            .class::<PhpVm>()
            .function(wrap_function!(version))
            .function(wrap_function!(contract_version))
            .function(wrap_function!(protocol_versions)),
    )
}

#[cfg(feature = "diagnostics")]
fn with_diagnostics(module: ModuleBuilder) -> ModuleBuilder {
    diagnostics::register(module)
}

#[cfg(not(feature = "diagnostics"))]
fn with_diagnostics(module: ModuleBuilder) -> ModuleBuilder {
    module
}
