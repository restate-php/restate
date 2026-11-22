//! `ctx.run`: executing a run and proposing its result, replaying a stored
//! result, and retryable failures.

use crate::protocol::{
    propose_run_completion_message, run_completion_notification_message, Message,
    ProposeRunCompletionMessage, RunCommandMessage, RunCompletionNotificationMessage,
    SuspensionMessageV6,
};
use crate::support::*;
use restate_php_core::{
    Bytes, Handle, NotificationResult, OnMaxAttempts, Progress, RetryPolicy, RunRetryOutcome, Vm,
};
use std::time::Duration;

fn run_completion(completion_id: u32, value_content: &[u8]) -> Bytes {
    encode(&RunCompletionNotificationMessage {
        completion_id,
        result: Some(run_completion_notification_message::Result::Value(value(
            value_content,
        ))),
    })
}

#[test]
fn a_run_is_executed_proposed_and_acknowledged() {
    let mut vm = Invocation::new().open().running();

    let handle = vm.sys_run("charge".to_string()).unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ExecuteRun(handle));
    vm.propose_run_success(handle, Bytes::from_static(b"42"), RetryPolicy::Infinite)
        .unwrap();

    let frames = output(&mut vm);
    assert_eq!(
        message_types(&frames),
        vec![RunCommandMessage::TYPE, ProposeRunCompletionMessage::TYPE]
    );
    let command: RunCommandMessage = frames[0].decode();
    assert_eq!(command.name, "charge");
    let proposal: ProposeRunCompletionMessage = frames[1].decode();
    assert_eq!(proposal.result_completion_id, command.result_completion_id);
    assert_eq!(
        proposal.result,
        Some(propose_run_completion_message::Result::Value(
            Bytes::from_static(b"42")
        ))
    );

    // Restate acknowledges the proposal by sending the stored result.
    let result = completed_after(
        &mut vm,
        handle,
        run_completion(command.result_completion_id, b"42"),
    );
    assert_eq!(
        result,
        NotificationResult::Success(Bytes::from_static(b"42"))
    );
}

#[test]
fn a_proposed_run_suspends_when_the_request_body_is_closed() {
    // Request/response: the acknowledgement arrives with the next attempt.
    let mut vm = Invocation::new().running();

    let handle = vm.sys_run("charge".to_string()).unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ExecuteRun(handle));
    vm.propose_run_success(handle, Bytes::from_static(b"42"), RetryPolicy::Infinite)
        .unwrap();

    assert_eq!(vm.do_await(handle).unwrap(), Progress::Suspended);
    let frames = output(&mut vm);
    assert_eq!(
        message_types(&frames),
        vec![
            RunCommandMessage::TYPE,
            ProposeRunCompletionMessage::TYPE,
            SuspensionMessageV6::TYPE
        ]
    );
    let command: RunCommandMessage = frames[0].decode();
    assert_eq!(
        frames[2]
            .decode::<SuspensionMessageV6>()
            .waiting_completions,
        vec![command.result_completion_id]
    );
}

#[test]
fn a_terminal_failure_is_proposed_and_stored_as_the_run_result() {
    let mut vm = Invocation::new().open().running();

    let handle = vm.sys_run("charge".to_string()).unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ExecuteRun(handle));
    vm.propose_run_failure(
        handle,
        terminal_failure(402, "card declined"),
        RetryPolicy::Infinite,
    )
    .unwrap();

    let frames = output(&mut vm);
    let command: RunCommandMessage = single(&frames);
    assert_eq!(
        single::<ProposeRunCompletionMessage>(&frames).result,
        Some(propose_run_completion_message::Result::Failure(failure(
            402,
            "card declined",
            &[]
        )))
    );

    let result = completed_after(
        &mut vm,
        handle,
        encode(&RunCompletionNotificationMessage {
            completion_id: command.result_completion_id,
            result: Some(run_completion_notification_message::Result::Failure(
                failure(402, "card declined", &[]),
            )),
        }),
    );
    assert_eq!(
        result,
        NotificationResult::Failure(terminal_failure(402, "card declined"))
    );
}

