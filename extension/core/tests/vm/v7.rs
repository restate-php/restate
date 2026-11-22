//! Protocol v7, which Restate 1.8 negotiates by default: the scenarios the
//! other modules cover on v5/v6 where v7 writes or expects something else
//! (future-tree suspensions, run completion acknowledgements, awaiting-on
//! messages, error behaviour), and the common scenarios once more on v7.

use crate::protocol::{
    call_completion_notification_message, get_eager_state_command_message, output_command_message,
    propose_run_completion_message, run_completion_notification_message, AwaitingOnMessage,
    CallCommandMessage, CallCompletionNotificationMessage,
    CallInvocationIdCompletionNotificationMessage, ClearStateCommandMessage, CombinatorType,
    EndMessage, ErrorBehavior, ErrorMessage, GetEagerStateCommandMessage,
    GetLazyStateCommandMessage, Message, OutputCommandMessage, ProposeRunCompletionAckMessage,
    ProposeRunCompletionMessage, RunCommandMessage, RunCompletionNotificationMessage,
    SetStateCommandMessage, SleepCommandMessage, SleepCompletionNotificationMessage,
    SuspensionMessage, Void,
};
use crate::support::*;
use restate_php_core::{
    Bytes, CallTarget, ErrorCategory, NotificationResult, Progress, RetryPolicy, RunRetryOutcome,
    TargetKind, Vm,
};
use std::time::Duration;

fn v7() -> Invocation {
    Invocation::new().content_type(V7)
}

fn ack(completion_id: u32) -> Bytes {
    encode(&ProposeRunCompletionAckMessage { completion_id })
}

/// The tree a suspension or awaiting-on message carries for a single awaited
/// handle: shared-core always awaits the cancel signal alongside it.
fn single_handle_tree(awaiting_on: Option<crate::protocol::Future>, completion_id: u32) {
    let future = awaiting_on.expect("the message carries the await point");
    assert_eq!(
        future.combinator_type,
        CombinatorType::FirstCompleted as i32
    );
    assert_eq!(future.waiting_completions, vec![completion_id]);
    assert_eq!(future.waiting_signals, vec![CANCEL_SIGNAL_ID]);
    assert!(future.nested_futures.is_empty());
}

#[test]
fn the_response_speaks_the_negotiated_version() {
    let vm = Vm::new(headers(V7)).unwrap();

    assert!(vm
        .response_head()
        .headers
        .contains(&("content-type".to_string(), V7.to_string())));
}

#[test]
fn the_input_carries_the_scope_limit_key_and_idempotency_key() {
    let mut vm = v7()
        .start(|start| {
            start.scope = Some("tenant-1".to_string());
            start.limit_key = Some("user-7".to_string());
            start.idempotency_key = Some("once".to_string());
        })
        .received();

    let input = vm.input().unwrap();

    assert_eq!(input.scope.as_deref(), Some("tenant-1"));
    assert_eq!(input.limit_key.as_deref(), Some("user-7"));
    assert_eq!(input.idempotency_key.as_deref(), Some("once"));
}

#[test]
fn the_input_has_no_scope_limit_key_or_idempotency_key_when_restate_sends_none() {
    let mut vm = v7().received();

    let input = vm.input().unwrap();

    assert_eq!(
        (input.scope, input.limit_key, input.idempotency_key),
        (None, None, None)
    );
}

#[test]
fn the_input_and_a_success_are_read_and_written_as_on_v6() {
    let mut vm = v7().key("alice").received();

    let input = vm.input().unwrap();
    assert_eq!(input.invocation_id, INVOCATION_ID);
    assert_eq!(input.key, "alice");
    assert_eq!(input.random_seed, 42, "v6+ takes the seed from Restate");
    assert_eq!(input.input, Bytes::from_static(b"\"Mary\""));

    vm.write_output_success(Bytes::from_static(b"\"Hello Mary\""))
        .unwrap();
    vm.end().unwrap();

    let frames = output(&mut vm);
    assert_eq!(
        message_types(&frames),
        vec![OutputCommandMessage::TYPE, EndMessage::TYPE]
    );
    assert_eq!(
        frames[0].decode::<OutputCommandMessage>().result,
        Some(output_command_message::Result::Value(value(
            b"\"Hello Mary\""
        )))
    );
    assert!(vm.take_output().is_none(), "the response is complete");
}

