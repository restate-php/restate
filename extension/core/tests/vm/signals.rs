//! Named signals: awaiting one on this invocation, and resolving or rejecting
//! one on another invocation. Named signals exist on every protocol version
//! the core speaks (v5 to v8), so the scenarios run on v5 (the default here),
//! v6 and v7.

use crate::protocol::{
    send_signal_command_message, signal_notification_message, CombinatorType, Message,
    SendSignalCommandMessage, SignalNotificationMessage, SuspensionMessage, SuspensionMessageV6,
};
use crate::support::*;
use restate_php_core::{AwaitTree, Bytes, ErrorCategory, NotificationResult, Progress};

/// Restate's notification of a delivery of the signal `name` to this
/// invocation.
fn named_signal(name: &str, result: signal_notification_message::Result) -> Bytes {
    encode(&SignalNotificationMessage {
        signal_id: Some(signal_notification_message::SignalId::Name(
            name.to_string(),
        )),
        result: Some(result),
    })
}

fn resolved(name: &str, content: &[u8]) -> Bytes {
    named_signal(
        name,
        signal_notification_message::Result::Value(value(content)),
    )
}

#[test]
fn a_signal_completes_with_the_value_it_is_resolved_with() {
    for content_type in [V5, V6, V7] {
        let mut vm = Invocation::new()
            .content_type(content_type)
            .open()
            .running();

        let handle = vm.sys_signal("approved".to_string()).unwrap();
        assert!(output(&mut vm).is_empty(), "waiting journals nothing");

        let result = completed_after(&mut vm, handle, resolved("approved", b"true"));
        assert_eq!(
            result,
            NotificationResult::Success(Bytes::from_static(b"true")),
            "{content_type}"
        );
    }
}

#[test]
fn a_signal_completes_with_the_failure_it_is_rejected_with() {
    let mut vm = Invocation::new().open().running();
    let handle = vm.sys_signal("approved".to_string()).unwrap();

    let result = completed_after(
        &mut vm,
        handle,
        named_signal(
            "approved",
            signal_notification_message::Result::Failure(failure(500, "denied", &[])),
        ),
    );
    assert_eq!(
        result,
        NotificationResult::Failure(terminal_failure(500, "denied"))
    );
}

#[test]
fn a_signal_delivered_before_the_handler_waits_completes_at_once() {
    let mut vm = Invocation::new()
        .entry(SignalNotificationMessage {
            signal_id: Some(signal_notification_message::SignalId::Name(
                "approved".to_string(),
            )),
            result: Some(signal_notification_message::Result::Value(value(b"1"))),
        })
        .running();

    let handle = vm.sys_signal("approved".to_string()).unwrap();

    assert_eq!(
        completed(&mut vm, handle),
        NotificationResult::Success(Bytes::from_static(b"1"))
    );
}

#[test]
fn a_signal_of_another_name_does_not_complete_it() {
    let mut vm = Invocation::new().open().running();
    let handle = vm.sys_signal("approved".to_string()).unwrap();

    assert_eq!(vm.do_await(handle).unwrap(), Progress::ReadFromInput);
    vm.notify_input(resolved("rejected", b"1"));

    assert_eq!(vm.do_await(handle).unwrap(), Progress::ReadFromInput);
    assert!(!vm.is_completed(handle));
}

#[test]
fn the_same_signal_delivered_twice_is_observed_twice_in_order() {
    let mut vm = Invocation::new().open().running();
    let awakeable = vm.sys_awakeable().unwrap();

    // Both deliveries arrive while the handler awaits something else.
    assert_eq!(
        vm.do_await(awakeable.handle).unwrap(),
        Progress::ReadFromInput
    );
    vm.notify_input(resolved("approved", b"1"));
    vm.notify_input(resolved("approved", b"2"));

    let first = vm.sys_signal("approved".to_string()).unwrap();
    assert_eq!(
        completed(&mut vm, first),
        NotificationResult::Success(Bytes::from_static(b"1"))
    );
    let second = vm.sys_signal("approved".to_string()).unwrap();
    assert_eq!(
        completed(&mut vm, second),
        NotificationResult::Success(Bytes::from_static(b"2"))
    );
}

#[test]
fn an_awaited_signal_suspends_on_v5_and_v6_waiting_for_its_name() {
    for content_type in [V5, V6] {
        let mut vm = Invocation::new().content_type(content_type).running();
        let handle = vm.sys_signal("approved".to_string()).unwrap();

        assert_eq!(vm.do_await(handle).unwrap(), Progress::Suspended);

        let suspension: SuspensionMessageV6 = single(&output(&mut vm));
        assert_eq!(
            suspension.waiting_named_signals,
            vec!["approved".to_string()],
            "{content_type}"
        );
    }
}

#[test]
fn an_awaited_signal_suspends_on_v7_with_its_name_in_the_await_point() {
    let mut vm = Invocation::new().content_type(V7).running();
    let handle = vm.sys_signal("approved".to_string()).unwrap();

    assert_eq!(vm.do_await(handle).unwrap(), Progress::Suspended);

    let suspension: SuspensionMessage = single(&output(&mut vm));
    let future = suspension.awaiting_on.expect("the await point");
    assert_eq!(
        future.combinator_type,
        CombinatorType::FirstCompleted as i32
    );
    assert_eq!(future.waiting_named_signals, vec!["approved".to_string()]);
    assert_eq!(future.waiting_signals, vec![CANCEL_SIGNAL_ID]);
}