#[test]
fn a_replayed_run_completes_with_its_stored_result_without_executing() {
    let mut vm = Invocation::new()
        .entry(RunCommandMessage {
            result_completion_id: 1,
            name: "charge".to_string(),
        })
        .entry(RunCompletionNotificationMessage {
            completion_id: 1,
            result: Some(run_completion_notification_message::Result::Value(value(
                b"42",
            ))),
        })
        .running();
    assert!(vm.is_replaying());

    let handle = vm.sys_run("charge".to_string()).unwrap();

    assert_eq!(vm.do_await(handle).unwrap(), Progress::Completed(()));
    assert_eq!(
        vm.take_run_result(handle).unwrap(),
        Bytes::from_static(b"42")
    );
    assert!(output(&mut vm).is_empty(), "nothing new to journal");
}

#[test]
fn a_replayed_run_failure_completes_with_the_failure() {
    let mut vm = Invocation::new()
        .entry(RunCommandMessage {
            result_completion_id: 1,
            name: "charge".to_string(),
        })
        .entry(RunCompletionNotificationMessage {
            completion_id: 1,
            result: Some(run_completion_notification_message::Result::Failure(
                failure(402, "card declined", &[("reason", "funds")]),
            )),
        })
        .running();

    let handle = vm.sys_run("charge".to_string()).unwrap();

    assert_eq!(
        completed(&mut vm, handle),
        NotificationResult::Failure(
            restate_php_core::TerminalFailure::new(
                402,
                "card declined",
                vec![("reason".to_string(), "funds".to_string())]
            )
            .unwrap()
        )
    );
}

#[test]
fn awaiting_a_run_again_before_proposing_its_result_waits_for_it() {
    let mut vm = Invocation::new().running();

    let handle = vm.sys_run("charge".to_string()).unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ExecuteRun(handle));

    assert_eq!(vm.do_await(handle).unwrap(), Progress::WaitingPendingRun);
}

#[test]
fn a_retryable_failure_schedules_a_retry_under_an_infinite_policy() {
    let mut vm = Invocation::new().running();
    let handle = vm.sys_run("charge".to_string()).unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ExecuteRun(handle));

    let outcome = vm
        .propose_run_retryable(
            handle,
            retryable(500, b"timeout \xe9".to_vec(), &[]),
            Duration::from_millis(5),
            None,
            RetryPolicy::Infinite,
        )
        .unwrap();

    assert_eq!(outcome, RunRetryOutcome::AttemptEnded);
    // The attempt ends with the (substituted) error for Restate to retry.
    let error: crate::protocol::ErrorMessage = single(&output(&mut vm));
    assert_eq!(error.message, "timeout \u{fffd}");
}

/// The `run::retryable_outcome` heuristic against a real VM: an exhausted
/// policy records the failure and reports `Completed`, and the run's handle
/// then completes with that failure.
#[test]
fn a_retryable_failure_completes_the_run_once_the_policy_is_exhausted() {
    let mut vm = Invocation::new().running();
    let handle = vm.sys_run("charge".to_string()).unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ExecuteRun(handle));

    let outcome = vm
        .propose_run_retryable(
            handle,
            retryable(500, b"timeout".to_vec(), &[]),
            Duration::from_millis(5),
            None,
            RetryPolicy::None,
        )
        .unwrap();

    assert_eq!(outcome, RunRetryOutcome::Completed);
    let frames = output(&mut vm);
    assert_eq!(
        message_types(&frames).last(),
        Some(&ProposeRunCompletionMessage::TYPE)
    );
    match single::<ProposeRunCompletionMessage>(&frames).result {
        Some(propose_run_completion_message::Result::Failure(failure)) => {
            assert_eq!(failure.message, "timeout");
        }
        other => panic!("expected a failure proposal, got {other:?}"),
    }
}

