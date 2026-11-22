//! Run completion: what proposing a retryable failure (or a pause) did.

use crate::error::{Error, Result};
use restate_sdk_shared_core::VMResult;

/// The outcome of proposing a retryable run failure.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum RunRetryOutcome {
    /// The run did not complete and the current attempt must stop:
    /// shared-core either scheduled another attempt, or — only under a policy
    /// with [`crate::OnMaxAttempts::Pause`] — found the policy exhausted and
    /// asked Restate to pause the invocation. The two cannot be told apart
    /// (see [`retryable_outcome`]); for the caller they are the same: the
    /// attempt ends without a result and Restate decides what comes next.
    AttemptEnded,
    /// The retry policy is exhausted and the failure was recorded as the
    /// run's terminal result.
    Completed,
}

/// Interprets the result of `propose_run_completion` for a retryable failure
/// submitted with `submitted_code` and `submitted_message`.
///
/// **Heuristic** (`docs/decisions/0014-shared-core.md`, "Run completion heuristic", and
/// `docs/decisions/0021-pause.md`). shared-core (as of 8.7.2) has no
/// structured result for run completion: `propose_run_completion` returns
/// `VMResult<()>`, and when the retry policy schedules another attempt it
/// returns the submitted error itself (`Err(error)` from the
/// `ProposeRunCompletion` transition, after setting its `next_retry_delay`
/// and `related_command`). Those fields are `pub(crate)` and the public API
/// (`code()`, `message()`, `description()` — the stack trace — and
/// `metadata()`) cannot tell that error from any other, so an ended attempt
/// is recognised by the submitted code and message coming back. When the
/// policy is exhausted the failure is recorded as the run's terminal result
/// (with its code, message and metadata) and the call returns `Ok(())`. The
/// submitted code is never 570 (see
/// [`crate::validation::retryable_failure_code`]), the one code shared-core
/// rewrites on the way out.
///
/// 8.x has a third outcome, `NextRetry::Pause` (a policy with
/// `OnMaxAttempts::Pause`, protocol v7): it also returns the submitted error,
/// with its behaviour set to pause (also `pub(crate)`). So under a pausing
/// policy an ended attempt is either a scheduled retry or a pause, and this
/// reports [`RunRetryOutcome::AttemptEnded`] for both rather than claiming
/// one. Nothing the SDK does depends on which it was: in both cases the
/// attempt ends without a run result, the error message the VM wrote tells
/// Restate what to do, and the PHP side ends the attempt as after a
/// suspension. A pause the SDK asks for directly ([`pause_outcome`]) is
/// known for what it is.
///
/// This is the only place the comparison is made; the tests here and in
/// `tests/vm/run.rs`, `tests/vm/pause.rs` and `tests/vm/v7.rs` pin its
/// behaviour. It goes away once shared-core reports the outcome itself, for
/// example `propose_run_completion` returning whether it completed the run,
/// scheduled a retry or paused, instead of `VMResult<()>`.
pub(crate) fn retryable_outcome(
    result: VMResult<()>,
    submitted_code: u16,
    submitted_message: &str,
) -> Result<RunRetryOutcome> {
    match result {
        Ok(()) => Ok(RunRetryOutcome::Completed),
        Err(error) if is_submitted(&error, submitted_code, submitted_message) => {
            Ok(RunRetryOutcome::AttemptEnded)
        }
        Err(error) => Err(Error::from(error)),
    }
}

/// Interprets the result of `propose_run_completion` for a pause request: a
/// retryable failure submitted with `submitted_code` and `submitted_message`
/// under [`crate::RetryPolicy::pause_now`], which leaves shared-core no
/// outcome but `NextRetry::Pause`. The submitted error coming back (the same
/// comparison as [`retryable_outcome`]) means the VM wrote the pause and the
/// attempt must stop; any other error, such as 573 below protocol v7, is
/// returned.
pub(crate) fn pause_outcome(
    result: VMResult<()>,
    submitted_code: u16,
    submitted_message: &str,
) -> Result<()> {
    match result {
        Err(error) if is_submitted(&error, submitted_code, submitted_message) => Ok(()),
        Err(error) => Err(Error::from(error)),
        Ok(()) => Err(Error::internal(
            "shared-core recorded a run result for a pause request",
        )),
    }
}

fn is_submitted(
    error: &restate_sdk_shared_core::Error,
    submitted_code: u16,
    submitted_message: &str,
) -> bool {
    error.code() == submitted_code && error.message() == submitted_message
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::error::ErrorCategory;
    use restate_sdk_shared_core::Error as VmError;

    #[test]
    fn accepted_proposal_completes_the_run() {
        assert_eq!(
            retryable_outcome(Ok(()), 500, "boom").unwrap(),
            RunRetryOutcome::Completed
        );
    }

    #[test]
    fn the_submitted_error_coming_back_means_the_attempt_ended() {
        assert_eq!(
            retryable_outcome(Err(VmError::new(500u16, "boom")), 500, "boom").unwrap(),
            RunRetryOutcome::AttemptEnded
        );
        assert_eq!(
            retryable_outcome(Err(VmError::new(503u16, "boom")), 503, "boom").unwrap(),
            RunRetryOutcome::AttemptEnded
        );
    }

    #[test]
    fn any_other_error_is_returned() {
        let other_message =
            retryable_outcome(Err(VmError::new(500u16, "other")), 500, "boom").unwrap_err();
        assert_eq!(other_message.message(), "(500) other");

        let other_code =
            retryable_outcome(Err(VmError::new(598u16, "boom")), 500, "boom").unwrap_err();
        assert_eq!(other_code.category(), ErrorCategory::Closed);

        let default_code =
            retryable_outcome(Err(VmError::new(500u16, "boom")), 503, "boom").unwrap_err();
        assert_eq!(default_code.message(), "(500) boom");
    }

    #[test]
    fn a_pause_request_ends_the_attempt_when_the_submitted_error_comes_back() {
        pause_outcome(Err(VmError::new(500u16, "boom")), 500, "boom").unwrap();

        let unsupported =
            pause_outcome(Err(VmError::new(573u16, "no pause")), 500, "boom").unwrap_err();
        assert_eq!(unsupported.category(), ErrorCategory::Protocol);
        assert_eq!(unsupported.code(), Some(573));

        let recorded = pause_outcome(Ok(()), 500, "boom").unwrap_err();
        assert_eq!(recorded.category(), ErrorCategory::Internal);
    }
}
