//! [`Vm`]: one invocation's state machine and every operation on it.

use crate::await_tree::AwaitTree;
use crate::error::{Error, Result};
use crate::logs::{LogCapture, LogLevel, LogRecord};
use crate::notification::{self, NotificationResult};
use crate::progress::{self, Progress};
use crate::run::{self, RunRetryOutcome};
use crate::types::{
    header_pairs, AttachTarget, Awakeable, CallTarget, Handle, InvocationInput, ResponseHead,
    RetryPolicy, RetryableFailure, TerminalFailure,
};
use crate::validation;
use bytes::Bytes;
use restate_sdk_shared_core::{
    AwakeableHandle, CoreVM, NonEmptyValue, NotificationHandle, PayloadOptions, RunExitResult,
    UnresolvedFuture, VMOptions, Value, VM,
};
use std::collections::{BTreeSet, HashMap};
use std::time::{Duration, SystemTime, UNIX_EPOCH};

/// One invocation's `restate-sdk-shared-core` VM.
///
/// An operation that produces a result returns a [`Handle`] and never waits:
/// the caller drives the VM with [`Vm::do_await`] (or [`Vm::do_await_tree`]),
/// which returns a [`Progress`] — suspension and cancellation are outcomes
/// there, not errors — and takes the result with
/// [`Vm::take_notification_result`] (or [`Vm::take_run_result`]) once the
/// handle completed. Nothing here drives the VM on its own, so a handle
/// survives any progress state.
pub struct Vm {
    core: CoreVM,
    /// Keys this attempt cleared and has not set since. See
    /// [`Vm::sys_get_state_keys`].
    cleared_state: BTreeSet<String>,
    /// For each state keys read not taken yet, the keys cleared when it
    /// started.
    state_keys_reads: HashMap<Handle, BTreeSet<String>>,
    /// Captures shared-core's log events while [`Vm::logged`] runs; none
    /// when the VM was created without a log level.
    logs: Option<LogCapture>,
}

fn now() -> Result<Duration> {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_err(|e| Error::internal(e.to_string()))
}

impl Vm {
    /// Creates the VM for a request with these headers. Fails (with the
    /// shared-core code, e.g. 415) when the content type is missing or names
    /// an unsupported protocol version.
    pub fn new(headers: Vec<(String, String)>) -> Result<Self> {
        Self::with_log_level(headers, None)
    }

    /// [`Vm::new`], capturing shared-core's log events at or above
    /// `log_level` (none: capture nothing). Events are captured while the VM
    /// is created and while an operation runs inside [`Vm::logged`]; take
    /// them with [`Vm::take_logs`].
    pub fn with_log_level(
        headers: Vec<(String, String)>,
        log_level: Option<LogLevel>,
    ) -> Result<Self> {
        let logs = log_level.map(LogCapture::new);
        let core = match &logs {
            Some(logs) => logs.scope(|| CoreVM::new(headers, VMOptions::default()))?,
            None => CoreVM::new(headers, VMOptions::default())?,
        };
        Ok(Self {
            core,
            cleared_state: BTreeSet::new(),
            state_keys_reads: HashMap::new(),
            logs,
        })
    }

    /// Runs `operation` on this VM, capturing the log events shared-core
    /// emits meanwhile on this thread (when the VM captures any). The
    /// thread's previous capture, if any, is back once it returns or panics.
    pub fn logged<T>(&mut self, operation: impl FnOnce(&mut Self) -> T) -> T {
        let _active = self.logs.as_ref().map(LogCapture::enter);
        operation(self)
    }

    /// The log events captured since the last call, oldest first; always
    /// empty for a VM created without a log level.
    pub fn take_logs(&mut self) -> Vec<LogRecord> {
        self.logs.as_ref().map(LogCapture::take).unwrap_or_default()
    }

    pub fn response_head(&self) -> ResponseHead {
        let head = self.core.get_response_head();
        ResponseHead {
            status_code: head.status_code,
            headers: header_pairs(head.headers),
        }
    }

    pub fn notify_input(&mut self, bytes: Bytes) {
        self.core.notify_input(bytes);
    }