/// A bounded policy with attempts left schedules a retry, also when the
/// proposal overrides the delay.
#[test]
fn a_retryable_failure_schedules_a_retry_while_a_bounded_policy_has_attempts_left() {
    let mut vm = Invocation::new().running();
    let handle = vm.sys_run("charge".to_string()).unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ExecuteRun(handle));

    let outcome = vm
        .propose_run_retryable(
            handle,
            retryable(500, b"first failure".to_vec(), &[]),
            Duration::ZERO,
            Some(Duration::from_millis(10)),
            RetryPolicy::FixedDelay {
                interval: None,
                max_attempts: Some(5),
                max_duration: None,
                on_max_attempts: OnMaxAttempts::Fail,
            },
        )
        .unwrap();

    assert_eq!(outcome, RunRetryOutcome::AttemptEnded);
    let error: crate::protocol::ErrorMessage = single(&output(&mut vm));
    assert_eq!(error.next_retry_delay, Some(10));
}

/// A run being executed: `invocation`, the handler running, the run `charge`
/// awaited and due for execution.
fn executing_run(invocation: Invocation) -> (Vm, Handle) {
    let mut vm = invocation.running();
    let handle = vm.sys_run("charge".to_string()).unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ExecuteRun(handle));
    (vm, handle)
}

/// A run that an earlier attempt journaled and that failed `retries` times
/// since, `elapsed_millis` ago (what Restate's start message says about the
/// entry being retried).
fn retried_run(retries: u32, elapsed_millis: u64) -> Invocation {
    Invocation::new()
        .start(|start| {
            start.retry_count_since_last_stored_entry = retries;
            start.duration_since_last_stored_entry = elapsed_millis;
        })
        .entry(RunCommandMessage {
            result_completion_id: 1,
            name: "charge".to_string(),
        })
}

/// Proposes a retryable failure of `charge` and returns the outcome and the
/// delay the attempt asked Restate for (`None` when it completed the run or
/// left the delay to Restate).
fn propose_retryable(
    invocation: Invocation,
    retry_after: Option<Duration>,
    policy: RetryPolicy,
) -> (RunRetryOutcome, Option<u64>) {
    let (mut vm, handle) = executing_run(invocation);
    let outcome = vm
        .propose_run_retryable(
            handle,
            retryable(500, b"rate limited".to_vec(), &[]),
            Duration::from_millis(5),
            retry_after,
            policy,
        )
        .unwrap();
    let frames = output(&mut vm);
    let delay = match outcome {
        RunRetryOutcome::AttemptEnded => {
            single::<crate::protocol::ErrorMessage>(&frames).next_retry_delay
        }
        RunRetryOutcome::Completed => {
            assert!(matches!(
                single::<ProposeRunCompletionMessage>(&frames).result,
                Some(propose_run_completion_message::Result::Failure(_))
            ));
            None
        }
    };
    (outcome, delay)
}

fn fixed_delay(interval_millis: u64, max_attempts: Option<u32>) -> RetryPolicy {
    RetryPolicy::FixedDelay {
        interval: Some(Duration::from_millis(interval_millis)),
        max_attempts,
        max_duration: None,
        on_max_attempts: OnMaxAttempts::Fail,
    }
}

fn exponential(max_attempts: Option<u32>, max_duration: Option<Duration>) -> RetryPolicy {
    RetryPolicy::Exponential {
        initial_interval: Duration::from_millis(50),
        factor: 2.0,
        max_interval: None,
        max_attempts,
        max_duration,
        on_max_attempts: OnMaxAttempts::Fail,
    }
}

