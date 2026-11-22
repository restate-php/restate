//! Calls, one-way sends, attaching to invocations, reading their output, and
//! cancelling them.

use crate::protocol::{
    attach_invocation_command_message, attach_invocation_completion_notification_message,
    call_completion_notification_message, get_invocation_output_command_message,
    get_invocation_output_completion_notification_message, send_signal_command_message,
    AttachInvocationCommandMessage, AttachInvocationCompletionNotificationMessage,
    CallCommandMessage, CallCompletionNotificationMessage,
    CallInvocationIdCompletionNotificationMessage, GetInvocationOutputCommandMessage,
    GetInvocationOutputCompletionNotificationMessage, Header, OneWayCallCommandMessage,
    SendSignalCommandMessage, WorkflowTarget,
};
use crate::support::*;
use restate_php_core::{
    AttachTarget, Bytes, CallTarget, ErrorCategory, NotificationResult, TargetKind,
};
use std::time::{Duration, SystemTime, UNIX_EPOCH};

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

fn call_completion(
    completion_id: u32,
    result: call_completion_notification_message::Result,
) -> Bytes {
    encode(&CallCompletionNotificationMessage {
        completion_id,
        result: Some(result),
    })
}

#[test]
fn a_call_is_journaled_and_completes_with_the_callee_result() {
    let mut vm = Invocation::new().open().running();

    let handle = vm
        .sys_call(greeter(), Bytes::from_static(b"\"Bob\""), None)
        .unwrap();

    let command: CallCommandMessage = single(&output(&mut vm));
    assert_eq!(command.service_name, "Greeter");
    assert_eq!(command.handler_name, "greet");
    assert_eq!(command.parameter, Bytes::from_static(b"\"Bob\""));
    assert_eq!(command.key, "");
    assert_eq!(command.idempotency_key, None);

    // Restate first reports the callee's invocation id, then its result.
    vm.notify_input(encode(&CallInvocationIdCompletionNotificationMessage {
        completion_id: command.invocation_id_notification_idx,
        invocation_id: "inv-2".to_string(),
    }));
    let result = completed_after(
        &mut vm,
        handle,
        call_completion(
            command.result_completion_id,
            call_completion_notification_message::Result::Value(value(b"\"Hello Bob\"")),
        ),
    );
    assert_eq!(
        result,
        NotificationResult::Success(Bytes::from_static(b"\"Hello Bob\""))
    );
}

#[test]
fn a_call_completes_with_the_callee_terminal_failure() {
    let mut vm = Invocation::new().open().running();

    let handle = vm.sys_call(greeter(), Bytes::new(), None).unwrap();
    let command: CallCommandMessage = single(&output(&mut vm));

    let result = completed_after(
        &mut vm,
        handle,
        call_completion(
            command.result_completion_id,
            call_completion_notification_message::Result::Failure(failure(
                400,
                "bad name",
                &[("field", "name")],
            )),
        ),
    );
    assert_eq!(
        result,
        NotificationResult::Failure(
            restate_php_core::TerminalFailure::new(
                400,
                "bad name",
                vec![("field".to_string(), "name".to_string())]
            )
            .unwrap()
        )
    );
}

#[test]
fn a_keyed_call_carries_its_key_headers_and_idempotency_key() {
    let mut vm = Invocation::new().running();

    vm.sys_call(
        CallTarget {
            kind: TargetKind::Object,
            service: "Counter".to_string(),
            handler: "add".to_string(),
            key: Some("alice".to_string()),
            headers: vec![("x-trace".to_string(), "abc".to_string())],
            scope: None,
            limit_key: None,
        },
        Bytes::from_static(b"1"),
        Some("once".to_string()),
    )
    .unwrap();

    let command: CallCommandMessage = single(&output(&mut vm));
    assert_eq!(command.key, "alice");
    assert_eq!(
        command.headers,
        vec![Header {
            key: "x-trace".to_string(),
            value: "abc".to_string()
        }]
    );
    assert_eq!(command.idempotency_key, Some("once".to_string()));
}