    pub fn notify_input_closed(&mut self) {
        self.core.notify_input_closed();
    }

    pub fn is_ready_to_execute(&self) -> Result<bool> {
        Ok(self.core.is_ready_to_execute()?)
    }

    pub fn is_replaying(&self) -> bool {
        self.core.state().is_replaying()
    }

    pub fn input(&mut self) -> Result<InvocationInput> {
        let input = self.core.sys_input()?;
        Ok(InvocationInput {
            invocation_id: input.invocation_id,
            random_seed: input.random_seed,
            key: input.key,
            headers: header_pairs(input.headers),
            input: input.input,
            scope: input.scope,
            limit_key: input.limit_key,
            idempotency_key: input.idempotency_key,
        })
    }

    pub fn write_output_success(&mut self, bytes: Bytes) -> Result<()> {
        Ok(self
            .core
            .sys_write_output(NonEmptyValue::Success(bytes), PayloadOptions::default())?)
    }

    pub fn write_output_failure(&mut self, failure: TerminalFailure) -> Result<()> {
        Ok(self.core.sys_write_output(
            NonEmptyValue::Failure(failure.into()),
            PayloadOptions::default(),
        )?)
    }

    /// Reports a retryable failure of the attempt with status `code`, which
    /// goes through [`validation::retryable_failure_code`] (anything that
    /// cannot be a failure code, and 570, becomes 500). `retry_after` asks
    /// Restate to wait that long before the next attempt instead of following
    /// its invocation retry policy (shared-core writes it to the error message
    /// as given). The protocol's error message has no metadata, so a
    /// handler's retryable failure carries none. Message and stack trace may
    /// be any bytes; see [`validation::lossy_text`].
    pub fn notify_error(
        &mut self,
        code: i64,
        message: impl Into<Vec<u8>>,
        stacktrace: impl Into<Vec<u8>>,
        retry_after: Option<Duration>,
    ) {
        let mut error = restate_sdk_shared_core::Error::new(
            validation::retryable_failure_code(code),
            validation::lossy_text(message),
        )
        .with_stacktrace(validation::lossy_text(stacktrace));
        if let Some(delay) = retry_after {
            error = error.with_next_retry_delay_override(delay);
        }
        self.core.notify_error(error, None);
    }

    /// Ends the attempt asking Restate to pause the invocation instead of
    /// retrying it; it stays paused until someone resumes it. Message and
    /// stack trace may be any bytes; see [`validation::lossy_text`].
    pub fn notify_pause(&mut self, message: impl Into<Vec<u8>>, stacktrace: impl Into<Vec<u8>>) {
        let error = restate_sdk_shared_core::Error::internal(validation::lossy_text(message))
            .with_stacktrace(validation::lossy_text(stacktrace))
            .with_should_pause(true);
        self.core.notify_error(error, None);
    }

    pub fn end(&mut self) -> Result<()> {
        Ok(self.core.sys_end()?)
    }

    /// The next chunk of response body (empty when nothing is buffered yet),
    /// or `None` once the response is complete.
    ///
    /// shared-core returns whatever is buffered and has no end-of-output
    /// marker; its state becomes `Closed` exactly where the output closes
    /// (end, suspension, error), so drained and closed means complete.
    pub fn take_output(&mut self) -> Option<Bytes> {
        let bytes = self.core.take_output();
        if bytes.is_empty() && self.core.state().is_closed() {
            None
        } else {
            Some(bytes)
        }
    }

    // --- Progress

    pub fn do_await(&mut self, handle: Handle) -> Result<Progress<()>> {
        progress::from_response(
            self.core
                .do_await(UnresolvedFuture::Single(NotificationHandle::from(handle))),
        )
    }

    /// Awaits a combinator tree. Completion means the tree or a combinator
    /// nested in it resolved, not necessarily the whole tree (see
    /// [`AwaitTree`]), and carries the tree's handles that are then completed:
    /// what [`Vm::is_completed`] reports for each of them right after the
    /// await, in the tree's depth-first order (a handle as often as it
    /// occurs). One call answers what a combinator would otherwise ask once
    /// per handle.
    pub fn do_await_tree(&mut self, tree: AwaitTree) -> Result<Progress<Vec<Handle>>> {
        tree.validate()?;
        let handles = tree.handles();
        let progress = progress::from_response(self.core.do_await(UnresolvedFuture::from(tree)))?;
        Ok(progress.map(|()| {
            handles
                .into_iter()
                .filter(|&handle| self.is_completed(handle))
                .collect()
        }))
    }

