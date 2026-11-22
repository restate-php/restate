//! The Restate PHP SDK's Rust core: the single implementation of every
//! operation the PHP side performs on a `restate-sdk-shared-core` VM.
//!
//! It is plain Rust with no PHP dependency, so `cargo test -p
//! restate-php-core` runs without PHP. The extension crate is glue: it
//! converts PHP arguments with [`validation`], calls [`Vm`], and converts the
//! typed result back into PHP values. Only this crate depends on
//! `restate-sdk-shared-core`.
//!
//! - [`Vm`] owns one invocation's VM and exposes every `sys_*` operation.
//! - Operations that produce a result return a [`Handle`] and never drive the
//!   VM themselves; awaiting a handle, or an [`AwaitTree`] of combinators
//!   over handles, returns a [`Progress`]: completion, or what the VM has to
//!   report first (suspension, cancellation, a run to execute, …). Suspension
//!   and cancellation are outcomes there, never errors, and the handle stays
//!   valid whatever the outcome.
//! - Completed notifications decode into [`NotificationResult`] and run
//!   results.
//! - Every failure is an [`Error`] with an [`ErrorCategory`]; shared-core
//!   errors are classified by their code.
//! - [`guard`] contains Rust panics: a panic becomes an internal [`Error`],
//!   and a [`guard::Poisonable`] value refuses every operation after one
//!   panicked.
//! - [`LogLevel`]: a VM created with one captures shared-core's `tracing`
//!   events at or above it, for the caller to drain ([`Vm::take_logs`]).
//! - [`IdentityVerifier`]: shared-core's request identity verifier, built once
//!   per endpoint from its identity keys.

mod await_tree;
mod error;
pub mod guard;
mod identity;
mod logs;
mod notification;
mod progress;
mod run;
mod types;
pub mod validation;
mod vm;

pub use await_tree::AwaitTree;
pub use bytes::Bytes;
pub use error::{Error, ErrorCategory, Result};
pub use identity::IdentityVerifier;
pub use logs::{LogLevel, LogRecord, MAX_BUFFERED_RECORDS};
pub use notification::NotificationResult;
pub use progress::Progress;
pub use run::RunRetryOutcome;
pub use types::{
    protocol_versions, AttachTarget, Awakeable, CallTarget, Handle, InvocationInput, OnMaxAttempts,
    ProtocolVersions, ResponseHead, RetryPolicy, RetryableFailure, TargetKind, TerminalFailure,
};
pub use vm::Vm;
