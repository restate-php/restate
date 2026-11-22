//! Awaiting handles: awaiting several at once (the combinators themselves
//! are in `combinators`), which handles completed, and cancellation as a
//! typed outcome of every await.

use crate::protocol::{
    send_signal_command_message, signal_notification_message, CallCommandMessage,
    CallInvocationIdCompletionNotificationMessage, Message, SendSignalCommandMessage,
    SignalNotificationMessage, SleepCommandMessage, SleepCompletionNotificationMessage, Void,
};
use crate::support::*;
use restate_php_core::{
    AttachTarget, AwaitTree, Bytes, CallTarget, Handle, Progress, Result, RetryPolicy, TargetKind,
    Vm,
};
use std::time::Duration;

fn greeter() -> CallTarget {
    CallTarget {
        kind: TargetKind::Service,
        service: "Greeter".to_string(),
        handler: "greet".to_string(),
        key: None,
        headers: vec![],
        scope: None,
        limit_key: None,
    }
}

fn first_completed(handles: &[Handle]) -> AwaitTree {
    AwaitTree::FirstCompleted(handles.iter().copied().map(AwaitTree::Single).collect())
}

#[test]
fn awaiting_the_first_of_several_reports_which_handle_completed() {
    let mut vm = Invocation::new().open().running();
    let slow = vm.sys_sleep(Duration::from_secs(60)).unwrap();
    let fast = vm.sys_sleep(Duration::from_millis(10)).unwrap();
    let commands: Vec<SleepCommandMessage> =
        output(&mut vm).iter().map(|frame| frame.decode()).collect();

    assert_eq!(
        vm.do_await_tree(first_completed(&[slow, fast])).unwrap(),
        Progress::ReadFromInput
    );
    assert!(!vm.is_completed(slow) && !vm.is_completed(fast));
    vm.notify_input(encode(&SleepCompletionNotificationMessage {
        completion_id: commands[1].result_completion_id,
        void: Some(Void {}),
    }));

    assert_eq!(
        vm.do_await_tree(first_completed(&[slow, fast])).unwrap(),
        Progress::Completed(vec![fast])
    );
    assert!(!vm.is_completed(slow));
    assert!(vm.is_completed(fast));
}

#[test]
fn a_handle_is_completed_only_once_an_await_saw_its_result() {
    let mut vm = Invocation::new().open().running();
    let handle = vm.sys_sleep(Duration::from_millis(10)).unwrap();
    let command: SleepCommandMessage = single(&output(&mut vm));

    vm.notify_input(encode(&SleepCompletionNotificationMessage {
        completion_id: command.result_completion_id,
        void: Some(Void {}),
    }));
    assert!(!vm.is_completed(handle), "not processed by an await yet");

    assert_eq!(vm.do_await(handle).unwrap(), Progress::Completed(()));
    assert!(vm.is_completed(handle));
}

#[test]
fn an_unknown_handle_is_not_completed() {
    let vm = Invocation::new().running();

    assert!(!vm.is_completed(4_000));
}

type Start = fn(&mut Vm) -> Result<Handle>;

/// Every operation that returns a handle to await, started on a running VM,
/// except calls (see [`a_cancel_signal_first_cancels_the_calls_it_made`]).
fn awaiting_operations() -> Vec<(&'static str, Start)> {
    vec![
        ("state get", |vm| vm.sys_get_state("count".to_string())),
        ("state keys", Vm::sys_get_state_keys),
        ("promise get", |vm| {
            vm.sys_get_promise("approval".to_string())
        }),
        ("promise peek", |vm| {
            vm.sys_peek_promise("approval".to_string())
        }),
        ("promise resolve", |vm| {
            vm.sys_resolve_promise("approval".to_string(), Bytes::from_static(b"1"))
        }),
        ("promise reject", |vm| {
            vm.sys_reject_promise("approval".to_string(), terminal_failure(409, "denied"))
        }),
        ("sleep", |vm| vm.sys_sleep(Duration::from_secs(60))),
        ("awakeable", |vm| Ok(vm.sys_awakeable()?.handle)),
        ("send", |vm| {
            vm.sys_send(greeter(), Bytes::new(), None, None)
        }),
        ("attach", |vm| {
            vm.sys_attach_invocation(AttachTarget::Invocation {
                invocation_id: "inv-2".to_string(),
            })
        }),
        ("get output", |vm| {
            vm.sys_get_invocation_output(AttachTarget::Idempotency {
                service_name: "Greeter".to_string(),
                service_key: None,
                handler_name: "greet".to_string(),
                idempotency_key: "once".to_string(),
                scope: None,
            })
        }),
        ("run acknowledgement", |vm| {
            let handle = vm.sys_run("charge".to_string())?;
            assert_eq!(vm.do_await(handle)?, Progress::ExecuteRun(handle));
            vm.propose_run_success(handle, Bytes::from_static(b"1"), RetryPolicy::Infinite)?;
            Ok(handle)
        }),
    ]
}