    /// Whether an await has seen this handle's result and it has not been
    /// taken yet. A result that arrived is not completed until an await
    /// processed it; a taken or unknown handle is not completed.
    pub fn is_completed(&self, handle: Handle) -> bool {
        self.core.is_completed(NotificationHandle::from(handle))
    }

    fn take(&mut self, handle: Handle) -> Result<Option<Value>> {
        Ok(self.core.take_notification(handle.into())?)
    }

    /// The result of any completed operation except a run: call, send, attach,
    /// get-output, sleep, awakeable, signal, state get or keys, promise get,
    /// peek or completion.
    pub fn take_notification_result(&mut self, handle: Handle) -> Result<NotificationResult> {
        let result = notification::notification_result(self.take(handle)?)?;
        Ok(match (self.state_keys_reads.remove(&handle), result) {
            (Some(cleared), NotificationResult::StateKeys(keys)) => NotificationResult::StateKeys(
                keys.into_iter()
                    .filter(|key| !cleared.contains(key))
                    .collect(),
            ),
            (_, result) => result,
        })
    }

    // --- Run

    pub fn sys_run(&mut self, name: String) -> Result<Handle> {
        Ok(self.core.sys_run(name)?.handle.into())
    }

    /// The success payload of a completed run.
    pub fn take_run_result(&mut self, handle: Handle) -> Result<Bytes> {
        notification::run_result(self.take(handle)?)
    }

    pub fn propose_run_success(
        &mut self,
        handle: Handle,
        bytes: Bytes,
        retry_policy: RetryPolicy,
    ) -> Result<()> {
        Ok(self.core.propose_run_completion(
            handle.into(),
            RunExitResult::Success(bytes),
            retry_policy.into(),
        )?)
    }

    pub fn propose_run_failure(
        &mut self,
        handle: Handle,
        failure: TerminalFailure,
        retry_policy: RetryPolicy,
    ) -> Result<()> {
        Ok(self.core.propose_run_completion(
            handle.into(),
            RunExitResult::TerminalFailure(failure.into()),
            retry_policy.into(),
        )?)
    }

    /// Proposes a retryable failure. `retry_after` replaces the policy's
    /// delay before the next attempt (see [`RetryPolicy::with_retry_after`]);
    /// the policy's limits still apply. Once the policy is exhausted the
    /// failure, with its code and metadata, is the run's terminal result
    /// (metadata needs protocol v6). A policy with
    /// [`crate::OnMaxAttempts::Pause`] pauses the invocation instead; see
    /// [`RunRetryOutcome::AttemptEnded`].
    pub fn propose_run_retryable(
        &mut self,
        handle: Handle,
        failure: RetryableFailure,
        attempt_duration: Duration,
        retry_after: Option<Duration>,
        retry_policy: RetryPolicy,
    ) -> Result<RunRetryOutcome> {
        let (code, message) = (failure.code(), failure.message().to_owned());
        let error = restate_sdk_shared_core::Error::from(failure);
        let retry_policy = match retry_after {
            Some(delay) => retry_policy.with_retry_after(delay),
            None => retry_policy,
        };

        let result = self.core.propose_run_completion(
            handle.into(),
            RunExitResult::RetryableFailure {
                attempt_duration,
                error,
            },
            retry_policy.into(),
        );
        run::retryable_outcome(result, code, &message)
    }