#[test]
fn a_sleep_suspends_with_the_await_point_as_a_future_tree() {
    let mut vm = v7().running();

    let handle = vm.sys_sleep(Duration::from_secs(60)).unwrap();

    assert_eq!(vm.do_await(handle).unwrap(), Progress::Suspended);
    let frames = output(&mut vm);
    assert_eq!(
        message_types(&frames),
        vec![SleepCommandMessage::TYPE, SuspensionMessage::TYPE]
    );
    let command: SleepCommandMessage = frames[0].decode();
    single_handle_tree(
        frames[1].decode::<SuspensionMessage>().awaiting_on,
        command.result_completion_id,
    );
    assert!(vm.take_output().is_none(), "the response is complete");
}

#[test]
fn an_awaited_awakeable_suspends_waiting_for_its_signal() {
    let mut vm = v7().running();
    let awakeable = vm.sys_awakeable().unwrap();

    assert_eq!(vm.do_await(awakeable.handle).unwrap(), Progress::Suspended);

    let suspension: SuspensionMessage = single(&output(&mut vm));
    let waiting = flatten(&suspension.awaiting_on.unwrap());
    assert!(waiting.waiting_signals.contains(&17), "{waiting:?}");
}

#[test]
fn awaiting_with_the_request_body_open_tells_restate_the_await_point() {
    let mut vm = v7().open().running();
    let handle = vm.sys_sleep(Duration::from_millis(10)).unwrap();
    let command: SleepCommandMessage = single(&output(&mut vm));

    assert_eq!(vm.do_await(handle).unwrap(), Progress::ReadFromInput);

    let awaiting: AwaitingOnMessage = single(&output(&mut vm));
    single_handle_tree(awaiting.awaiting_on, command.result_completion_id);
    assert!(!awaiting.executing_side_effects);

    vm.notify_input(encode(&SleepCompletionNotificationMessage {
        completion_id: command.result_completion_id,
        void: Some(Void {}),
    }));
    assert_eq!(completed(&mut vm, handle), NotificationResult::Void);
}

#[test]
fn a_run_proposal_requests_an_ack_and_completes_once_acknowledged() {
    let mut vm = v7().open().running();

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
    assert_eq!(frames[0].flags & REQUESTED_ACK, 0);
    assert_eq!(
        frames[1].flags & REQUESTED_ACK,
        REQUESTED_ACK,
        "v7 asks Restate for an ack instead of the stored result"
    );
    let proposal: ProposeRunCompletionMessage = frames[1].decode();
    assert_eq!(proposal.result_completion_id, command.result_completion_id);
    assert_eq!(
        proposal.result,
        Some(propose_run_completion_message::Result::Value(
            Bytes::from_static(b"42")
        ))
    );

    // The VM keeps the proposed result until Restate acknowledges it.
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ReadFromInput);
    vm.notify_input(ack(command.result_completion_id));

    assert_eq!(vm.do_await(handle).unwrap(), Progress::Completed(()));
    assert_eq!(
        vm.take_run_result(handle).unwrap(),
        Bytes::from_static(b"42")
    );
}

#[test]
fn an_acknowledged_terminal_failure_is_the_run_result() {
    let mut vm = v7().open().running();
    let handle = vm.sys_run("charge".to_string()).unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ExecuteRun(handle));
    vm.propose_run_failure(
        handle,
        terminal_failure(402, "card declined"),
        RetryPolicy::Infinite,
    )
    .unwrap();
    let command: RunCommandMessage = single(&output(&mut vm));

    vm.notify_input(ack(command.result_completion_id));

    assert_eq!(
        completed(&mut vm, handle),
        NotificationResult::Failure(terminal_failure(402, "card declined"))
    );
}

#[test]
fn an_ack_for_a_run_that_proposed_nothing_is_a_protocol_violation() {
    let mut vm = v7().open().running();
    let handle = vm.sys_run("charge".to_string()).unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ExecuteRun(handle));

    vm.notify_input(ack(1));

    let error = vm.do_await(handle).unwrap_err();
    assert_eq!(error.category(), ErrorCategory::Protocol);
    assert_eq!(error.code(), Some(571));
}

