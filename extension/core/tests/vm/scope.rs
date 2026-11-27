//! Scope and limit key on calls, sends and attach targets (protocol v7,
//! Restate 1.8): v7 journals them on the command.

use crate::protocol::{
    attach_invocation_command_message, get_invocation_output_command_message,
    AttachInvocationCommandMessage, CallCommandMessage, GetInvocationOutputCommandMessage,
    IdempotentRequestTarget, OneWayCallCommandMessage, WorkflowTarget,
};
use crate::support::*;
use restate_php_core::{AttachTarget, Bytes, CallTarget, ErrorCategory, TargetKind};

fn scoped_counter(limit_key: Option<&str>) -> CallTarget {
    CallTarget {
        kind: TargetKind::Object,
        service: "Counter".to_string(),
        handler: "add".to_string(),
        key: Some("alice".to_string()),
        headers: vec![],
        scope: Some("tenant-1".to_string()),
        limit_key: limit_key.map(str::to_string),
    }
}

fn scoped_workflow() -> AttachTarget {
    AttachTarget::Workflow {
        name: "Checkout".to_string(),
        key: "order-1".to_string(),
        scope: Some("tenant-1".to_string()),
    }
}

fn scoped_idempotent_request() -> AttachTarget {
    AttachTarget::Idempotency {
        service_name: "Greeter".to_string(),
        service_key: None,
        handler_name: "greet".to_string(),
        idempotency_key: "once".to_string(),
        scope: Some("tenant-1".to_string()),
    }
}

#[test]
fn a_call_journals_its_scope_and_limit_key() {
    let mut vm = Invocation::new().content_type(V7).running();

    vm.sys_call(
        scoped_counter(Some("user/42")),
        Bytes::from_static(b"1"),
        Some("once".to_string()),
    )
    .unwrap();

    let command: CallCommandMessage = single(&output(&mut vm));
    assert_eq!(command.service_name, "Counter");
    assert_eq!(command.key, "alice");
    assert_eq!(command.idempotency_key.as_deref(), Some("once"));
    assert_eq!(command.scope.as_deref(), Some("tenant-1"));
    assert_eq!(command.limit_key.as_deref(), Some("user/42"));
}

#[test]
fn a_send_journals_its_scope_and_limit_key() {
    let mut vm = Invocation::new().content_type(V7).running();

    vm.sys_send(
        scoped_counter(Some("user")),
        Bytes::from_static(b"1"),
        None,
        None,
    )
    .unwrap();
    vm.sys_send(scoped_counter(None), Bytes::from_static(b"2"), None, None)
        .unwrap();

    let frames = output(&mut vm);
    let first: OneWayCallCommandMessage = frames[0].decode();
    assert_eq!(first.scope.as_deref(), Some("tenant-1"));
    assert_eq!(first.limit_key.as_deref(), Some("user"));
    let second: OneWayCallCommandMessage = frames[1].decode();
    assert_eq!(second.scope.as_deref(), Some("tenant-1"));
    assert_eq!(second.limit_key, None);
}

#[test]
fn attach_and_get_output_journal_the_scope_of_their_target() {
    let mut vm = Invocation::new().content_type(V7).running();

    vm.sys_attach_invocation(scoped_workflow()).unwrap();
    vm.sys_get_invocation_output(scoped_idempotent_request())
        .unwrap();

    let frames = output(&mut vm);
    assert_eq!(
        frames[0].decode::<AttachInvocationCommandMessage>().target,
        Some(attach_invocation_command_message::Target::WorkflowTarget(
            WorkflowTarget {
                workflow_name: "Checkout".to_string(),
                workflow_key: "order-1".to_string(),
                scope: Some("tenant-1".to_string()),
            }
        ))
    );
    assert_eq!(
        frames[1]
            .decode::<GetInvocationOutputCommandMessage>()
            .target,
        Some(
            get_invocation_output_command_message::Target::IdempotentRequestTarget(
                IdempotentRequestTarget {
                    service_name: "Greeter".to_string(),
                    service_key: None,
                    handler_name: "greet".to_string(),
                    idempotency_key: "once".to_string(),
                    scope: Some("tenant-1".to_string()),
                }
            )
        )
    );
}

#[test]
fn an_empty_scope_or_limit_key_or_a_limit_key_without_scope_is_refused_before_journaling() {
    let mut vm = Invocation::new().content_type(V7).running();

    let empty_scope = vm
        .sys_call(
            CallTarget {
                scope: Some(String::new()),
                ..scoped_counter(None)
            },
            Bytes::new(),
            None,
        )
        .unwrap_err();
    let empty_limit_key = vm
        .sys_send(scoped_counter(Some("")), Bytes::new(), None, None)
        .unwrap_err();
    let unscoped_limit_key = vm
        .sys_call(
            CallTarget {
                scope: None,
                ..scoped_counter(Some("user"))
            },
            Bytes::new(),
            None,
        )
        .unwrap_err();
    let empty_attach_scope = vm
        .sys_attach_invocation(AttachTarget::Workflow {
            name: "Checkout".to_string(),
            key: "order-1".to_string(),
            scope: Some(String::new()),
        })
        .unwrap_err();

    for (error, message) in [
        (empty_scope, "Scope must not be empty."),
        (empty_limit_key, "Limit key must not be empty."),
        (unscoped_limit_key, "A limit key needs a scope."),
        (empty_attach_scope, "Scope must not be empty."),
    ] {
        assert_eq!(error.category(), ErrorCategory::InvalidArgument);
        assert_eq!(error.message(), message);
    }
    assert!(output(&mut vm).is_empty(), "nothing was journaled");
}
