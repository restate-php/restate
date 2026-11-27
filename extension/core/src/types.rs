//! Plain values the core takes and returns. They mirror the shared-core types
//! the core needs, so the glue crates never depend on `restate-sdk-shared-core`
//! directly and a shared-core upgrade stays inside this crate.

use crate::error::{Error, Result};
use crate::validation;
use bytes::Bytes;
use restate_sdk_shared_core as shared;
use std::time::Duration;

/// A notification handle as the PHP side sees it.
pub type Handle = u32;

/// A terminal failure.
///
/// Failures the SDK writes are built with [`TerminalFailure::new`], which
/// enforces the status-code rule. Failures Restate delivers in notifications
/// are passed through as received.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct TerminalFailure {
    code: u16,
    message: String,
    metadata: Vec<(String, String)>,
}

impl TerminalFailure {
    /// `message` may be any bytes; see [`validation::lossy_text`].
    pub fn new(
        code: i64,
        message: impl Into<Vec<u8>>,
        metadata: Vec<(String, String)>,
    ) -> Result<Self> {
        Ok(Self {
            code: validation::terminal_failure_code(code)?,
            message: validation::lossy_text(message),
            metadata,
        })
    }

    pub fn code(&self) -> u16 {
        self.code
    }

    pub fn message(&self) -> &str {
        &self.message
    }

    pub fn metadata(&self) -> &[(String, String)] {
        &self.metadata
    }
}

impl From<shared::TerminalFailure> for TerminalFailure {
    fn from(failure: shared::TerminalFailure) -> Self {
        Self {
            code: failure.code,
            message: failure.message,
            metadata: failure.metadata,
        }
    }
}

impl From<TerminalFailure> for shared::TerminalFailure {
    fn from(failure: TerminalFailure) -> Self {
        Self {
            code: failure.code,
            message: failure.message,
            metadata: failure.metadata,
        }
    }
}

/// A retryable failure of a run. When the retry policy is exhausted it
/// becomes the run's terminal failure, code and metadata included.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RetryableFailure {
    code: u16,
    message: String,
    metadata: Vec<(String, String)>,
}

impl RetryableFailure {
    /// `code` goes through [`validation::retryable_failure_code`] (anything
    /// that cannot be a failure code becomes 500); `message` may be any bytes,
    /// see [`validation::lossy_text`].
    pub fn new(code: i64, message: impl Into<Vec<u8>>, metadata: Vec<(String, String)>) -> Self {
        Self {
            code: validation::retryable_failure_code(code),
            message: validation::lossy_text(message),
            metadata,
        }
    }

    pub fn code(&self) -> u16 {
        self.code
    }

    pub fn message(&self) -> &str {
        &self.message
    }

    pub fn metadata(&self) -> &[(String, String)] {
        &self.metadata
    }
}

