//! Sleeps and awakeables.

use crate::protocol::{
    complete_awakeable_command_message, signal_notification_message,
    CompleteAwakeableCommandMessage, Message, SignalNotificationMessage, SleepCommandMessage,
    SleepCompletionNotificationMessage, SuspensionMessageV6, Void,
};
use crate::support::*;
use restate_php_core::{Bytes, NotificationResult, Progress};
use std::time::{Duration, SystemTime, UNIX_EPOCH};

#[test]
fn a_sleep_suspends_the_invocation_until_its_timer_fires() {
    let mut vm = Invocation::new().running();
    let before = SystemTime::now().duration_since(UNIX_EPOCH).unwrap();

    let handle = vm.sys_sleep(Duration::from_secs(60)).unwrap();

    assert_eq!(vm.do_await(handle).unwrap(), Progress::Suspended);
    let frames = output(&mut vm);
    assert_eq!(
        message_types(&frames),
        vec![SleepCommandMessage::TYPE, SuspensionMessageV6::TYPE]
    );
    let command: SleepCommandMessage = frames[0].decode();
    let expected = (before + Duration::from_secs(60)).as_millis() as u64;
    assert!(
        (expected..expected + 5_000).contains(&command.wake_up_time),
        "wake-up time {} is not about {expected}",
        command.wake_up_time
    );
    assert_eq!(
        frames[1]
            .decode::<SuspensionMessageV6>()
            .waiting_completions,
        vec![command.result_completion_id]
    );
}

#[test]
fn a_sleep_completes_with_void_when_its_timer_fires() {
    let mut vm = Invocation::new().open().running();

    let handle = vm.sys_sleep(Duration::from_millis(10)).unwrap();
    let command: SleepCommandMessage = single(&output(&mut vm));

    let result = completed_after(
        &mut vm,
        handle,
        encode(&SleepCompletionNotificationMessage {
            completion_id: command.result_completion_id,
            void: Some(Void {}),
        }),
    );
    assert_eq!(result, NotificationResult::Void);
}

#[test]
fn a_replayed_sleep_that_fired_completes_at_once() {
    let mut vm = Invocation::new()
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

    let handle = vm.sys_sleep(Duration::from_secs(60)).unwrap();

    assert_eq!(completed(&mut vm, handle), NotificationResult::Void);
    assert!(output(&mut vm).is_empty());
}

/// The awakeable's signal: the id Restate addresses it by is derived from the
/// signal index the VM allocated, which is what Restate sends back.
fn awakeable_signal(index: u32, result: signal_notification_message::Result) -> Bytes {
    encode(&SignalNotificationMessage {
        signal_id: Some(signal_notification_message::SignalId::Idx(index)),
        result: Some(result),
    })
}

/// User signals (awakeables) are numbered from 17; 1–16 are built-in.
const FIRST_AWAKEABLE_SIGNAL: u32 = 17;

#[test]
fn an_awakeable_completes_with_the_value_it_is_resolved_with() {
    let mut vm = Invocation::new().open().running();

    let awakeable = vm.sys_awakeable().unwrap();
    assert!(awakeable.id.starts_with("sign_1"), "{}", awakeable.id);
    assert!(output(&mut vm).is_empty(), "creating one journals nothing");

    let result = completed_after(
        &mut vm,
        awakeable.handle,
        awakeable_signal(
            FIRST_AWAKEABLE_SIGNAL,
            signal_notification_message::Result::Value(value(b"\"paid\"")),
        ),
    );
    assert_eq!(
        result,
        NotificationResult::Success(Bytes::from_static(b"\"paid\""))
    );
}

#[test]
fn an_awakeable_completes_with_the_failure_it_is_rejected_with() {
    let mut vm = Invocation::new().open().running();
    let awakeable = vm.sys_awakeable().unwrap();

    let result = completed_after(
        &mut vm,
        awakeable.handle,
        awakeable_signal(
            FIRST_AWAKEABLE_SIGNAL,
            signal_notification_message::Result::Failure(failure(400, "declined", &[])),
        ),
    );
    assert_eq!(
        result,
        NotificationResult::Failure(terminal_failure(400, "declined"))
    );
}

#[test]
fn an_awaited_awakeable_suspends_the_invocation_waiting_for_its_signal() {
    let mut vm = Invocation::new().running();
    let awakeable = vm.sys_awakeable().unwrap();

    assert_eq!(vm.do_await(awakeable.handle).unwrap(), Progress::Suspended);
    let suspension: SuspensionMessageV6 = single(&output(&mut vm));
    assert!(suspension.waiting_signals.contains(&FIRST_AWAKEABLE_SIGNAL));
}

#[test]
fn completing_an_awakeable_journals_its_value_or_failure() {
    let mut vm = Invocation::new().running();

    vm.complete_awakeable_success("sign_1abc".to_string(), Bytes::from_static(b"\"ok\""))
        .unwrap();
    vm.complete_awakeable_failure("sign_1def".to_string(), terminal_failure(400, "no"))
        .unwrap();

    let frames = output(&mut vm);
    assert_eq!(
        message_types(&frames),
        vec![
            CompleteAwakeableCommandMessage::TYPE,
            CompleteAwakeableCommandMessage::TYPE
        ]
    );
    let resolved: CompleteAwakeableCommandMessage = frames[0].decode();
    assert_eq!(resolved.awakeable_id, "sign_1abc");
    assert_eq!(
        resolved.result,
        Some(complete_awakeable_command_message::Result::Value(value(
            b"\"ok\""
        )))
    );
    assert_eq!(
        frames[1].decode::<CompleteAwakeableCommandMessage>().result,
        Some(complete_awakeable_command_message::Result::Failure(
            failure(400, "no", &[])
        ))
    );
}
