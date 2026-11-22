//! Workflow durable promises: get, peek, resolve and reject.

use crate::protocol::{
    complete_promise_command_message, complete_promise_completion_notification_message,
    get_promise_completion_notification_message, peek_promise_completion_notification_message,
    CompletePromiseCommandMessage, CompletePromiseCompletionNotificationMessage,
    GetPromiseCommandMessage, GetPromiseCompletionNotificationMessage, PeekPromiseCommandMessage,
    PeekPromiseCompletionNotificationMessage, Void,
};
use crate::support::*;
use restate_php_core::{Bytes, NotificationResult, Vm};

fn workflow() -> Vm {
    Invocation::new().key("order-1").open().running()
}

#[test]
fn a_promise_get_completes_with_the_promise_value_or_failure() {
    let mut vm = workflow();

    let resolved = vm.sys_get_promise("approval".to_string()).unwrap();
    let command: GetPromiseCommandMessage = single(&output(&mut vm));
    assert_eq!(command.key, "approval");
    assert_eq!(
        completed_after(
            &mut vm,
            resolved,
            encode(&GetPromiseCompletionNotificationMessage {
                completion_id: command.result_completion_id,
                result: Some(get_promise_completion_notification_message::Result::Value(
                    value(b"\"ok\"")
                )),
            }),
        ),
        NotificationResult::Success(Bytes::from_static(b"\"ok\""))
    );

    let rejected = vm.sys_get_promise("payment".to_string()).unwrap();
    let command: GetPromiseCommandMessage = single(&output(&mut vm));
    assert_eq!(
        completed_after(
            &mut vm,
            rejected,
            encode(&GetPromiseCompletionNotificationMessage {
                completion_id: command.result_completion_id,
                result: Some(
                    get_promise_completion_notification_message::Result::Failure(failure(
                        410,
                        "expired",
                        &[]
                    ))
                ),
            }),
        ),
        NotificationResult::Failure(terminal_failure(410, "expired"))
    );
}

#[test]
fn a_promise_peek_completes_with_void_while_the_promise_is_pending() {
    let mut vm = workflow();

    let pending = vm.sys_peek_promise("approval".to_string()).unwrap();
    let command: PeekPromiseCommandMessage = single(&output(&mut vm));
    assert_eq!(command.key, "approval");
    assert_eq!(
        completed_after(
            &mut vm,
            pending,
            encode(&PeekPromiseCompletionNotificationMessage {
                completion_id: command.result_completion_id,
                result: Some(peek_promise_completion_notification_message::Result::Void(
                    Void {}
                )),
            }),
        ),
        NotificationResult::Void
    );

    let resolved = vm.sys_peek_promise("approval".to_string()).unwrap();
    let command: PeekPromiseCommandMessage = single(&output(&mut vm));
    assert_eq!(
        completed_after(
            &mut vm,
            resolved,
            encode(&PeekPromiseCompletionNotificationMessage {
                completion_id: command.result_completion_id,
                result: Some(peek_promise_completion_notification_message::Result::Value(
                    value(b"\"ok\"")
                )),
            }),
        ),
        NotificationResult::Success(Bytes::from_static(b"\"ok\""))
    );
}

#[test]
fn completing_a_promise_journals_the_value_and_reports_whether_it_won() {
    let mut vm = workflow();

    let resolve = vm
        .sys_resolve_promise("approval".to_string(), Bytes::from_static(b"\"yes\""))
        .unwrap();
    let command: CompletePromiseCommandMessage = single(&output(&mut vm));
    assert_eq!(command.key, "approval");
    assert_eq!(
        command.completion,
        Some(complete_promise_command_message::Completion::CompletionValue(value(b"\"yes\"")))
    );
    assert_eq!(
        completed_after(
            &mut vm,
            resolve,
            encode(&CompletePromiseCompletionNotificationMessage {
                completion_id: command.result_completion_id,
                result: Some(
                    complete_promise_completion_notification_message::Result::Void(Void {})
                ),
            }),
        ),
        NotificationResult::Void
    );

    let reject = vm
        .sys_reject_promise("approval".to_string(), terminal_failure(409, "denied"))
        .unwrap();
    let command: CompletePromiseCommandMessage = single(&output(&mut vm));
    assert_eq!(
        command.completion,
        Some(
            complete_promise_command_message::Completion::CompletionFailure(failure(
                409,
                "denied",
                &[]
            ))
        )
    );
    // The promise was completed already: the rejection lost.
    assert_eq!(
        completed_after(
            &mut vm,
            reject,
            encode(&CompletePromiseCompletionNotificationMessage {
                completion_id: command.result_completion_id,
                result: Some(
                    complete_promise_completion_notification_message::Result::Failure(failure(
                        409,
                        "already completed",
                        &[]
                    ))
                ),
            }),
        ),
        NotificationResult::Failure(terminal_failure(409, "already completed"))
    );
}