impl From<RetryableFailure> for shared::Error {
    fn from(failure: RetryableFailure) -> Self {
        let error = Self::new(failure.code, failure.message);
        if failure.metadata.is_empty() {
            error
        } else {
            error.with_metadata(failure.metadata)
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum TargetKind {
    Service,
    Object,
    Workflow,
}

impl TargetKind {
    /// Parses the kind as the PHP side names it: `service`, `object` or
    /// `workflow`.
    pub fn from_name(name: &str) -> Result<Self> {
        match name {
            "service" => Ok(TargetKind::Service),
            "object" => Ok(TargetKind::Object),
            "workflow" => Ok(TargetKind::Workflow),
            kind => Err(Error::invalid_argument(format!(
                "unsupported target kind: {kind}"
            ))),
        }
    }
}

/// The target of a call or a one-way send.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CallTarget {
    pub kind: TargetKind,
    pub service: String,
    pub handler: String,
    pub key: Option<String>,
    pub headers: Vec<(String, String)>,
    /// The scope the target is addressed in: part of its identity (object and
    /// workflow instances, idempotency keys), and what Restate's concurrency
    /// rules match.
    pub scope: Option<String>,
    /// The concurrency limit key within the scope; not part of the identity.
    /// Needs a scope.
    pub limit_key: Option<String>,
}

impl CallTarget {
    /// Validates the key against the kind (services take none, objects and
    /// workflows need one), and the scope and limit key
    /// ([`validation::scope_and_limit_key`]).
    pub(crate) fn into_shared(self, idempotency_key: Option<String>) -> Result<shared::Target> {
        let key = match (self.kind, self.key) {
            (TargetKind::Service, None) => None,
            (TargetKind::Service, Some(_)) => {
                return Err(Error::invalid_argument(
                    "service target must not include a key",
                ))
            }
            (TargetKind::Object | TargetKind::Workflow, Some(key)) => Some(key),
            (TargetKind::Object | TargetKind::Workflow, None) => {
                return Err(Error::invalid_argument("target key is required"))
            }
        };

        let (scope, limit_key) = validation::scope_and_limit_key(self.scope, self.limit_key)?;

        Ok(shared::Target {
            service: self.service,
            handler: self.handler,
            key,
            idempotency_key: validation::idempotency_key(idempotency_key),
            scope,
            limit_key,
            headers: self
                .headers
                .into_iter()
                .map(|(key, value)| shared::Header {
                    key: key.into(),
                    value: value.into(),
                })
                .collect(),
        })
    }
}

/// The invocation an attach or get-output operation refers to. A workflow run
/// and an idempotent invocation are identified within their scope, if any.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum AttachTarget {
    Invocation {
        invocation_id: String,
    },
    Workflow {
        name: String,
        key: String,
        scope: Option<String>,
    },
    Idempotency {
        service_name: String,
        service_key: Option<String>,
        handler_name: String,
        idempotency_key: String,
        scope: Option<String>,
    },
}

impl AttachTarget {
    /// The scope the target is identified in.
    pub fn scope(&self) -> Option<&str> {
        match self {
            AttachTarget::Invocation { .. } => None,
            AttachTarget::Workflow { scope, .. } | AttachTarget::Idempotency { scope, .. } => {
                scope.as_deref()
            }
        }
    }
}

impl From<AttachTarget> for shared::AttachInvocationTarget {
    fn from(target: AttachTarget) -> Self {
        match target {
            AttachTarget::Invocation { invocation_id } => Self::InvocationId(invocation_id),
            AttachTarget::Workflow { name, key, scope } => Self::WorkflowId { name, key, scope },
            AttachTarget::Idempotency {
                service_name,
                service_key,
                handler_name,
                idempotency_key,
                scope,
            } => Self::IdempotencyId {
                service_name,
                service_key,
                handler_name,
                idempotency_key,
                scope,
            },
        }
    }
}

/// What a bounded run retry policy does once its attempts or duration are
/// used up.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub enum OnMaxAttempts {
    /// The last failure becomes the run's terminal result.
    #[default]
    Fail,
    /// The attempt ends and Restate pauses the invocation until someone
    /// resumes it.
    Pause,
}

/// Retry policy for a run.
#[derive(Debug, Clone, Default, PartialEq)]
pub enum RetryPolicy {
    #[default]
    Infinite,
    None,
    FixedDelay {
        interval: Option<Duration>,
        max_attempts: Option<u32>,
        max_duration: Option<Duration>,
        on_max_attempts: OnMaxAttempts,
    },
    Exponential {
        initial_interval: Duration,
        factor: f32,
        max_interval: Option<Duration>,
        max_attempts: Option<u32>,
        max_duration: Option<Duration>,
        on_max_attempts: OnMaxAttempts,
    },
}