#[test]
fn a_proposed_run_suspends_on_its_completion_when_the_request_body_is_closed() {
    // Request/response: the ack arrives with the next attempt, as on v5/v6.
    let mut vm = v7().running();

    let handle = vm.sys_run("charge".to_string()).unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ExecuteRun(handle));
    assert_eq!(vm.do_await(handle).unwrap(), Progress::WaitingPendingRun);
    vm.propose_run_success(handle, Bytes::from_static(b"42"), RetryPolicy::Infinite)
        .unwrap();

    assert_eq!(vm.do_await(handle).unwrap(), Progress::Suspended);
    let frames = output(&mut vm);
    assert_eq!(
        message_types(&frames),
        vec![
            RunCommandMessage::TYPE,
            ProposeRunCompletionMessage::TYPE,
            SuspensionMessage::TYPE
        ]
    );
    let command: RunCommandMessage = frames[0].decode();
    single_handle_tree(
        frames[2].decode::<SuspensionMessage>().awaiting_on,
        command.result_completion_id,
    );
}

#[test]
fn a_replayed_run_completes_with_its_stored_result_without_executing() {
    let mut vm = v7()
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

/// The `run::retryable_outcome` heuristic on v7: the scheduled retry still
/// comes back as the submitted error, and the attempt ends with an error
/// message whose behaviour is "retry".
#[test]
fn a_retryable_run_failure_schedules_a_retry_or_completes_once_exhausted() {
    let mut vm = v7().running();
    let handle = vm.sys_run("charge".to_string()).unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ExecuteRun(handle));

    let outcome = vm
        .propose_run_retryable(
            handle,
            retryable(500, b"timeout".to_vec(), &[]),
            Duration::from_millis(5),
            None,
            RetryPolicy::Infinite,
        )
        .unwrap();

    assert_eq!(outcome, RunRetryOutcome::AttemptEnded);
    let error: ErrorMessage = single(&output(&mut vm));
    assert_eq!(error.message, "timeout");
    assert_eq!(error.behavior, ErrorBehavior::Retry as i32);

    let mut vm = v7().running();
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
    match single::<ProposeRunCompletionMessage>(&output(&mut vm)).result {
        Some(propose_run_completion_message::Result::Failure(failure)) => {
            assert_eq!((failure.code, failure.message.as_str()), (500, "timeout"));
        }
        other => panic!("expected a failure proposal, got {other:?}"),
    }
}

#[test]
fn a_retryable_handler_error_ends_the_attempt_for_restate_to_retry() {
    let mut vm = v7().running();

    vm.notify_error(b"boom".to_vec(), b"at handler".to_vec(), None);

    let error: ErrorMessage = single(&output(&mut vm));
    assert_eq!(error.code, 500);
    assert_eq!(error.message, "boom");
    assert_eq!(error.stacktrace, "at handler");
    assert_eq!(error.behavior, ErrorBehavior::Retry as i32);
    assert!(vm.take_output().is_none(), "the response is complete");
}

/// The default journal-mismatch behaviour follows the retry policy, as on
/// v5/v6 (shared-core sets it explicitly on v7).
#[test]
fn a_journal_mismatch_is_reported_for_restate_to_retry() {
    let mut vm = v7()
        .entry(SleepCommandMessage {
            wake_up_time: 1,
            result_completion_id: 1,
            ..SleepCommandMessage::default()
        })
        .entry(SleepCompletionNotificationMessage {
            completion_id: 1,
            void: Some(Void {}),
        })
        .running();

    let error = vm.sys_get_state("count".to_string()).unwrap_err();

    assert_eq!(error.category(), ErrorCategory::JournalMismatch);
    let reported: ErrorMessage = single(&output(&mut vm));
    assert_eq!(reported.code, 570);
    assert_eq!(reported.behavior, ErrorBehavior::Retry as i32);
}

