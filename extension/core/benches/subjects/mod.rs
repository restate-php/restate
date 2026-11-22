//! What both benchmarks measure: one request each, built from the VM tests'
//! frames (`tests/vm/protocol.rs`, `tests/vm/support.rs`) as Restate 1.8 sends
//! it (protocol v7, the whole body, then the body closed), and driven through
//! the core's [`Vm`] the way the extension drives it: every operation inside
//! [`Vm::logged`], with the captured log records drained after it, as the
//! PHP side's `CoreLogForwarder` does after every VM call.
//!
//! `vm.rs` (criterion) times them; `vm_instructions.rs` (gungraun) counts
//! their instructions under callgrind. A subject asserts what the VM answers,
//! so a benchmark that stops doing the work fails instead of getting faster.

use crate::protocol::{
    run_completion_notification_message, InputCommandMessage, RunCommandMessage,
    RunCompletionNotificationMessage, SleepCommandMessage, SleepCompletionNotificationMessage,
    StartMessage, Void,
};
use crate::support::{encode, headers, value, INVOCATION_ID, V7};
use restate_php_core::{AwaitTree, Bytes, IdentityVerifier, LogLevel, Progress, Vm};
use std::hint::black_box;

/// The log level the endpoint captures shared-core's events at when it has a
/// logger (`Endpoint::create()`'s default `coreLogLevel`).
pub const LOGGED: Option<LogLevel> = Some(LogLevel::Info);

/// One request body, frame by frame, and the log level its VM captures at.
pub struct Request {
    frames: Vec<Bytes>,
    log_level: Option<LogLevel>,
}

impl Request {
    /// A start message (with `known_entries` counted), the input command and
    /// then `journal`. `start` adjusts the start message: key, state, ….
    fn new(input: &[u8], journal: Vec<Bytes>, start: impl FnOnce(&mut StartMessage)) -> Self {
        let mut message = StartMessage {
            id: Bytes::from_static(INVOCATION_ID.as_bytes()),
            debug_id: INVOCATION_ID.to_string(),
            random_seed: 42,
            known_entries: 1 + u32::try_from(journal.len()).unwrap(),
            ..StartMessage::default()
        };
        start(&mut message);

        let mut frames = vec![
            encode(&message),
            encode(&InputCommandMessage {
                value: Some(value(input)),
                ..InputCommandMessage::default()
            }),
        ];
        frames.extend(journal);
        Self {
            frames,
            log_level: None,
        }
    }

    /// The VM captures shared-core's log events at `level`.
    pub fn log_level(mut self, level: Option<LogLevel>) -> Self {
        self.log_level = level;
        self
    }

    /// A first attempt: start message and input, nothing journaled.
    pub fn start() -> Self {
        Self::new(b"\"Mary\"", vec![], |_| {})
    }

    /// The last attempt of `runs` sequential runs: every run command and its
    /// completion is in the journal, so the handler replays them all.
    pub fn replay_runs(runs: u32) -> Self {
        let mut journal = Vec::new();
        for step in 1..=runs {
            journal.push(encode(&RunCommandMessage {
                result_completion_id: step,
                name: format!("step-{step}"),
            }));
            journal.push(encode(&RunCompletionNotificationMessage {
                completion_id: step,
                result: Some(run_completion_notification_message::Result::Value(value(
                    step.to_string().as_bytes(),
                ))),
            }));
        }
        Self::new(runs.to_string().as_bytes(), journal, |_| {})
    }

    /// A virtual object call with `keys` state entries sent up front.
    pub fn eager_state(keys: u32) -> Self {
        Self::new(keys.to_string().as_bytes(), vec![], |start| {
            start.key = "workload".to_string();
            for key in 0..keys {
                start
                    .state_map
                    .push(crate::protocol::start_message::StateEntry {
                        key: Bytes::from(format!("k{key}")),
                        value: Bytes::from_static(b"1"),
                    });
            }
        })
    }

    /// `sleeps` sleeps that all fired: each command and its completion is in
    /// the journal.
    pub fn fired_sleeps(sleeps: u32) -> Self {
        let mut journal = Vec::new();
        for step in 1..=sleeps {
            journal.push(encode(&SleepCommandMessage {
                wake_up_time: 1,
                result_completion_id: step,
                ..SleepCommandMessage::default()
            }));
            journal.push(encode(&SleepCompletionNotificationMessage {
                completion_id: step,
                void: Some(Void {}),
            }));
        }
        Self::new(b"null", journal, |_| {})
    }