impl RetryPolicy {
    /// This policy with every retry `delay` apart, and the same limits.
    ///
    /// **Emulates retry-after** (`docs/decisions/0014-shared-core.md`,
    /// "Retry-after on `ctx.run`"). shared-core (as of 8.7.2) replaces the delay
    /// override of a retryable run failure with the interval its retry policy
    /// computes (`None` for `Infinite`). Its `next_retry` reads only the
    /// policy and the retry count and duration, so proposing one failure under
    /// a fixed-delay policy with the same bounds asks Restate for `delay` and
    /// still gives up at the same attempt. `None` never retries and stays as
    /// it is; `Infinite` has no bounds.
    pub fn with_retry_after(self, delay: Duration) -> Self {
        let (max_attempts, max_duration, on_max_attempts) = match self {
            Self::None => return Self::None,
            Self::Infinite => (None, None, OnMaxAttempts::Fail),
            Self::FixedDelay {
                max_attempts,
                max_duration,
                on_max_attempts,
                ..
            }
            | Self::Exponential {
                max_attempts,
                max_duration,
                on_max_attempts,
                ..
            } => (max_attempts, max_duration, on_max_attempts),
        };
        Self::FixedDelay {
            interval: Some(delay),
            max_attempts,
            max_duration,
            on_max_attempts,
        }
    }

    /// The policy that pauses the invocation at the first failure: what a
    /// pause request from a run proposes (see [`crate::Vm::propose_run_pause`]).
    /// No attempt is allowed, so shared-core's `next_retry` always answers
    /// "pause".
    pub(crate) fn pause_now() -> Self {
        Self::FixedDelay {
            interval: None,
            max_attempts: Some(0),
            max_duration: None,
            on_max_attempts: OnMaxAttempts::Pause,
        }
    }
}

impl From<OnMaxAttempts> for shared::OnMaxAttempts {
    fn from(on_max_attempts: OnMaxAttempts) -> Self {
        match on_max_attempts {
            OnMaxAttempts::Fail => Self::FailAsTerminal,
            OnMaxAttempts::Pause => Self::Pause,
        }
    }
}

impl From<RetryPolicy> for shared::RetryPolicy {
    fn from(policy: RetryPolicy) -> Self {
        match policy {
            RetryPolicy::Infinite => Self::Infinite,
            RetryPolicy::None => Self::None,
            RetryPolicy::FixedDelay {
                interval,
                max_attempts,
                max_duration,
                on_max_attempts,
            } => Self::FixedDelay {
                interval,
                max_attempts,
                max_duration,
                on_max_attempts: on_max_attempts.into(),
            },
            RetryPolicy::Exponential {
                initial_interval,
                factor,
                max_interval,
                max_attempts,
                max_duration,
                on_max_attempts,
            } => Self::Exponential {
                initial_interval,
                factor,
                max_interval,
                max_attempts,
                max_duration,
                on_max_attempts: on_max_attempts.into(),
            },
        }
    }
}

/// Status and headers of the HTTP response.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ResponseHead {
    pub status_code: u16,
    pub headers: Vec<(String, String)>,
}

/// The invocation's input, from `sys_input`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct InvocationInput {
    pub invocation_id: String,
    pub random_seed: u64,
    pub key: String,
    pub headers: Vec<(String, String)>,
    pub input: Bytes,
    /// The scope the invocation was called in. Protocol v7 and later; always
    /// `None` before.
    pub scope: Option<String>,
    /// The limit key the invocation was called with. Protocol v7 and later.
    pub limit_key: Option<String>,
    /// The idempotency key the invocation was called with. Protocol v7 and
    /// later.
    pub idempotency_key: Option<String>,
}

/// The range of service protocol versions discovery advertises, inclusive.
/// The maximum is shared-core's; the minimum is v7 (Restate 1.8), the oldest
/// version the SDK supports, even though [`crate::Vm::new`] accepts every
/// version shared-core speaks: Restate 1.7 (v6 by default) then cannot invoke
/// the deployment (1.7.14 registers it and fails every invocation with
/// RT0013).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct ProtocolVersions {
    pub min: u32,
    pub max: u32,
}

pub fn protocol_versions() -> ProtocolVersions {
    let minimum = shared::Version::minimum_supported_version().max(shared::Version::V7);
    ProtocolVersions {
        min: minimum as u32,
        max: shared::Version::maximum_supported_version() as u32,
    }
}

/// A newly created awakeable.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Awakeable {
    pub id: String,
    pub handle: Handle,
}