fn awaiting_vm() -> Vm {
    awaiting_vm_on(V5)
}

fn awaiting_vm_on(content_type: &'static str) -> Vm {
    // Lazy state, so state reads wait for Restate too.
    Invocation::new()
        .content_type(content_type)
        .key("order-1")
        .lazy_state()
        .open()
        .running()
}

#[test]
fn a_cancel_signal_interrupts_every_awaiting_operation_as_a_typed_outcome() {
    for content_type in [V5, V6, V7] {
        for (name, start) in awaiting_operations() {
            let mut vm = awaiting_vm_on(content_type);
            let handle = start(&mut vm).unwrap();
            assert_eq!(
                vm.do_await(handle).unwrap(),
                Progress::ReadFromInput,
                "{content_type} {name}"
            );

            vm.notify_input(cancel_signal());

            assert_eq!(
                vm.do_await(handle).unwrap(),
                Progress::CancelSignalReceived,
                "{content_type} {name}"
            );
        }
    }
}

#[test]
fn a_cancel_signal_interrupts_every_combinator() {
    for content_type in [V5, V6, V7] {
        for combinator in [
            "first_completed",
            "all_completed",
            "first_succeeded_or_all_failed",
            "all_succeeded_or_first_failed",
        ] {
            let mut vm = awaiting_vm_on(content_type);
            let sleep = vm.sys_sleep(Duration::from_secs(60)).unwrap();
            let promise = vm.sys_get_promise("approval".to_string()).unwrap();
            let tree = AwaitTree::combinator(
                combinator,
                vec![AwaitTree::Single(sleep), AwaitTree::Single(promise)],
            )
            .unwrap();

            vm.notify_input(cancel_signal());

            assert_eq!(
                vm.do_await_tree(tree).unwrap(),
                Progress::CancelSignalReceived,
                "{content_type} {combinator}"
            );
        }
    }
}

#[test]
fn a_cancel_signal_already_in_the_journal_interrupts_the_first_await() {
    let mut vm = Invocation::new()
        .entry(SignalNotificationMessage {
            signal_id: Some(signal_notification_message::SignalId::Idx(1)),
            result: Some(signal_notification_message::Result::Void(Void {})),
        })
        .running();

    let handle = vm.sys_sleep(Duration::from_secs(60)).unwrap();

    assert_eq!(vm.do_await(handle).unwrap(), Progress::CancelSignalReceived);
    // The interrupted operation is still journaled.
    assert!(message_types(&output(&mut vm)).contains(&SleepCommandMessage::TYPE));
}

/// Cancellation propagates: before reporting the cancel signal, the VM
/// cancels every invocation this one called (not the ones it sent to), so it
/// first waits for Restate to report their invocation ids.
#[test]
fn a_cancel_signal_first_cancels_the_calls_it_made() {
    let mut vm = awaiting_vm();
    let handle = vm.sys_call(greeter(), Bytes::new(), None).unwrap();
    let id_completion =
        single::<CallCommandMessage>(&output(&mut vm)).invocation_id_notification_idx;

    vm.notify_input(cancel_signal());
    assert_eq!(
        vm.do_await(handle).unwrap(),
        Progress::ReadFromInput,
        "waits for the callee's invocation id"
    );

    vm.notify_input(encode(&CallInvocationIdCompletionNotificationMessage {
        completion_id: id_completion,
        invocation_id: "inv-2".to_string(),
    }));
    assert_eq!(vm.do_await(handle).unwrap(), Progress::CancelSignalReceived);

    let cancel: SendSignalCommandMessage = single(&output(&mut vm));
    assert_eq!(cancel.target_invocation_id, "inv-2");
    assert_eq!(
        cancel.signal_id,
        Some(send_signal_command_message::SignalId::Idx(1))
    );
}
