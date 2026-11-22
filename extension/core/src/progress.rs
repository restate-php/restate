//! Driving the VM until a handle completes: the typed result of
//! `do_await`, and how shared-core's responses and errors map onto it.

use crate::error::{Error, ErrorCategory, Result};
use crate::types::Handle;
use restate_sdk_shared_core::{AwaitResponse, VMResult};

/// What happened when the VM was asked to make progress on one or more
/// handles: the operation completed with a `T`, or the VM has to report
/// something else first.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Progress<T> {
    /// The awaited handle completed (for a tree: the tree or a combinator in
    /// it resolved).
    Completed(T),
    /// The invocation suspended; the attempt must stop.
    Suspended,
    /// The VM needs more input before it can continue.
    ReadFromInput,
    /// A run returned earlier by `ExecuteRun` has not been completed yet.
    WaitingPendingRun,
    /// The invocation received a cancel signal.
    CancelSignalReceived,
    /// The SDK must execute the run with this handle first.
    ExecuteRun(Handle),
}

impl<T> Progress<T> {
    pub fn map<U>(self, f: impl FnOnce(T) -> U) -> Progress<U> {
        match self {
            Progress::Completed(value) => Progress::Completed(f(value)),
            Progress::Suspended => Progress::Suspended,
            Progress::ReadFromInput => Progress::ReadFromInput,
            Progress::WaitingPendingRun => Progress::WaitingPendingRun,
            Progress::CancelSignalReceived => Progress::CancelSignalReceived,
            Progress::ExecuteRun(handle) => Progress::ExecuteRun(handle),
        }
    }
}

/// Maps the result of `do_await`. Suspension is an outcome, not an error;
/// every other shared-core error is returned classified.
///
/// `WaitingExternalProgress` maps onto the two states shared-core 0.10.0 had
/// before it merged them: waiting for input is [`Progress::ReadFromInput`],
/// and waiting only for a run proposal is [`Progress::WaitingPendingRun`]
/// (shared-core returns that only once the input is closed and a run is
/// executing). Waiting for neither cannot make progress, and
/// `EphemeralNotificationReady` follows only `ephemeral_state_get`, which the
/// core never calls: both are internal errors.
pub(crate) fn from_response(response: VMResult<AwaitResponse>) -> Result<Progress<()>> {
    match response {
        Ok(AwaitResponse::AnyCompleted) => Ok(Progress::Completed(())),
        Ok(AwaitResponse::ExecuteRun(handle)) => Ok(Progress::ExecuteRun(handle.into())),
        Ok(AwaitResponse::WaitingExternalProgress {
            waiting_input: true,
            ..
        }) => Ok(Progress::ReadFromInput),
        Ok(AwaitResponse::WaitingExternalProgress {
            waiting_input: false,
            waiting_run_proposal: true,
        }) => Ok(Progress::WaitingPendingRun),
        Ok(AwaitResponse::WaitingExternalProgress {
            waiting_input: false,
            waiting_run_proposal: false,
        }) => Err(Error::internal(
            "do_await is waiting for external progress, but neither for input nor for a run proposal",
        )),
        Ok(AwaitResponse::CancelSignalReceived) => Ok(Progress::CancelSignalReceived),
        Ok(AwaitResponse::EphemeralNotificationReady(_)) => Err(Error::internal(
            "do_await reported an ephemeral notification, but the core issues no ephemeral commands",
        )),
        Err(error) => {
            let error = Error::from(error);
            if error.category() == ErrorCategory::Suspended {
                Ok(Progress::Suspended)
            } else {
                Err(error)
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use restate_sdk_shared_core::Error as VmError;

    fn waiting(waiting_input: bool, waiting_run_proposal: bool) -> AwaitResponse {
        AwaitResponse::WaitingExternalProgress {
            waiting_input,
            waiting_run_proposal,
        }
    }

    #[test]
    fn responses_map_to_progress() {
        assert_eq!(
            from_response(Ok(AwaitResponse::AnyCompleted)).unwrap(),
            Progress::Completed(())
        );
        assert_eq!(
            from_response(Ok(AwaitResponse::ExecuteRun(7.into()))).unwrap(),
            Progress::ExecuteRun(7)
        );
        assert_eq!(
            from_response(Ok(AwaitResponse::CancelSignalReceived)).unwrap(),
            Progress::CancelSignalReceived
        );
    }

    #[test]
    fn waiting_for_input_reads_from_input_whether_or_not_a_run_is_pending() {
        assert_eq!(
            from_response(Ok(waiting(true, false))).unwrap(),
            Progress::ReadFromInput
        );
        assert_eq!(
            from_response(Ok(waiting(true, true))).unwrap(),
            Progress::ReadFromInput
        );
    }

    #[test]
    fn waiting_only_for_a_run_proposal_is_waiting_for_the_pending_run() {
        assert_eq!(
            from_response(Ok(waiting(false, true))).unwrap(),
            Progress::WaitingPendingRun
        );
    }

    #[test]
    fn waiting_for_neither_input_nor_a_run_proposal_is_an_internal_error() {
        let error = from_response(Ok(waiting(false, false))).unwrap_err();

        assert_eq!(error.category(), ErrorCategory::Internal);
        assert_eq!(error.code(), None);
    }

    #[test]
    fn an_ephemeral_notification_is_an_internal_error() {
        let error =
            from_response(Ok(AwaitResponse::EphemeralNotificationReady(3.into()))).unwrap_err();

        assert_eq!(error.category(), ErrorCategory::Internal);
        assert_eq!(error.code(), None);
    }

    #[test]
    fn suspension_is_an_outcome() {
        assert_eq!(
            from_response(Err(VmError::new(599u16, "Suspended invocation"))).unwrap(),
            Progress::Suspended
        );
    }

    #[test]
    fn other_errors_are_classified() {
        let error = from_response(Err(VmError::new(570u16, "mismatch"))).unwrap_err();

        assert_eq!(error.category(), ErrorCategory::JournalMismatch);
        assert_eq!(error.message(), "(570) mismatch");
    }

    #[test]
    fn map_carries_states_over() {
        assert_eq!(
            Progress::Completed(2).map(|v| v * 2),
            Progress::Completed(4)
        );
        assert_eq!(
            Progress::<i32>::Suspended.map(|v| v * 2),
            Progress::Suspended
        );
    }
}
