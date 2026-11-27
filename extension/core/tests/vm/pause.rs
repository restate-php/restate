//! Pausing the invocation instead of retrying it: a handler's pause request
//! (`notify_pause`), a run's pause request (`propose_run_pause`), and a run
//! retry policy that pauses once exhausted, on protocol v7 (what Restate 1.8
//! negotiates).

use crate::protocol::{
    propose_run_completion_message, ErrorBehavior, ErrorMessage, Message,
    ProposeRunCompletionMessage, RunCommandMessage,
};
use crate::support::*;
use restate_php_core::{Bytes, Handle, OnMaxAttempts, Progress, RetryPolicy, RunRetryOutcome, Vm};
use std::time::Duration;

fn executing_run(invocation: Invocation) -> (Vm, Handle) {
    let mut vm = invocation.running();
    let handle = vm.sys_run("charge".to_string()).unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ExecuteRun(handle));
    (vm, handle)
}

/// The run `charge`, journaled by an earlier attempt and failed `retries`
/// times since (what Restate's start message says about the entry).
fn retried_run(content_type: &'static str, retries: u32) -> Invocation {
    Invocation::new()
        .content_type(content_type)
        .start(|start| start.retry_count_since_last_stored_entry = retries)
        .entry(RunCommandMessage {
            result_completion_id: 1,
            name: "charge".to_string(),
        })
}

fn fixed_delay(max_attempts: u32, on_max_attempts: OnMaxAttempts) -> RetryPolicy {
    RetryPolicy::FixedDelay {
        interval: Some(Duration::from_millis(100)),
        max_attempts: Some(max_attempts),
        max_duration: None,
        on_max_attempts,
    }
}

#[test]
fn a_handler_pause_ends_the_attempt_asking_restate_to_pause() {
    let mut vm = Invocation::new().content_type(V7).running();

    vm.notify_pause(b"needs a fix".to_vec(), b"at handler".to_vec());

    let error: ErrorMessage = single(&output(&mut vm));
    assert_eq!(error.code, 500);
    assert_eq!(error.message, "needs a fix");
    assert_eq!(error.stacktrace, "at handler");
    assert_eq!(error.behavior, ErrorBehavior::Pause as i32);
    assert_eq!(error.next_retry_delay, None);
    assert_eq!(error.related_command_index, None);
    assert!(vm.take_output().is_none(), "the response is complete");
}

#[test]
fn a_run_pause_ends_the_attempt_with_the_run_as_related_command() {
    for retries in [0, 7] {
        let (mut vm, handle) = executing_run(retried_run(V7, retries));

        vm.propose_run_pause(
            handle,
            b"approval needed".to_vec(),
            Duration::from_millis(5),
        )
        .unwrap();

        let frames = output(&mut vm);
        assert_eq!(
            message_types(&frames),
            vec![ErrorMessage::TYPE],
            "{retries}"
        );
        let error: ErrorMessage = frames[0].decode();
        assert_eq!(error.code, 500);
        assert_eq!(error.message, "approval needed");
        assert_eq!(error.behavior, ErrorBehavior::Pause as i32);
        assert_eq!(error.next_retry_delay, None);
        assert_eq!(error.related_command_index, Some(1));
        assert_eq!(error.related_command_name.as_deref(), Some("charge"));
        assert_eq!(
            error.related_command_type,
            Some(u32::from(RunCommandMessage::TYPE))
        );
        assert!(vm.take_output().is_none(), "the response is complete");
    }
}

/// A run's pause request on a new run: the run command, then the pause.
#[test]
fn a_run_pause_of_a_new_run_journals_the_run_first() {
    let (mut vm, handle) = executing_run(Invocation::new().content_type(V7));

    vm.propose_run_pause(handle, b"approval needed".to_vec(), Duration::ZERO)
        .unwrap();

    let frames = output(&mut vm);
    assert_eq!(
        message_types(&frames),
        vec![RunCommandMessage::TYPE, ErrorMessage::TYPE]
    );
    assert_eq!(
        frames[1].decode::<ErrorMessage>().behavior,
        ErrorBehavior::Pause as i32
    );
}