/// Without a retry-after, the policy's interval is the delay (the exponential
/// one grows with the retry count Restate reports).
#[test]
fn a_retryable_failure_asks_for_the_policy_interval() {
    let fixed = propose_retryable(Invocation::new(), None, fixed_delay(100, None));
    assert_eq!(fixed, (RunRetryOutcome::AttemptEnded, Some(100)));

    let backoff = propose_retryable(retried_run(2, 300), None, exponential(None, None));
    assert_eq!(backoff, (RunRetryOutcome::AttemptEnded, Some(200)));

    let infinite = propose_retryable(Invocation::new(), None, RetryPolicy::Infinite);
    assert_eq!(infinite, (RunRetryOutcome::AttemptEnded, None));
}

/// shared-core (8.6.0 to 8.7.2) replaces a delay override with the policy's interval;
/// the core substitutes the policy for that one proposal instead, so the
/// retry-after is the delay whatever the policy's interval.
#[test]
fn a_retryable_failure_asks_for_the_retry_after_instead_of_the_policy_interval() {
    let retry_after = Some(Duration::from_millis(2_000));

    for policy in [
        fixed_delay(100, None),
        fixed_delay(100, Some(5)),
        exponential(None, None),
        exponential(Some(5), Some(Duration::from_secs(60))),
        RetryPolicy::Infinite,
        RetryPolicy::FixedDelay {
            interval: None,
            max_attempts: None,
            max_duration: None,
            on_max_attempts: OnMaxAttempts::Fail,
        },
    ] {
        assert_eq!(
            propose_retryable(Invocation::new(), retry_after, policy.clone()),
            (RunRetryOutcome::AttemptEnded, Some(2_000)),
            "{policy:?}"
        );
        assert_eq!(
            propose_retryable(retried_run(3, 400), retry_after, policy.clone()),
            (RunRetryOutcome::AttemptEnded, Some(2_000)),
            "{policy:?} on a later attempt"
        );
    }
}

/// The retry-after changes only the delay: a bounded policy is exhausted at
/// the same attempt, by attempts or by duration, and `None` never retries.
#[test]
fn a_retry_after_keeps_the_policy_limits() {
    let retry_after = Some(Duration::from_millis(2_000));

    for policy in [fixed_delay(100, Some(3)), exponential(Some(3), None)] {
        // Two failures so far: this third one is the last attempt.
        assert_eq!(
            propose_retryable(retried_run(1, 100), retry_after, policy.clone()),
            (RunRetryOutcome::AttemptEnded, Some(2_000)),
            "{policy:?}"
        );
        assert_eq!(
            propose_retryable(retried_run(2, 100), retry_after, policy.clone()),
            (RunRetryOutcome::Completed, None),
            "{policy:?}"
        );
    }

    let by_duration = exponential(None, Some(Duration::from_millis(1_000)));
    assert_eq!(
        propose_retryable(retried_run(2, 900), retry_after, by_duration.clone()),
        (RunRetryOutcome::AttemptEnded, Some(2_000))
    );
    assert_eq!(
        propose_retryable(retried_run(2, 1_000), retry_after, by_duration),
        (RunRetryOutcome::Completed, None)
    );

    assert_eq!(
        propose_retryable(Invocation::new(), retry_after, RetryPolicy::None),
        (RunRetryOutcome::Completed, None)
    );
}

/// Proposes `failure` of `charge` under `policy` on `content_type` and
/// returns what the VM did with it: the outcome, and the code, message and
/// metadata it wrote (the terminal failure it proposed, or the error that
/// ends the attempt).
fn propose_failure(
    content_type: &'static str,
    failure: restate_php_core::RetryableFailure,
    policy: RetryPolicy,
) -> (RunRetryOutcome, u32, String, Vec<(String, String)>) {
    let (mut vm, handle) = executing_run(Invocation::new().content_type(content_type));
    let outcome = vm
        .propose_run_retryable(handle, failure, Duration::ZERO, None, policy)
        .unwrap();
    let frames = output(&mut vm);
    match outcome {
        RunRetryOutcome::AttemptEnded => {
            let error = single::<crate::protocol::ErrorMessage>(&frames);
            (outcome, error.code, error.message, vec![])
        }
        RunRetryOutcome::Completed => match single::<ProposeRunCompletionMessage>(&frames).result {
            Some(propose_run_completion_message::Result::Failure(failure)) => (
                outcome,
                failure.code,
                failure.message,
                failure
                    .metadata
                    .into_iter()
                    .map(|entry| (entry.key, entry.value))
                    .collect(),
            ),
            other => panic!("expected a failure proposal, got {other:?}"),
        },
    }
}