#[test]
fn signals_combine_with_other_handles_in_a_tree() {
    let mut vm = Invocation::new().open().running();
    let sleep = vm.sys_sleep(std::time::Duration::from_secs(60)).unwrap();
    let first = vm.sys_signal("a".to_string()).unwrap();
    let second = vm.sys_signal("b".to_string()).unwrap();
    output(&mut vm);
    let tree = AwaitTree::combinator(
        "first_completed",
        vec![
            AwaitTree::Single(sleep),
            AwaitTree::combinator(
                "all_succeeded_or_first_failed",
                vec![AwaitTree::Single(first), AwaitTree::Single(second)],
            )
            .unwrap(),
        ],
    )
    .unwrap();

    vm.notify_input(resolved("b", b"2"));
    assert_eq!(
        vm.do_await_tree(tree.clone()).unwrap(),
        Progress::ReadFromInput
    );
    vm.notify_input(resolved("a", b"1"));
    assert_eq!(
        vm.do_await_tree(tree).unwrap(),
        Progress::Completed(vec![first, second])
    );

    assert!(vm.is_completed(first) && vm.is_completed(second));
    assert!(!vm.is_completed(sleep));
}

#[test]
fn a_tree_of_signals_suspends_on_v7_with_their_names() {
    let mut vm = Invocation::new().content_type(V7).running();
    let first = vm.sys_signal("a".to_string()).unwrap();
    let second = vm.sys_signal("b".to_string()).unwrap();
    let tree = AwaitTree::combinator(
        "all_completed",
        vec![AwaitTree::Single(first), AwaitTree::Single(second)],
    )
    .unwrap();

    assert_eq!(vm.do_await_tree(tree).unwrap(), Progress::Suspended);

    let suspension: SuspensionMessage = single(&output(&mut vm));
    let root = suspension.awaiting_on.expect("the await point");
    assert_eq!(root.nested_futures.len(), 1);
    let combinator = &root.nested_futures[0];
    assert_eq!(
        combinator.combinator_type,
        CombinatorType::AllCompleted as i32
    );
    assert_eq!(
        combinator.waiting_named_signals,
        vec!["a".to_string(), "b".to_string()]
    );
}

#[test]
fn resolving_or_rejecting_a_signal_journals_a_send_signal_command() {
    for content_type in [V5, V6, V7] {
        let mut vm = Invocation::new().content_type(content_type).running();

        vm.complete_signal_success(
            "inv-2".to_string(),
            "approved".to_string(),
            Bytes::from_static(b"true"),
        )
        .unwrap();
        vm.complete_signal_failure(
            "inv-3".to_string(),
            "approved".to_string(),
            terminal_failure(409, "denied"),
        )
        .unwrap();

        let frames = output(&mut vm);
        assert_eq!(
            message_types(&frames),
            vec![
                SendSignalCommandMessage::TYPE,
                SendSignalCommandMessage::TYPE
            ],
            "{content_type}"
        );
        let resolved: SendSignalCommandMessage = frames[0].decode();
        assert_eq!(resolved.target_invocation_id, "inv-2");
        assert_eq!(
            resolved.signal_id,
            Some(send_signal_command_message::SignalId::Name(
                "approved".to_string()
            ))
        );
        assert_eq!(
            resolved.result,
            Some(send_signal_command_message::Result::Value(value(b"true")))
        );
        let rejected: SendSignalCommandMessage = frames[1].decode();
        assert_eq!(rejected.target_invocation_id, "inv-3");
        assert_eq!(
            rejected.result,
            Some(send_signal_command_message::Result::Failure(failure(
                409,
                "denied",
                &[]
            )))
        );
    }
}

#[test]
fn a_replayed_send_signal_command_journals_nothing_new() {
    let mut vm = Invocation::new()
        .entry(SendSignalCommandMessage {
            target_invocation_id: "inv-2".to_string(),
            signal_id: Some(send_signal_command_message::SignalId::Name(
                "approved".to_string(),
            )),
            result: Some(send_signal_command_message::Result::Value(value(b"true"))),
            ..SendSignalCommandMessage::default()
        })
        .running();

    vm.complete_signal_success(
        "inv-2".to_string(),
        "approved".to_string(),
        Bytes::from_static(b"true"),
    )
    .unwrap();

    assert!(output(&mut vm).is_empty());
}

#[test]
fn an_empty_signal_name_is_an_invalid_argument() {
    let mut vm = Invocation::new().running();

    let errors = [
        vm.sys_signal(String::new()).unwrap_err(),
        vm.complete_signal_success("inv-2".to_string(), String::new(), Bytes::new())
            .unwrap_err(),
        vm.complete_signal_failure(
            "inv-2".to_string(),
            String::new(),
            terminal_failure(500, "no"),
        )
        .unwrap_err(),
    ];

    for error in errors {
        assert_eq!(error.category(), ErrorCategory::InvalidArgument);
        assert_eq!(error.message(), "Signal name must not be empty.");
    }
    assert!(output(&mut vm).is_empty());
}
