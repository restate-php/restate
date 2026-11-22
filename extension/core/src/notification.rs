//! Decoding the value a completed notification carries, per operation. Each
//! operation accepts only the values shared-core can produce for it; anything
//! else is an internal error naming the operation.

use crate::error::{Error, Result};
use crate::types::TerminalFailure;
use bytes::Bytes;
use restate_sdk_shared_core::Value;

/// The result of a completed notification: a call, attach, get-output,
/// sleep, awakeable or send (invocation id), a state get (success, or void
/// when the key is not set) or state keys, or a promise get, peek (void: not
/// completed yet) or completion (void, or a failure when the promise was
/// already completed).
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum NotificationResult {
    Success(Bytes),
    Void,
    Failure(TerminalFailure),
    InvocationId(String),
    StateKeys(Vec<String>),
}

fn unsupported(operation: &str, value: &Value) -> Error {
    Error::internal(format!(
        "unsupported notification value for {operation}: {value:?}"
    ))
}

fn not_completed(subject: &str) -> Error {
    Error::internal(format!("{subject} notification is not completed"))
}

/// A run's stored result. Only a success payload is accepted.
pub(crate) fn run_result(value: Option<Value>) -> Result<Bytes> {
    match value {
        Some(Value::Success(bytes)) => Ok(bytes),
        Some(Value::Void) => Err(Error::internal(
            "run completion returned void; success payload bytes are required",
        )),
        Some(Value::Failure(failure)) => Err(Error::internal(format!(
            "run completion failed with code {}: {}",
            failure.code, failure.message
        ))),
        Some(value) => Err(unsupported("run completion", &value)),
        None => Err(not_completed("run")),
    }
}

pub(crate) fn notification_result(value: Option<Value>) -> Result<NotificationResult> {
    match value {
        Some(Value::Success(bytes)) => Ok(NotificationResult::Success(bytes)),
        Some(Value::Void) => Ok(NotificationResult::Void),
        Some(Value::Failure(failure)) => Ok(NotificationResult::Failure(failure.into())),
        Some(Value::InvocationId(id)) => Ok(NotificationResult::InvocationId(id)),
        Some(Value::StateKeys(keys)) => Ok(NotificationResult::StateKeys(keys)),
        None => Err(not_completed("operation")),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::error::ErrorCategory;
    use restate_sdk_shared_core::TerminalFailure as SharedFailure;

    fn failure() -> Value {
        Value::Failure(SharedFailure {
            code: 404,
            message: "missing".to_string(),
            metadata: vec![("404".to_string(), "x".to_string())],
        })
    }

    fn received_failure() -> TerminalFailure {
        TerminalFailure::new(404, "missing", vec![("404".to_string(), "x".to_string())]).unwrap()
    }

    #[test]
    fn run_result_accepts_only_success() {
        assert_eq!(
            run_result(Some(Value::Success(Bytes::from_static(b"1")))).unwrap(),
            Bytes::from_static(b"1")
        );
        assert_eq!(
            run_result(Some(Value::Void)).unwrap_err().message(),
            "run completion returned void; success payload bytes are required"
        );
        assert_eq!(
            run_result(Some(failure())).unwrap_err().message(),
            "run completion failed with code 404: missing"
        );
        assert_eq!(
            run_result(Some(Value::StateKeys(vec![])))
                .unwrap_err()
                .message(),
            "unsupported notification value for run completion: StateKeys([])"
        );
        let error = run_result(None).unwrap_err();
        assert_eq!(error.message(), "run notification is not completed");
        assert_eq!(error.category(), ErrorCategory::Internal);
    }

    #[test]
    fn notification_result_accepts_every_value() {
        assert_eq!(
            notification_result(Some(Value::Success(Bytes::from_static(b"\"hi\"")))).unwrap(),
            NotificationResult::Success(Bytes::from_static(b"\"hi\""))
        );
        assert_eq!(
            notification_result(Some(Value::Void)).unwrap(),
            NotificationResult::Void
        );
        assert_eq!(
            notification_result(Some(failure())).unwrap(),
            NotificationResult::Failure(received_failure())
        );
        assert_eq!(
            notification_result(Some(Value::InvocationId("inv-1".to_string()))).unwrap(),
            NotificationResult::InvocationId("inv-1".to_string())
        );
        assert_eq!(
            notification_result(Some(Value::StateKeys(vec!["a".to_string()]))).unwrap(),
            NotificationResult::StateKeys(vec!["a".to_string()])
        );
        assert_eq!(
            notification_result(None).unwrap_err().message(),
            "operation notification is not completed"
        );
    }
}