fn pairs(metadata: &[(&str, &str)]) -> Vec<(String, String)> {
    metadata
        .iter()
        .map(|(key, value)| (key.to_string(), value.to_string()))
        .collect()
}

/// Since shared-core 8.3.0 an exhausted policy turns the retryable failure
/// into the run's terminal failure with its code, message and metadata.
#[test]
fn an_exhausted_retryable_failure_keeps_its_code_and_metadata() {
    let metadata = [("retry-reason", "maintenance"), ("503", "numeric key")];

    for content_type in [V6, V7] {
        assert_eq!(
            propose_failure(
                content_type,
                retryable(503, "unavailable", &metadata),
                RetryPolicy::None,
            ),
            (
                RunRetryOutcome::Completed,
                503,
                "unavailable".to_string(),
                pairs(&metadata)
            ),
            "{content_type}"
        );
    }

    // Exhausted by attempts: the third failure under at most three attempts.
    let (mut vm, handle) = executing_run(retried_run(2, 100).content_type(V7));
    let outcome = vm
        .propose_run_retryable(
            handle,
            retryable(429, "slow down", &metadata),
            Duration::ZERO,
            Some(Duration::from_millis(2_000)),
            fixed_delay(100, Some(3)),
        )
        .unwrap();
    assert_eq!(outcome, RunRetryOutcome::Completed);
    assert_eq!(
        single::<ProposeRunCompletionMessage>(&output(&mut vm)).result,
        Some(propose_run_completion_message::Result::Failure(failure(
            429,
            "slow down",
            &metadata
        )))
    );
}

/// A scheduled retry ends the attempt with the failure's code, and the
/// heuristic in `run::retryable_outcome` still recognises it.
#[test]
fn a_scheduled_retry_ends_the_attempt_with_the_failure_code() {
    for content_type in [V6, V7] {
        assert_eq!(
            propose_failure(
                content_type,
                retryable(503, "unavailable", &[("retry-reason", "maintenance")]),
                RetryPolicy::Infinite,
            ),
            (
                RunRetryOutcome::AttemptEnded,
                503,
                "unavailable".to_string(),
                vec![]
            ),
            "{content_type}"
        );
    }
}

/// Only failure codes (400–599) are kept, except 570, which shared-core
/// treats as a journal mismatch; anything else (a PHP exception code of 0,
/// say) is submitted as 500.
#[test]
fn a_retryable_failure_code_outside_the_failure_codes_is_submitted_as_500() {
    for (code, submitted) in [
        (0, 500),
        (-1, 500),
        (399, 500),
        (400, 400),
        (570, 500),
        (599, 599),
        (600, 500),
        (i64::MAX, 500),
    ] {
        for policy in [RetryPolicy::None, RetryPolicy::Infinite] {
            let (_, written, ..) = propose_failure(V7, retryable(code, "boom", &[]), policy);
            assert_eq!(written, submitted, "code {code}");
        }
    }
}

/// Failure metadata needs protocol v6; on v5 the proposal fails.
#[test]
fn retryable_failure_metadata_is_refused_on_v5() {
    let (mut vm, handle) = executing_run(Invocation::new());

    let error = vm
        .propose_run_retryable(
            handle,
            retryable(503, "unavailable", &[("retry-reason", "maintenance")]),
            Duration::ZERO,
            None,
            RetryPolicy::None,
        )
        .unwrap_err();

    assert!(
        error.message().contains("terminal error metadata"),
        "{}",
        error.message()
    );
}