/// Proposes a retryable failure of `charge` under `policy` and returns the
/// outcome and the error message the attempt ended with.
fn propose_retryable(
    invocation: Invocation,
    policy: RetryPolicy,
) -> (RunRetryOutcome, ErrorMessage) {
    let (mut vm, handle) = executing_run(invocation);
    let outcome = vm
        .propose_run_retryable(
            handle,
            retryable(503, b"unavailable".to_vec(), &[]),
            Duration::from_millis(5),
            None,
            policy,
        )
        .unwrap();
    (outcome, single(&output(&mut vm)))
}

/// Under a policy that pauses once exhausted, the attempt ends either way:
/// with a retry while attempts are left, with a pause after the last one.
/// Both are reported as `AttemptEnded`, since the result shared-core returns
/// cannot tell them apart (`run::retryable_outcome`).
#[test]
fn a_pausing_policy_retries_while_attempts_are_left_and_pauses_once_exhausted() {
    let policy = fixed_delay(3, OnMaxAttempts::Pause);

    let (outcome, error) = propose_retryable(retried_run(V7, 1), policy.clone());
    assert_eq!(outcome, RunRetryOutcome::AttemptEnded);
    assert_eq!(error.behavior, ErrorBehavior::Retry as i32);
    assert_eq!(error.next_retry_delay, Some(100));

    let (outcome, error) = propose_retryable(retried_run(V7, 2), policy.clone());
    assert_eq!(outcome, RunRetryOutcome::AttemptEnded);
    assert_eq!(error.code, 503);
    assert_eq!(error.message, "unavailable");
    assert_eq!(error.behavior, ErrorBehavior::Pause as i32);
    assert_eq!(error.next_retry_delay, None);
    assert_eq!(error.related_command_name.as_deref(), Some("charge"));

    // A retry-after replaces the interval and keeps the pause.
    let (mut vm, handle) = executing_run(retried_run(V7, 2));
    let outcome = vm
        .propose_run_retryable(
            handle,
            retryable(503, b"unavailable".to_vec(), &[]),
            Duration::ZERO,
            Some(Duration::from_secs(2)),
            policy,
        )
        .unwrap();
    assert_eq!(outcome, RunRetryOutcome::AttemptEnded);
    assert_eq!(
        single::<ErrorMessage>(&output(&mut vm)).behavior,
        ErrorBehavior::Pause as i32
    );

    // The same exhausted policy without the pause completes the run.
    let (mut vm, handle) = executing_run(retried_run(V7, 2));
    let outcome = vm
        .propose_run_retryable(
            handle,
            retryable(503, b"unavailable".to_vec(), &[]),
            Duration::ZERO,
            None,
            fixed_delay(3, OnMaxAttempts::Fail),
        )
        .unwrap();
    assert_eq!(outcome, RunRetryOutcome::Completed);
}

#[test]
fn an_exponential_policy_pauses_once_its_duration_is_used_up() {
    let policy = RetryPolicy::Exponential {
        initial_interval: Duration::from_millis(50),
        factor: 2.0,
        max_interval: None,
        max_attempts: None,
        max_duration: Some(Duration::from_millis(1)),
        on_max_attempts: OnMaxAttempts::Pause,
    };

    let (outcome, error) = propose_retryable(retried_run(V7, 0), policy);

    assert_eq!(outcome, RunRetryOutcome::AttemptEnded);
    assert_eq!(error.behavior, ErrorBehavior::Pause as i32);
}

/// A success or terminal failure never uses the policy, so a pausing policy
/// does not stop the run from completing.
#[test]
fn a_pausing_policy_does_not_stop_a_run_from_completing() {
    let (mut vm, handle) = executing_run(Invocation::new().content_type(V7));
    vm.propose_run_success(
        handle,
        Bytes::from_static(b"42"),
        fixed_delay(3, OnMaxAttempts::Pause),
    )
    .unwrap();
    assert_eq!(
        single::<ProposeRunCompletionMessage>(&output(&mut vm)).result,
        Some(propose_run_completion_message::Result::Value(
            Bytes::from_static(b"42")
        ))
    );

    let (mut vm, handle) = executing_run(Invocation::new().content_type(V7));
    vm.propose_run_failure(
        handle,
        terminal_failure(402, "declined"),
        fixed_delay(3, OnMaxAttempts::Pause),
    )
    .unwrap();
    assert!(matches!(
        single::<ProposeRunCompletionMessage>(&output(&mut vm)).result,
        Some(propose_run_completion_message::Result::Failure(_))
    ));
}