#[test]
fn a_call_is_journaled_without_scope_or_limit_key_and_completes_with_the_result() {
    let mut vm = v7().running();

    let handle = vm
        .sys_call(
            CallTarget {
                kind: TargetKind::Object,
                service: "Counter".to_string(),
                handler: "add".to_string(),
                key: Some("alice".to_string()),
                headers: vec![],
                scope: None,
                limit_key: None,
            },
            Bytes::from_static(b"1"),
            Some("idem-1".to_string()),
        )
        .unwrap();

    let command: CallCommandMessage = single(&output(&mut vm));
    assert_eq!(command.service_name, "Counter");
    assert_eq!(command.key, "alice");
    assert_eq!(command.idempotency_key.as_deref(), Some("idem-1"));
    assert_eq!(command.scope, None);
    assert_eq!(command.limit_key, None);

    // Request/response: the next attempt replays the call with its result.
    let mut vm = v7()
        .entry(command.clone())
        .entry(CallInvocationIdCompletionNotificationMessage {
            completion_id: command.invocation_id_notification_idx,
            invocation_id: "inv-2".to_string(),
        })
        .entry(CallCompletionNotificationMessage {
            completion_id: command.result_completion_id,
            result: Some(call_completion_notification_message::Result::Value(value(
                b"2",
            ))),
        })
        .running();
    let handle_again = vm
        .sys_call(
            CallTarget {
                kind: TargetKind::Object,
                service: "Counter".to_string(),
                handler: "add".to_string(),
                key: Some("alice".to_string()),
                headers: vec![],
                scope: None,
                limit_key: None,
            },
            Bytes::from_static(b"1"),
            Some("idem-1".to_string()),
        )
        .unwrap();
    assert_eq!(handle_again, handle);
    assert_eq!(
        completed(&mut vm, handle),
        NotificationResult::Success(Bytes::from_static(b"2"))
    );
}

#[test]
fn eager_state_is_read_set_and_cleared_as_on_v6() {
    let mut vm = v7().key("alice").state("count", b"5").running();

    let count = vm.sys_get_state("count".to_string()).unwrap();
    assert_eq!(
        completed(&mut vm, count),
        NotificationResult::Success(Bytes::from_static(b"5"))
    );
    vm.set_state("count".to_string(), Bytes::from_static(b"6"))
        .unwrap();
    vm.clear_state("count".to_string()).unwrap();
    let keys = vm.sys_get_state_keys().unwrap();
    assert_eq!(
        completed(&mut vm, keys),
        NotificationResult::StateKeys(vec![]),
        "the cleared-keys workaround still applies"
    );

    let frames = output(&mut vm);
    assert_eq!(
        frames[0].decode::<GetEagerStateCommandMessage>().result,
        Some(get_eager_state_command_message::Result::Value(value(b"5")))
    );
    assert_eq!(
        frames[1].decode::<SetStateCommandMessage>().key,
        Bytes::from_static(b"count")
    );
    assert_eq!(
        frames[2].decode::<ClearStateCommandMessage>().key,
        Bytes::from_static(b"count")
    );
}

#[test]
fn a_lazy_state_read_suspends_on_its_completion() {
    let mut vm = v7().key("alice").lazy_state().running();

    let handle = vm.sys_get_state("count".to_string()).unwrap();

    assert_eq!(vm.do_await(handle).unwrap(), Progress::Suspended);
    let frames = output(&mut vm);
    let command: GetLazyStateCommandMessage = single(&frames);
    single_handle_tree(
        single::<SuspensionMessage>(&frames).awaiting_on,
        command.result_completion_id,
    );
}

#[test]
fn a_cancel_signal_interrupts_an_await() {
    let mut vm = v7().open().running();
    let handle = vm.sys_sleep(Duration::from_secs(60)).unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ReadFromInput);

    vm.notify_input(cancel_signal());

    assert_eq!(vm.do_await(handle).unwrap(), Progress::CancelSignalReceived);
}

#[test]
fn a_cancel_signal_interrupts_an_unacknowledged_run() {
    let mut vm = v7().open().running();
    let handle = vm.sys_run("charge".to_string()).unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ExecuteRun(handle));
    vm.propose_run_success(handle, Bytes::from_static(b"1"), RetryPolicy::Infinite)
        .unwrap();
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ReadFromInput);

    vm.notify_input(cancel_signal());

    assert_eq!(vm.do_await(handle).unwrap(), Progress::CancelSignalReceived);
}
