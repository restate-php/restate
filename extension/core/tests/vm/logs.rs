//! shared-core's log events, captured by a VM created with a log level.

use crate::protocol::{SleepCommandMessage, SleepCompletionNotificationMessage, Void};
use crate::support::*;
use restate_php_core::{ErrorCategory, LogLevel, LogRecord, Progress, Vm};
use std::time::Duration;

fn messages(records: &[LogRecord]) -> Vec<(LogLevel, &str)> {
    records
        .iter()
        .map(|record| (record.level, record.message.as_str()))
        .collect()
}

/// The journal recorded a sleep where the handler now reads state.
fn diverging(level: Option<LogLevel>) -> Invocation {
    let invocation = Invocation::new()
        .entry(SleepCommandMessage {
            wake_up_time: 1,
            result_completion_id: 1,
            ..SleepCommandMessage::default()
        })
        .entry(SleepCompletionNotificationMessage {
            completion_id: 1,
            void: Some(Void {}),
        });
    match level {
        Some(level) => invocation.log_level(level),
        None => invocation,
    }
}

#[test]
fn a_journal_mismatch_is_logged_at_debug() {
    let mut vm = diverging(Some(LogLevel::Debug)).running();
    vm.take_logs();

    let error = vm
        .logged(|vm| vm.sys_get_state("count".to_string()))
        .unwrap_err();

    assert_eq!(error.category(), ErrorCategory::JournalMismatch);
    let records = vm.take_logs();
    let mismatch = records
        .iter()
        .find(|record| record.message.starts_with("Failed with error"))
        .unwrap_or_else(|| panic!("no mismatch record in {records:?}"));
    assert_eq!(mismatch.level, LogLevel::Debug);
    assert!(
        mismatch
            .message
            .contains("Found a mismatch between the code paths"),
        "{}",
        mismatch.message
    );
    assert!(mismatch.target.starts_with("restate_sdk_shared_core"));
}

#[test]
fn starting_and_suspending_are_logged_at_debug() {
    let mut vm = Invocation::new().log_level(LogLevel::Debug).running();
    assert_eq!(
        messages(&vm.take_logs()),
        vec![(LogLevel::Debug, "Start invocation")]
    );

    let progress = vm.logged(|vm| {
        let handle = vm.sys_sleep(Duration::from_secs(60)).unwrap();
        vm.do_await(handle).unwrap()
    });

    assert_eq!(progress, Progress::Suspended);
    assert!(messages(&vm.take_logs()).contains(&(LogLevel::Debug, "Suspending")));
}

#[test]
fn a_level_above_debug_captures_none_of_the_debug_events() {
    let mut vm = diverging(Some(LogLevel::Info)).running();

    let _ = vm.logged(|vm| vm.sys_get_state("count".to_string()));

    assert_eq!(vm.take_logs(), vec![]);
}

#[test]
fn a_vm_without_a_level_captures_nothing() {
    let mut vm = diverging(None).running();

    let _ = vm.logged(|vm| vm.sys_get_state("count".to_string()));

    assert_eq!(vm.take_logs(), vec![]);
}

#[test]
fn operations_outside_logged_are_not_captured() {
    let mut vm = diverging(Some(LogLevel::Debug)).running();
    vm.take_logs();

    let _ = vm.sys_get_state("count".to_string());

    assert_eq!(vm.take_logs(), vec![]);
}

#[test]
fn two_vms_on_one_thread_capture_their_own_events() {
    let mut debug = Invocation::new().log_level(LogLevel::Debug).running();
    let mut quiet = Invocation::new().log_level(LogLevel::Warn).running();

    for vm in [&mut debug, &mut quiet] {
        vm.logged(|vm| {
            let handle = vm.sys_sleep(Duration::from_secs(60)).unwrap();
            vm.do_await(handle).unwrap()
        });
    }

    assert!(messages(&debug.take_logs()).contains(&(LogLevel::Debug, "Suspending")));
    assert_eq!(quiet.take_logs(), vec![]);
}

#[test]
fn a_vm_that_cannot_be_created_fails_as_without_a_level() {
    let error = Vm::with_log_level(headers("application/json"), Some(LogLevel::Trace))
        .err()
        .unwrap();

    assert_eq!(error.code(), Some(415));
}