    /// Ends the run's attempt, and with it the invocation's attempt, asking
    /// Restate to pause the invocation instead of retrying. The run stays
    /// unfinished: once the invocation is resumed it executes again. Shared
    /// core reports the failure with the run as the related command, like a
    /// retryable failure, under [`RetryPolicy::pause_now`]. `message` may be
    /// any bytes; see [`validation::lossy_text`].
    pub fn propose_run_pause(
        &mut self,
        handle: Handle,
        message: impl Into<Vec<u8>>,
        attempt_duration: Duration,
    ) -> Result<()> {
        let failure = RetryableFailure::new(500, message, vec![]);
        let (code, message) = (failure.code(), failure.message().to_owned());

        let result = self.core.propose_run_completion(
            handle.into(),
            RunExitResult::RetryableFailure {
                attempt_duration,
                error: restate_sdk_shared_core::Error::from(failure),
            },
            RetryPolicy::pause_now().into(),
        );
        run::pause_outcome(result, code, &message)
    }

    // --- State

    /// Starts a state read; the handle completes with the value
    /// ([`NotificationResult::Success`]) or [`NotificationResult::Void`] when
    /// the key is not set. With eager state it is completed at once.
    pub fn sys_get_state(&mut self, key: String) -> Result<Handle> {
        Ok(self
            .core
            .sys_state_get(key, None, PayloadOptions::default())?
            .into())
    }

    /// Starts reading the state keys; the handle completes with
    /// [`NotificationResult::StateKeys`].
    ///
    /// **Works around shared-core** (as of 8.7.2; `docs/decisions/0014-shared-core.md`):
    /// with eager state it records a cleared key as "cleared" rather than
    /// removing it, and still lists it among the keys (`EagerState::get_keys`
    /// in `src/vm/context.rs`). The core therefore
    /// drops the keys this attempt cleared, and did not set again, before the
    /// read started. Replay re-runs the same clears, so a replayed read
    /// returns what the original attempt returned. With lazy state Restate
    /// answers after applying the clears, so nothing is dropped.
    pub fn sys_get_state_keys(&mut self) -> Result<Handle> {
        let handle: Handle = self.core.sys_state_get_keys(None)?.into();
        if !self.cleared_state.is_empty() {
            self.state_keys_reads
                .insert(handle, self.cleared_state.clone());
        }
        Ok(handle)
    }

    pub fn set_state(&mut self, key: String, value: Bytes) -> Result<()> {
        self.core
            .sys_state_set(key.clone(), value, None, PayloadOptions::default())?;
        self.cleared_state.remove(&key);
        Ok(())
    }

    pub fn clear_state(&mut self, key: String) -> Result<()> {
        self.core.sys_state_clear(key.clone(), None)?;
        self.cleared_state.insert(key);
        Ok(())
    }

    pub fn clear_all_state(&mut self) -> Result<()> {
        self.core.sys_state_clear_all(None)?;
        // No key is left, and shared-core lists none after this.
        self.cleared_state.clear();
        Ok(())
    }

    // --- Promises

    /// Starts a promise get; the handle completes with the promise's value or
    /// failure once it is completed.
    pub fn sys_get_promise(&mut self, key: String) -> Result<Handle> {
        Ok(self.core.sys_get_promise(key)?.into())
    }

    /// Starts a promise peek; the handle completes with the promise's value or
    /// failure, or [`NotificationResult::Void`] when it is not completed yet.
    pub fn sys_peek_promise(&mut self, key: String) -> Result<Handle> {
        Ok(self.core.sys_peek_promise(key)?.into())
    }

    /// Starts resolving a promise; the handle completes with
    /// [`NotificationResult::Void`], or a failure when the promise was
    /// already completed.
    pub fn sys_resolve_promise(&mut self, key: String, value: Bytes) -> Result<Handle> {
        self.complete_promise(key, NonEmptyValue::Success(value))
    }

    /// Starts rejecting a promise; completes like [`Vm::sys_resolve_promise`].
    pub fn sys_reject_promise(&mut self, key: String, failure: TerminalFailure) -> Result<Handle> {
        self.complete_promise(key, NonEmptyValue::Failure(failure.into()))
    }

    fn complete_promise(&mut self, key: String, value: NonEmptyValue) -> Result<Handle> {
        Ok(self
            .core
            .sys_complete_promise(key, value, PayloadOptions::default())?
            .into())
    }

    // --- Communication