#[test]
fn a_target_whose_key_does_not_match_its_kind_is_refused() {
    let mut vm = Invocation::new().running();

    let keyed_service = vm
        .sys_call(
            CallTarget {
                key: Some("alice".to_string()),
                ..greeter()
            },
            Bytes::new(),
            None,
        )
        .unwrap_err();
    let unkeyed_object = vm
        .sys_call(
            CallTarget {
                kind: TargetKind::Object,
                ..greeter()
            },
            Bytes::new(),
            None,
        )
        .unwrap_err();

    assert_eq!(keyed_service.category(), ErrorCategory::InvalidArgument);
    assert_eq!(unkeyed_object.category(), ErrorCategory::InvalidArgument);
    assert!(output(&mut vm).is_empty(), "nothing was journaled");
}

#[test]
fn a_send_completes_with_the_new_invocation_id() {
    let mut vm = Invocation::new().open().running();

    let handle = vm
        .sys_send(greeter(), Bytes::from_static(b"\"Bob\""), None, None)
        .unwrap();
    let command: OneWayCallCommandMessage = single(&output(&mut vm));
    assert_eq!(command.service_name, "Greeter");
    assert_eq!(command.invoke_time, 0);

    let result = completed_after(
        &mut vm,
        handle,
        encode(&CallInvocationIdCompletionNotificationMessage {
            completion_id: command.invocation_id_notification_idx,
            invocation_id: "inv-2".to_string(),
        }),
    );
    assert_eq!(
        result,
        NotificationResult::InvocationId("inv-2".to_string())
    );
}

#[test]
fn a_delayed_send_is_journaled_with_its_invocation_time() {
    let mut vm = Invocation::new().running();
    let before = SystemTime::now().duration_since(UNIX_EPOCH).unwrap();

    vm.sys_send(greeter(), Bytes::new(), Some(Duration::from_secs(60)), None)
        .unwrap();

    let command: OneWayCallCommandMessage = single(&output(&mut vm));
    let expected = (before + Duration::from_secs(60)).as_millis() as u64;
    assert!(
        (expected..expected + 5_000).contains(&command.invoke_time),
        "invoke time {} is not about {expected}",
        command.invoke_time
    );
}

#[test]
fn attaching_completes_with_the_invocation_output() {
    let mut vm = Invocation::new().open().running();

    let handle = vm
        .sys_attach_invocation(AttachTarget::Invocation {
            invocation_id: "inv-2".to_string(),
        })
        .unwrap();
    let command: AttachInvocationCommandMessage = single(&output(&mut vm));
    assert_eq!(
        command.target,
        Some(attach_invocation_command_message::Target::InvocationId(
            "inv-2".to_string()
        ))
    );

    let result = completed_after(
        &mut vm,
        handle,
        encode(&AttachInvocationCompletionNotificationMessage {
            completion_id: command.result_completion_id,
            result: Some(
                attach_invocation_completion_notification_message::Result::Value(value(b"7")),
            ),
        }),
    );
    assert_eq!(
        result,
        NotificationResult::Success(Bytes::from_static(b"7"))
    );
}

#[test]
fn getting_the_output_of_an_unfinished_invocation_completes_with_void() {
    let mut vm = Invocation::new().open().running();

    let handle = vm
        .sys_get_invocation_output(AttachTarget::Workflow {
            name: "Checkout".to_string(),
            key: "order-1".to_string(),
            scope: None,
        })
        .unwrap();
    let command: GetInvocationOutputCommandMessage = single(&output(&mut vm));
    assert_eq!(
        command.target,
        Some(
            get_invocation_output_command_message::Target::WorkflowTarget(WorkflowTarget {
                workflow_name: "Checkout".to_string(),
                workflow_key: "order-1".to_string(),
                scope: None,
            })
        )
    );

    let result = completed_after(
        &mut vm,
        handle,
        encode(&GetInvocationOutputCompletionNotificationMessage {
            completion_id: command.result_completion_id,
            result: Some(
                get_invocation_output_completion_notification_message::Result::Void(
                    crate::protocol::Void {},
                ),
            ),
        }),
    );
    assert_eq!(result, NotificationResult::Void);
}

#[test]
fn cancelling_an_invocation_sends_it_the_cancel_signal() {
    let mut vm = Invocation::new().running();

    vm.sys_cancel_invocation("inv-2".to_string()).unwrap();

    let command: SendSignalCommandMessage = single(&output(&mut vm));
    assert_eq!(command.target_invocation_id, "inv-2");
    assert_eq!(
        command.signal_id,
        Some(send_signal_command_message::SignalId::Idx(1))
    );
}