pub(crate) fn header_pairs(headers: Vec<shared::Header>) -> Vec<(String, String)> {
    headers
        .into_iter()
        .map(|shared::Header { key, value }| (key.into_owned(), value.into_owned()))
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::error::ErrorCategory;

    fn target(kind: TargetKind, key: Option<&str>) -> CallTarget {
        CallTarget {
            kind,
            service: "Svc".to_string(),
            handler: "run".to_string(),
            key: key.map(str::to_string),
            headers: vec![("x-trace-id".to_string(), "trace-1".to_string())],
            scope: None,
            limit_key: None,
        }
    }

    fn shared_on_max_attempts(policy: &RetryPolicy) -> Option<shared::OnMaxAttempts> {
        match shared::RetryPolicy::from(policy.clone()) {
            shared::RetryPolicy::Infinite | shared::RetryPolicy::None => None,
            shared::RetryPolicy::FixedDelay {
                on_max_attempts, ..
            }
            | shared::RetryPolicy::Exponential {
                on_max_attempts, ..
            } => Some(on_max_attempts),
        }
    }

    /// A bounded policy pauses once exhausted only when asked to, also after
    /// a retry-after replaced its interval. (`run::retryable_outcome` cannot
    /// tell such a pause from a scheduled retry; see `RunRetryOutcome`.)
    #[test]
    fn a_policy_pauses_once_exhausted_only_when_asked() {
        let bounded = Some(3);
        for on_max_attempts in [OnMaxAttempts::Fail, OnMaxAttempts::Pause] {
            let expected = Some(shared::OnMaxAttempts::from(on_max_attempts));
            for policy in [
                RetryPolicy::FixedDelay {
                    interval: Some(Duration::from_millis(100)),
                    max_attempts: bounded,
                    max_duration: None,
                    on_max_attempts,
                },
                RetryPolicy::Exponential {
                    initial_interval: Duration::from_millis(50),
                    factor: 2.0,
                    max_interval: None,
                    max_attempts: bounded,
                    max_duration: Some(Duration::from_secs(1)),
                    on_max_attempts,
                },
            ] {
                let retry_after = policy.clone().with_retry_after(Duration::from_secs(2));
                assert_eq!(shared_on_max_attempts(&policy), expected, "{policy:?}");
                assert_eq!(
                    shared_on_max_attempts(&retry_after),
                    expected,
                    "{retry_after:?}"
                );
            }
        }

        // Unbounded and no-retry policies never pause.
        let infinite = RetryPolicy::Infinite.with_retry_after(Duration::from_secs(2));
        assert_eq!(
            shared_on_max_attempts(&infinite),
            Some(shared::OnMaxAttempts::FailAsTerminal)
        );
        assert_eq!(
            RetryPolicy::None.with_retry_after(Duration::from_secs(2)),
            RetryPolicy::None
        );

        assert_eq!(
            shared_on_max_attempts(&RetryPolicy::pause_now()),
            Some(shared::OnMaxAttempts::Pause)
        );
    }
    #[test]
    fn terminal_failure_new_enforces_the_code_rule() {
        let failure = TerminalFailure::new(
            409,
            "denied",
            vec![("key1".to_string(), "value1".to_string())],
        )
        .unwrap();

        assert_eq!(failure.code(), 409);
        assert_eq!(failure.message(), "denied");
        assert_eq!(
            failure.metadata(),
            [("key1".to_string(), "value1".to_string())]
        );
        assert_eq!(
            TerminalFailure::new(200, "ok?", vec![])
                .unwrap_err()
                .category(),
            ErrorCategory::InvalidArgument
        );
    }

    #[test]
    fn terminal_failure_message_bytes_that_are_not_utf8_are_substituted() {
        let failure = TerminalFailure::new(402, b"Carte refus\xe9e".to_vec(), vec![]).unwrap();

        assert_eq!(failure.message(), "Carte refus\u{fffd}e");
    }

    #[test]
    fn received_failures_keep_any_code() {
        let failure = TerminalFailure::from(shared::TerminalFailure {
            code: 302,
            message: "moved".to_string(),
            metadata: vec![],
        });

        assert_eq!(failure.code(), 302);
    }

    #[test]
    fn target_kinds_parse_from_their_names() {
        assert_eq!(
            TargetKind::from_name("service").unwrap(),
            TargetKind::Service
        );
        assert_eq!(TargetKind::from_name("object").unwrap(), TargetKind::Object);
        assert_eq!(
            TargetKind::from_name("workflow").unwrap(),
            TargetKind::Workflow
        );

        let error = TargetKind::from_name("actor").unwrap_err();
        assert_eq!(error.category(), ErrorCategory::InvalidArgument);
        assert_eq!(error.message(), "unsupported target kind: actor");
    }

    #[test]
    fn service_target_takes_no_key() {
        let shared = target(TargetKind::Service, None).into_shared(None).unwrap();

        assert_eq!(shared.service, "Svc");
        assert_eq!(shared.handler, "run");
        assert_eq!(shared.key, None);
        assert_eq!(shared.idempotency_key, None);
        assert_eq!(shared.headers.len(), 1);
        assert_eq!(shared.headers[0].key.as_ref(), "x-trace-id");
        assert_eq!(shared.headers[0].value.as_ref(), "trace-1");

        assert_eq!(
            target(TargetKind::Service, Some("k"))
                .into_shared(None)
                .unwrap_err()
                .message(),
            "service target must not include a key"
        );
    }

    #[test]
    fn keyed_targets_need_a_key() {
        for kind in [TargetKind::Object, TargetKind::Workflow] {
            let shared = target(kind, Some("alice"))
                .into_shared(Some("idem-1".to_string()))
                .unwrap();
            assert_eq!(shared.key.as_deref(), Some("alice"));
            assert_eq!(shared.idempotency_key.as_deref(), Some("idem-1"));

            assert_eq!(
                target(kind, None).into_shared(None).unwrap_err().message(),
                "target key is required"
            );
        }
    }

    #[test]
    fn empty_idempotency_key_is_dropped() {
        let shared = target(TargetKind::Service, None)
            .into_shared(Some(String::new()))
            .unwrap();

        assert_eq!(shared.idempotency_key, None);
    }

    #[test]
    fn scope_and_limit_key_are_passed_on() {
        let shared = CallTarget {
            scope: Some("tenant-1".to_string()),
            limit_key: Some("user/42".to_string()),
            ..target(TargetKind::Object, Some("alice"))
        }
        .into_shared(None)
        .unwrap();

        assert_eq!(shared.scope.as_deref(), Some("tenant-1"));
        assert_eq!(shared.limit_key.as_deref(), Some("user/42"));
        assert_eq!(shared.key.as_deref(), Some("alice"));
    }

    #[test]
    fn a_limit_key_needs_a_scope() {
        let error = CallTarget {
            limit_key: Some("user".to_string()),
            ..target(TargetKind::Service, None)
        }
        .into_shared(None)
        .unwrap_err();

        assert_eq!(error.category(), ErrorCategory::InvalidArgument);
        assert_eq!(error.message(), "A limit key needs a scope.");
    }

    #[test]
    fn attach_targets_convert() {
        assert_eq!(
            shared::AttachInvocationTarget::from(AttachTarget::Workflow {
                name: "Checkout".to_string(),
                key: "order-1".to_string(),
                scope: None,
            }),
            shared::AttachInvocationTarget::WorkflowId {
                name: "Checkout".to_string(),
                key: "order-1".to_string(),
                scope: None,
            }
        );
        let scoped = AttachTarget::Idempotency {
            service_name: "Greeter".to_string(),
            service_key: None,
            handler_name: "greet".to_string(),
            idempotency_key: "once".to_string(),
            scope: Some("tenant-1".to_string()),
        };
        assert_eq!(scoped.scope(), Some("tenant-1"));
        assert_eq!(
            shared::AttachInvocationTarget::from(scoped),
            shared::AttachInvocationTarget::IdempotencyId {
                service_name: "Greeter".to_string(),
                service_key: None,
                handler_name: "greet".to_string(),
                idempotency_key: "once".to_string(),
                scope: Some("tenant-1".to_string()),
            }
        );
    }
}
