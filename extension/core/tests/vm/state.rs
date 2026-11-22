//! Object state: eager state Restate sends up front, and lazy state read
//! from Restate on demand.

use crate::protocol::{
    get_eager_state_command_message, get_lazy_state_completion_notification_message,
    ClearAllStateCommandMessage, ClearStateCommandMessage, GetEagerStateCommandMessage,
    GetEagerStateKeysCommandMessage, GetLazyStateCommandMessage,
    GetLazyStateCompletionNotificationMessage, GetLazyStateKeysCommandMessage,
    GetLazyStateKeysCompletionNotificationMessage, Message, SetStateCommandMessage, StateKeys,
    SuspensionMessageV6, Void,
};
use crate::support::*;
use restate_php_core::{Bytes, NotificationResult, Progress};

fn keys(keys: &[&str]) -> NotificationResult {
    NotificationResult::StateKeys(keys.iter().map(|key| key.to_string()).collect())
}

#[test]
fn eager_state_is_read_without_waiting_and_journaled_as_eager_commands() {
    let mut vm = Invocation::new()
        .key("alice")
        .state("count", b"5")
        .state("name", b"\"Alice\"")
        .running();

    let count = vm.sys_get_state("count".to_string()).unwrap();
    assert_eq!(
        completed(&mut vm, count),
        NotificationResult::Success(Bytes::from_static(b"5"))
    );
    // Restate sent all state: a key it did not send is not set.
    let missing = vm.sys_get_state("missing".to_string()).unwrap();
    assert_eq!(completed(&mut vm, missing), NotificationResult::Void);
    let all = vm.sys_get_state_keys().unwrap();
    assert_eq!(completed(&mut vm, all), keys(&["count", "name"]));

    let frames = output(&mut vm);
    assert_eq!(
        message_types(&frames),
        vec![
            GetEagerStateCommandMessage::TYPE,
            GetEagerStateCommandMessage::TYPE,
            GetEagerStateKeysCommandMessage::TYPE
        ]
    );
    let first: GetEagerStateCommandMessage = frames[0].decode();
    assert_eq!(first.key, Bytes::from_static(b"count"));
    assert_eq!(
        first.result,
        Some(get_eager_state_command_message::Result::Value(value(b"5")))
    );
    assert_eq!(
        frames[1].decode::<GetEagerStateCommandMessage>().result,
        Some(get_eager_state_command_message::Result::Void(Void {}))
    );
}

#[test]
fn set_clear_and_clear_all_are_journaled_and_visible_to_later_reads() {
    let mut vm = Invocation::new()
        .key("alice")
        .state("count", b"5")
        .state("name", b"\"Alice\"")
        .running();

    vm.set_state("count".to_string(), Bytes::from_static(b"6"))
        .unwrap();
    let after_set = vm.sys_get_state("count".to_string()).unwrap();
    assert_eq!(
        completed(&mut vm, after_set),
        NotificationResult::Success(Bytes::from_static(b"6"))
    );

    vm.clear_state("count".to_string()).unwrap();
    let after_clear = vm.sys_get_state("count".to_string()).unwrap();
    assert_eq!(completed(&mut vm, after_clear), NotificationResult::Void);
    let remaining = vm.sys_get_state_keys().unwrap();
    assert_eq!(completed(&mut vm, remaining), keys(&["name"]));

    vm.clear_all_state().unwrap();
    let none = vm.sys_get_state_keys().unwrap();
    assert_eq!(completed(&mut vm, none), keys(&[]));

    let frames = output(&mut vm);
    let set: SetStateCommandMessage = single(&frames);
    assert_eq!(set.key, Bytes::from_static(b"count"));
    assert_eq!(set.value, Some(value(b"6")));
    assert_eq!(
        single::<ClearStateCommandMessage>(&frames).key,
        Bytes::from_static(b"count")
    );
    single::<ClearAllStateCommandMessage>(&frames);
}

