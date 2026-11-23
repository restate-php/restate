//! A whole invocation: protocol negotiation, input, output, failures, and
//! input the VM must reject.

use crate::protocol::{
    output_command_message, EndMessage, ErrorMessage, Header, Message, OutputCommandMessage,
    SleepCommandMessage, SleepCompletionNotificationMessage, StartMessage, Void,
};
use crate::support::*;
use restate_php_core::{protocol_versions, Bytes, ErrorCategory, TerminalFailure, Vm};
use std::time::Duration;

#[test]
fn every_supported_protocol_version_is_negotiated() {
    for content_type in [V5, V6, V7, V8] {
        let vm = Vm::new(headers(content_type)).unwrap();
        let head = vm.response_head();

        assert_eq!(head.status_code, 200);
        assert!(
            head.headers
                .contains(&("content-type".to_string(), content_type.to_string())),
            "{content_type}: {:?}",
            head.headers
        );
        assert!(!vm.is_ready_to_execute().unwrap());
    }
}

/// The range Discovery advertises is exactly the range the VM negotiates.
#[test]
fn the_reported_protocol_range_is_what_the_vm_negotiates() {
    let versions = protocol_versions();
    assert_eq!((versions.min, versions.max), (5, 8));

    let content_type = |version: u32| format!("application/vnd.restate.invocation.v{version}");
    for version in versions.min..=versions.max {
        assert!(
            Vm::new(headers(&content_type(version))).is_ok(),
            "v{version}"
        );
    }
    for version in [versions.min - 1, versions.max + 1] {
        assert!(
            Vm::new(headers(&content_type(version))).is_err(),
            "v{version}"
        );
    }
}

#[test]
fn unsupported_protocol_versions_are_protocol_errors() {
    for content_type in [
        "application/vnd.restate.invocation.v1",
        "application/vnd.restate.invocation.v4",
        "application/vnd.restate.invocation.v9",
        "application/json",
    ] {
        let error = Vm::new(headers(content_type)).err().unwrap();

        assert_eq!(error.category(), ErrorCategory::Protocol, "{content_type}");
        assert_eq!(error.code(), Some(415), "{content_type}");
    }

    let missing = Vm::new(vec![]).err().unwrap();
    assert_eq!(missing.category(), ErrorCategory::Protocol);
    assert_eq!(missing.code(), Some(415));
}

#[test]
fn the_input_comes_from_the_start_message_and_the_input_command() {
    let mut vm = Invocation::new()
        .key("alice")
        .input(|input| {
            input.headers.push(Header {
                key: "x-trace".to_string(),
                value: "abc".to_string(),
            })
        })
        .received();

    let input = vm.input().unwrap();

    assert_eq!(input.invocation_id, INVOCATION_ID);
    assert_eq!(input.key, "alice");
    assert_eq!(input.random_seed, seed_for(INVOCATION_ID, V5));
    assert_eq!(
        input.headers,
        vec![("x-trace".to_string(), "abc".to_string())]
    );
    assert_eq!(input.input, Bytes::from_static(b"\"Mary\""));
    assert!(!vm.is_replaying());
}

#[test]
fn the_input_has_no_scope_limit_key_or_idempotency_key_before_v7() {
    for content_type in [V5, V6] {
        // Even when the start message carries them: they are v7 fields.
        let mut vm = Invocation::new()
            .content_type(content_type)
            .start(|start| {
                start.scope = Some("tenant-1".to_string());
                start.limit_key = Some("user-7".to_string());
                start.idempotency_key = Some("once".to_string());
            })
            .received();

        let input = vm.input().unwrap();

        assert_eq!(
            (input.scope, input.limit_key, input.idempotency_key),
            (None, None, None),
            "{content_type}"
        );
    }
}