    /// What the endpoint does with the request before the handler runs:
    /// create the VM, hand it the body, close it, and read the input.
    pub fn receive(&self) -> Vm {
        let mut vm = Vm::with_log_level(headers(V7), self.log_level).unwrap();
        call(&mut vm, |vm| {
            for frame in &self.frames {
                vm.notify_input(frame.clone());
            }
            vm.notify_input_closed();
            assert!(vm.is_ready_to_execute().unwrap());
        });
        black_box(call(&mut vm, |vm| vm.input().unwrap()));
        vm
    }
}

/// One VM operation as the extension makes it.
pub fn call<T>(vm: &mut Vm, operation: impl FnOnce(&mut Vm) -> T) -> T {
    let result = vm.logged(operation);
    black_box(vm.take_logs());
    result
}

/// Writes `output` as the handler's result, ends the invocation and drains
/// the response; returns its length.
pub fn complete(vm: &mut Vm, output: Bytes) -> usize {
    call(vm, |vm| vm.write_output_success(output).unwrap());
    call(vm, |vm| vm.end().unwrap());
    drain(vm)
}

/// Takes the whole response; returns its length.
pub fn drain(vm: &mut Vm) -> usize {
    let mut length = 0;
    while let Some(chunk) = call(vm, Vm::take_output).filter(|chunk| !chunk.is_empty()) {
        length += black_box(chunk).len();
    }
    length
}

/// A first attempt up to the handler: VM, body, input.
pub fn vm_start(request: &Request) -> Vm {
    request.receive()
}

/// The handler of [`Request::replay_runs`]: replays each run and completes.
pub fn replay_runs(request: &Request, runs: u32) -> usize {
    let mut vm = request.receive();
    let mut sum = 0u64;
    for step in 1..=runs {
        let handle = call(&mut vm, |vm| vm.sys_run(format!("step-{step}")).unwrap());
        assert_eq!(
            call(&mut vm, |vm| vm.do_await(handle).unwrap()),
            Progress::Completed(())
        );
        let result = call(&mut vm, |vm| vm.take_run_result(handle).unwrap());
        sum += std::str::from_utf8(&result)
            .unwrap()
            .parse::<u64>()
            .unwrap();
    }
    assert_eq!(sum, u64::from(runs) * (u64::from(runs) + 1) / 2);
    complete(&mut vm, Bytes::from(sum.to_string()))
}

/// The handler of [`Request::eager_state`]: reads every key, then clears one
/// and lists the keys, which filters through the cleared-state bookkeeping.
pub fn eager_state(request: &Request, keys: u32) -> usize {
    let mut vm = request.receive();
    for key in 0..keys {
        let handle = call(&mut vm, |vm| vm.sys_get_state(format!("k{key}")).unwrap());
        assert_eq!(
            call(&mut vm, |vm| vm.do_await(handle).unwrap()),
            Progress::Completed(())
        );
        black_box(call(&mut vm, |vm| {
            vm.take_notification_result(handle).unwrap()
        }));
    }
    call(&mut vm, |vm| vm.clear_state("k0".to_string()).unwrap());
    let handle = call(&mut vm, |vm| vm.sys_get_state_keys().unwrap());
    assert_eq!(
        call(&mut vm, |vm| vm.do_await(handle).unwrap()),
        Progress::Completed(())
    );
    match call(&mut vm, |vm| vm.take_notification_result(handle).unwrap()) {
        restate_php_core::NotificationResult::StateKeys(listed) => {
            assert_eq!(listed.len(), keys as usize - 1)
        }
        other => panic!("expected state keys, got {other:?}"),
    }
    complete(&mut vm, Bytes::from(keys.to_string()))
}

/// A first attempt that returns `output` (1 MiB in the benchmarks).
pub fn large_output(request: &Request, output: &Bytes) -> usize {
    let mut vm = request.receive();
    let length = complete(&mut vm, output.clone());
    assert!(length > output.len());
    length
}