#[test]
fn a_lazy_state_read_waits_for_its_completion() {
    let mut vm = Invocation::new().key("alice").lazy_state().open().running();

    let count = vm.sys_get_state("count".to_string()).unwrap();
    let command: GetLazyStateCommandMessage = single(&output(&mut vm));
    assert_eq!(command.key, Bytes::from_static(b"count"));
    let value_result = completed_after(
        &mut vm,
        count,
        encode(&GetLazyStateCompletionNotificationMessage {
            completion_id: command.result_completion_id,
            result: Some(
                get_lazy_state_completion_notification_message::Result::Value(value(b"5")),
            ),
        }),
    );
    assert_eq!(
        value_result,
        NotificationResult::Success(Bytes::from_static(b"5"))
    );

    let missing = vm.sys_get_state("missing".to_string()).unwrap();
    let command: GetLazyStateCommandMessage = single(&output(&mut vm));
    let missing_result = completed_after(
        &mut vm,
        missing,
        encode(&GetLazyStateCompletionNotificationMessage {
            completion_id: command.result_completion_id,
            result: Some(get_lazy_state_completion_notification_message::Result::Void(Void {})),
        }),
    );
    assert_eq!(missing_result, NotificationResult::Void);
}

#[test]
fn lazy_state_keys_wait_for_their_completion() {
    let mut vm = Invocation::new().key("alice").lazy_state().open().running();

    let handle = vm.sys_get_state_keys().unwrap();
    let command: GetLazyStateKeysCommandMessage = single(&output(&mut vm));

    let result = completed_after(
        &mut vm,
        handle,
        encode(&GetLazyStateKeysCompletionNotificationMessage {
            completion_id: command.result_completion_id,
            state_keys: Some(StateKeys {
                keys: vec![Bytes::from_static(b"a"), Bytes::from_static(b"b")],
            }),
        }),
    );

    assert_eq!(result, keys(&["a", "b"]));
}

#[test]
fn lazy_state_reads_suspend_once_the_request_body_is_closed() {
    type Start =
        fn(&mut restate_php_core::Vm) -> restate_php_core::Result<restate_php_core::Handle>;
    let operations: [(&str, Start); 2] = [
        ("state get", |vm| vm.sys_get_state("count".to_string())),
        ("state keys", restate_php_core::Vm::sys_get_state_keys),
    ];

    for (name, start) in operations {
        let mut vm = Invocation::new().key("alice").lazy_state().running();
        let handle = start(&mut vm).unwrap();

        assert_eq!(vm.do_await(handle).unwrap(), Progress::Suspended, "{name}");
        // The attempt ends with a suspension message, not an error.
        let frames = output(&mut vm);
        assert_eq!(
            message_types(&frames).last(),
            Some(&SuspensionMessageV6::TYPE),
            "{name}"
        );
    }
}

/// shared-core (0.10.0 to 8.7.2) lists cleared eager keys; the core drops them (see
/// `Vm::sys_get_state_keys`) as of when each read started.
#[test]
fn state_keys_reflect_the_clears_and_sets_before_each_read() {
    let mut vm = Invocation::new()
        .key("alice")
        .state("count", b"5")
        .state("name", b"\"Alice\"")
        .running();

    let before_clear = vm.sys_get_state_keys().unwrap();
    vm.clear_state("name".to_string()).unwrap();
    let after_clear = vm.sys_get_state_keys().unwrap();
    vm.set_state("name".to_string(), Bytes::from_static(b"\"Al\""))
        .unwrap();
    let after_set = vm.sys_get_state_keys().unwrap();

    assert_eq!(completed(&mut vm, after_set), keys(&["count", "name"]));
    assert_eq!(completed(&mut vm, after_clear), keys(&["count"]));
    assert_eq!(completed(&mut vm, before_clear), keys(&["count", "name"]));
}

/// A replayed attempt re-runs the clear, so a replayed keys read returns what
/// the original attempt returned.
#[test]
fn replayed_state_keys_drop_the_keys_cleared_before_them() {
    let mut vm = Invocation::new()
        .key("alice")
        .state("count", b"5")
        .entry(ClearStateCommandMessage {
            key: Bytes::from_static(b"count"),
            ..ClearStateCommandMessage::default()
        })
        .entry(GetEagerStateKeysCommandMessage {
            // What shared-core journaled originally (it lists the cleared key).
            value: Some(StateKeys {
                keys: vec![Bytes::from_static(b"count")],
            }),
            ..GetEagerStateKeysCommandMessage::default()
        })
        .running();

    vm.clear_state("count".to_string()).unwrap();
    let replayed = vm.sys_get_state_keys().unwrap();

    assert_eq!(completed(&mut vm, replayed), keys(&[]));
}