/// The seed `sys_input` reports for an invocation with this id.
fn seed_for(invocation_id: &'static str, content_type: &'static str) -> u64 {
    let id = invocation_id.to_string();
    let mut vm = Invocation::new()
        .content_type(content_type)
        .start(|start| {
            start.id = Bytes::from(id.clone().into_bytes());
            start.debug_id = id;
        })
        .received();
    vm.input().unwrap().random_seed
}

#[test]
fn the_random_seed_comes_from_restate_from_v6_and_is_derived_from_the_id_before() {
    // v6: the start message carries it (42 in every test invocation).
    assert_eq!(seed_for(INVOCATION_ID, V6), 42);
    // v5: derived from the invocation id, so stable across attempts.
    assert_eq!(seed_for(INVOCATION_ID, V5), seed_for(INVOCATION_ID, V5));
    assert_ne!(seed_for(INVOCATION_ID, V5), seed_for("inv-2", V5));
    assert_ne!(seed_for(INVOCATION_ID, V5), 42);
}

#[test]
fn a_success_is_written_as_an_output_command_followed_by_end() {
    let mut vm = Invocation::new().running();

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
fn a_terminal_failure_is_written_with_its_code_message_and_metadata() {
    let mut vm = Invocation::new().content_type(V6).running();

    vm.write_output_failure(
        TerminalFailure::new(
            404,
            "not found",
            vec![("404".to_string(), "missing".to_string())],
        )
        .unwrap(),
    )
    .unwrap();
    vm.end().unwrap();

    let frames = output(&mut vm);
    assert_eq!(
        single::<OutputCommandMessage>(&frames).result,
        Some(output_command_message::Result::Failure(failure(
            404,
            "not found",
            &[("404", "missing")]
        )))
    );
    single::<EndMessage>(&frames);
}

#[test]
fn terminal_failure_metadata_needs_protocol_v6() {
    let mut vm = Invocation::new().running();

    let error = vm
        .write_output_failure(
            TerminalFailure::new(404, "not found", vec![("k".to_string(), "v".to_string())])
                .unwrap(),
        )
        .unwrap_err();

    assert_eq!(error.category(), ErrorCategory::Protocol);
    assert_eq!(error.code(), Some(573));
}

#[test]
fn a_terminal_failure_message_that_is_not_utf8_is_substituted() {
    let mut vm = Invocation::new().running();

    vm.write_output_failure(TerminalFailure::new(402, b"refus\xe9".to_vec(), vec![]).unwrap())
        .unwrap();
    vm.end().unwrap();

    let frames = output(&mut vm);
    assert_eq!(
        single::<OutputCommandMessage>(&frames).result,
        Some(output_command_message::Result::Failure(failure(
            402,
            "refus\u{fffd}",
            &[]
        )))
    );
}

#[test]
fn a_retryable_error_ends_the_attempt_with_an_error_message() {
    let mut vm = Invocation::new().running();

    vm.notify_error(500, b"caf\xe9 failed".to_vec(), b"at h\xe9".to_vec(), None);

    let error: ErrorMessage = single(&output(&mut vm));
    assert_eq!(error.code, 500);
    assert_eq!(error.message, "caf\u{fffd} failed");
    assert_eq!(error.stacktrace, "at h\u{fffd}");
    assert!(vm.take_output().is_none(), "the response is complete");
}

/// A handler's retryable error carries its status code when it is a failure
/// code other than 570 (a journal mismatch to shared-core); anything else is
/// reported as 500.
#[test]
fn a_retryable_error_carries_its_failure_code() {
    for (content_type, code, expected) in [
        (V5, 503, 503),
        (V7, 503, 503),
        (V7, 400, 400),
        (V7, 599, 599),
        (V7, 0, 500),
        (V7, 200, 500),
        (V7, 570, 500),
        (V7, 600, 500),
        (V7, -1, 500),
    ] {
        let mut vm = Invocation::new().content_type(content_type).running();

        vm.notify_error(code, b"unavailable".to_vec(), b"".to_vec(), None);

        let error: ErrorMessage = single(&output(&mut vm));
        assert_eq!(error.code, expected, "{content_type} {code}");
        assert_eq!(error.message, "unavailable");
    }
}

/// A handler's retryable error may ask for the delay before the next attempt;
/// without one Restate applies its invocation retry policy.
#[test]
fn a_retryable_error_may_ask_for_a_retry_delay() {
    for (content_type, retry_after, expected) in [
        (V5, Some(Duration::from_millis(2_000)), Some(2_000)),
        (V7, Some(Duration::from_millis(2_000)), Some(2_000)),
        (V7, Some(Duration::ZERO), Some(0)),
        (V7, None, None),
    ] {
        let mut vm = Invocation::new().content_type(content_type).running();

        vm.notify_error(500, b"rate limited".to_vec(), b"".to_vec(), retry_after);

        let error: ErrorMessage = single(&output(&mut vm));
        assert_eq!(error.message, "rate limited");
        assert_eq!(
            error.next_retry_delay, expected,
            "{content_type} {retry_after:?}"
        );
    }
}

#[test]
fn operations_after_the_end_are_refused_as_closed() {
    let mut vm = Invocation::new().running();
    vm.write_output_success(Bytes::from_static(b"1")).unwrap();
    vm.end().unwrap();

    let error = vm.sys_sleep(Duration::from_secs(1)).unwrap_err();

    assert_eq!(error.category(), ErrorCategory::Closed);
    assert_eq!(error.code(), Some(598));
}

#[test]
fn reading_the_input_before_the_start_message_is_a_protocol_violation() {
    let mut vm = Vm::new(headers(V5)).unwrap();

    let error = vm.input().unwrap_err();

    assert_eq!(error.category(), ErrorCategory::Protocol);
    assert_eq!(error.code(), Some(571));
}

#[test]
fn a_journal_that_does_not_open_with_a_start_message_is_rejected() {
    let mut vm = Vm::new(headers(V5)).unwrap();

    vm.notify_input(encode(&SleepCommandMessage {
        wake_up_time: 1,
        result_completion_id: 1,
        ..SleepCommandMessage::default()
    }));
    vm.notify_input_closed();

    let error = vm.is_ready_to_execute().unwrap_err();
    assert_eq!(error.category(), ErrorCategory::Protocol);
    assert_eq!(error.code(), Some(571));
}

#[test]
fn malformed_input_is_rejected() {
    let start_with_garbage = {
        let mut frame = encode(&StartMessage::default()).to_vec();
        frame[7] = 3;
        frame.extend([0xff, 0xff, 0xff]);
        Bytes::from(frame)
    };
    let unknown_message_type = Bytes::from_static(&[0x01, 0x23, 0, 0, 0, 0, 0, 0]);

    // shared-core reports a frame it cannot decode as an internal error (500),
    // which the core cannot tell from other internal errors by code.
    for (name, bytes) in [
        ("undecodable start message", start_with_garbage),
        ("unknown message type", unknown_message_type),
    ] {
        let mut vm = Vm::new(headers(V5)).unwrap();
        vm.notify_input(bytes);
        vm.notify_input_closed();

        let error = vm.is_ready_to_execute().unwrap_err();
        assert_eq!(
            (error.category(), error.code()),
            (ErrorCategory::Internal, Some(500)),
            "{name}"
        );
    }
}

#[test]
fn a_handler_that_diverges_from_the_journal_is_a_journal_mismatch() {
    // The journal recorded a sleep where the handler now reads state.
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
    assert!(vm.is_replaying());

    let error = vm.sys_get_state("count".to_string()).unwrap_err();

    assert_eq!(error.category(), ErrorCategory::JournalMismatch);
    assert_eq!(error.code(), Some(570));
    let reported: ErrorMessage = single(&output(&mut vm));
    assert_eq!(reported.code, 570);
}