/// The handler of [`Request::fired_sleeps`]: awaits all sleeps at once
/// (`all_succeeded_or_first_failed`, PHP's `DurableFuture::all()`), then
/// takes each result.
pub fn await_tree(request: &Request, sleeps: u32) -> usize {
    let mut vm = request.receive();
    let handles: Vec<_> = (0..sleeps)
        .map(|_| {
            call(&mut vm, |vm| {
                vm.sys_sleep(std::time::Duration::from_secs(60)).unwrap()
            })
        })
        .collect();
    let tree = AwaitTree::AllSucceededOrFirstFailed(
        handles.iter().copied().map(AwaitTree::Single).collect(),
    );
    // The await reports every sleep completed, so nothing is asked per handle.
    assert!(matches!(
        call(&mut vm, |vm| vm.do_await_tree(tree).unwrap()),
        Progress::Completed(completed) if completed == handles
    ));
    for handle in handles {
        black_box(call(&mut vm, |vm| {
            vm.take_notification_result(handle).unwrap()
        }));
    }
    complete(&mut vm, Bytes::from_static(b"null"))
}

/// A first attempt that creates `awakeables` awakeables and awaits them all:
/// nothing can complete in request/response mode, so the VM writes a
/// suspension with the v7 await tree. Returns the response length.
pub fn suspend(request: &Request, awakeables: u32) -> usize {
    let mut vm = request.receive();
    let handles: Vec<_> = (0..awakeables)
        .map(|_| call(&mut vm, |vm| vm.sys_awakeable().unwrap().handle))
        .collect();
    let tree = if handles.len() == 1 {
        AwaitTree::Single(handles[0])
    } else {
        AwaitTree::AllSucceededOrFirstFailed(handles.into_iter().map(AwaitTree::Single).collect())
    };
    assert_eq!(
        call(&mut vm, |vm| vm.do_await_tree(tree).unwrap()),
        Progress::Suspended
    );
    drain(&mut vm)
}

/// restate-server's test signing key, PKCS#8 DER (as in `tests/identity.rs`).
const RESTATE_TEST_PRIVATE_KEY_DER: [u8; 48] = [
    0x30, 0x2e, 0x02, 0x01, 0x00, 0x30, 0x05, 0x06, 0x03, 0x2b, 0x65, 0x70, 0x04, 0x22, 0x04, 0x20,
    0xf7, 0xbe, 0xfb, 0x86, 0x53, 0x3d, 0x00, 0xc5, 0xf3, 0x5a, 0x2d, 0xa6, 0x85, 0x3f, 0x98, 0xe1,
    0x87, 0x0b, 0x6b, 0xce, 0x01, 0x5a, 0x7d, 0x59, 0xb8, 0x82, 0x6e, 0x5b, 0xe7, 0xde, 0x75, 0xce,
];

/// Its public key as an identity key.
pub const IDENTITY_KEY: &str = "publickeyv1_AfQwmwfgEZhrWpvv8N52SHpRtZqGGaFr4AZN6qtYWSiY";

/// The path every signed request below is for.
pub const IDENTITY_PATH: &str = "/invoke/Greeter/greet";

/// A signed request's headers, as restate-server signs them for `audience`:
/// valid from 2023 on, and until 2100 when `unexpired`, else expired since
/// 2023. Fixed times, so the token, and what verifying it costs, is the same
/// on every run.
pub fn signed_headers(audience: &str, unexpired: bool) -> Vec<(String, String)> {
    let issued: i64 = 1_700_000_000;
    let header = jsonwebtoken::Header {
        typ: Some("JWT".into()),
        kid: Some(IDENTITY_KEY.into()),
        alg: jsonwebtoken::Algorithm::EdDSA,
        ..Default::default()
    };
    let claims = serde_json::json!({
        "aud": audience,
        "exp": if unexpired { 4_102_444_800 } else { issued + 60 },
        "iat": issued,
        "nbf": issued,
    });
    let token = jsonwebtoken::encode(
        &header,
        &claims,
        &jsonwebtoken::EncodingKey::from_ed_der(&RESTATE_TEST_PRIVATE_KEY_DER),
    )
    .unwrap();

    vec![
        ("content-type".into(), V7.into()),
        ("x-restate-signature-scheme".into(), "v1".into()),
        ("x-restate-jwt-v1".into(), token),
    ]
}

/// The endpoint's verifier, built once per endpoint (per request under FPM).
pub fn identity_verifier() -> IdentityVerifier {
    IdentityVerifier::new(&[IDENTITY_KEY]).unwrap()
}

/// Verifies a request; `accepted` says whether it should pass.
pub fn verify(verifier: &IdentityVerifier, headers: &Vec<(String, String)>, accepted: bool) {
    let error = verifier.verify(headers, IDENTITY_PATH);
    assert_eq!(error.is_none(), accepted, "{error:?}");
    black_box(error);
}