    /// Calls a handler; the handle completes with the call's result.
    pub fn sys_call(
        &mut self,
        target: CallTarget,
        payload: Bytes,
        idempotency_key: Option<String>,
    ) -> Result<Handle> {
        let target = target.into_shared(idempotency_key)?;
        let handle = self
            .core
            .sys_call(target, payload, None, PayloadOptions::default())?;
        Ok(handle.call_notification_handle.into())
    }

    /// Sends a one-way invocation, after `delay` when given; the handle
    /// completes with the new invocation's id.
    pub fn sys_send(
        &mut self,
        target: CallTarget,
        payload: Bytes,
        delay: Option<Duration>,
        idempotency_key: Option<String>,
    ) -> Result<Handle> {
        let target = target.into_shared(idempotency_key)?;
        let execution_time = match delay {
            Some(delay) => Some(now()? + delay),
            None => None,
        };
        let handle = self.core.sys_send(
            target,
            payload,
            execution_time,
            None,
            PayloadOptions::default(),
        )?;
        Ok(handle.invocation_id_notification_handle.into())
    }

    /// Attaches to an invocation; the handle completes with its result.
    pub fn sys_attach_invocation(&mut self, target: AttachTarget) -> Result<Handle> {
        validation::scope_value(target.scope().map(str::to_owned))?;
        Ok(self.core.sys_attach_invocation(target.into())?.into())
    }

    /// Reads an invocation's output without waiting; completes like
    /// [`Vm::sys_attach_invocation`], or with [`NotificationResult::Void`]
    /// while it runs.
    pub fn sys_get_invocation_output(&mut self, target: AttachTarget) -> Result<Handle> {
        validation::scope_value(target.scope().map(str::to_owned))?;
        Ok(self.core.sys_get_invocation_output(target.into())?.into())
    }

    pub fn sys_cancel_invocation(&mut self, invocation_id: String) -> Result<()> {
        Ok(self.core.sys_cancel_invocation(invocation_id)?)
    }

    // --- Timers and awakeables

    pub fn sys_sleep(&mut self, delay: Duration) -> Result<Handle> {
        let now = now()?;
        Ok(self
            .core
            .sys_sleep(String::default(), now + delay, Some(now))?
            .into())
    }

    pub fn sys_awakeable(&mut self) -> Result<Awakeable> {
        let AwakeableHandle { id, handle } = self.core.sys_awakeable()?;
        Ok(Awakeable {
            id,
            handle: handle.into(),
        })
    }

    pub fn complete_awakeable_success(&mut self, id: String, payload: Bytes) -> Result<()> {
        Ok(self.core.sys_complete_awakeable(
            id,
            NonEmptyValue::Success(payload),
            PayloadOptions::stable_serialization(),
        )?)
    }

    pub fn complete_awakeable_failure(
        &mut self,
        id: String,
        failure: TerminalFailure,
    ) -> Result<()> {
        Ok(self.core.sys_complete_awakeable(
            id,
            NonEmptyValue::Failure(failure.into()),
            PayloadOptions::stable_serialization(),
        )?)
    }

    // --- Named signals

    /// Waits for the next delivery of the signal `name` to this invocation.
    /// Journals nothing: the handle completes when a delivery arrives, before
    /// or after this call. Each call takes one delivery, in the order they
    /// arrived (shared-core's default `SignalDelivery::Queued`).
    pub fn sys_signal(&mut self, name: String) -> Result<Handle> {
        let name = validation::signal_name(name)?;
        Ok(self.core.create_signal_handle(name)?.into())
    }

    /// Resolves the signal `name` of the invocation `invocation_id`.
    pub fn complete_signal_success(
        &mut self,
        invocation_id: String,
        name: String,
        payload: Bytes,
    ) -> Result<()> {
        let name = validation::signal_name(name)?;
        Ok(self
            .core
            .sys_complete_signal(invocation_id, name, NonEmptyValue::Success(payload))?)
    }

    /// Rejects the signal `name` of the invocation `invocation_id`.
    pub fn complete_signal_failure(
        &mut self,
        invocation_id: String,
        name: String,
        failure: TerminalFailure,
    ) -> Result<()> {
        let name = validation::signal_name(name)?;
        Ok(self.core.sys_complete_signal(
            invocation_id,
            name,
            NonEmptyValue::Failure(failure.into()),
        )?)
    }
}
