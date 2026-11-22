//! Building the protocol input a VM receives and reading back what it writes.

use crate::protocol::{
    self, signal_notification_message, FailureMetadata, Future, InputCommandMessage, Message,
    SignalNotificationMessage, StartMessage, SuspensionMessageV6, Value, Void,
};
use restate_php_core::{
    Bytes, Handle, LogLevel, NotificationResult, Progress, RetryableFailure, TerminalFailure, Vm,
};

pub const V5: &str = "application/vnd.restate.invocation.v5";
pub const V6: &str = "application/vnd.restate.invocation.v6";
/// What Restate 1.8 negotiates by default.
pub const V7: &str = "application/vnd.restate.invocation.v7";
/// Supported by shared-core 8.7.2; no released Restate speaks it yet.
pub const V8: &str = "application/vnd.restate.invocation.v8";

/// The frame flag a v7 VM sets on a `ProposeRunCompletionMessage` to ask
/// Restate for a `ProposeRunCompletionAckMessage` instead of the stored
/// result (`REQUESTED_ACK_MASK` in shared-core's `src/service_protocol/header.rs`).
pub const REQUESTED_ACK: u16 = 0x8000;

/// The invocation id every test invocation has (start message `id` and
/// `debug_id`).
pub const INVOCATION_ID: &str = "inv-1";

pub fn headers(content_type: &str) -> Vec<(String, String)> {
    vec![("content-type".to_string(), content_type.to_string())]
}

/// One protocol frame: message type, no flags, 32-bit length, payload.
pub fn encode<M: Message>(message: &M) -> Bytes {
    let length = message.encoded_len();
    let mut frame = Vec::with_capacity(8 + length);
    frame.extend(M::TYPE.to_be_bytes());
    frame.extend([0, 0]);
    frame.extend(u32::try_from(length).unwrap().to_be_bytes());
    message.encode(&mut frame).unwrap();
    Bytes::from(frame)
}

/// A frame the VM wrote.
#[derive(Debug, Clone)]
pub struct Frame {
    pub message_type: u16,
    pub flags: u16,
    pub payload: Bytes,
}

impl Frame {
    pub fn decode<M: Message>(&self) -> M {
        assert_eq!(
            self.message_type,
            M::TYPE,
            "expected a frame of type {:#06x}, got {:#06x}",
            M::TYPE,
            self.message_type
        );
        M::decode(self.payload.clone()).unwrap()
    }
}

/// Everything the VM has written since the last call: until the response is
/// complete, or until nothing more is buffered.
pub fn output(vm: &mut Vm) -> Vec<Frame> {
    let mut bytes = Vec::new();
    while let Some(chunk) = vm.take_output().filter(|chunk| !chunk.is_empty()) {
        bytes.extend(chunk);
    }

    let mut frames = Vec::new();
    let mut rest = &bytes[..];
    while !rest.is_empty() {
        assert!(rest.len() >= 8, "truncated frame header in VM output");
        let length = u32::from_be_bytes([rest[4], rest[5], rest[6], rest[7]]) as usize;
        frames.push(Frame {
            message_type: u16::from_be_bytes([rest[0], rest[1]]),
            flags: u16::from_be_bytes([rest[2], rest[3]]),
            payload: Bytes::copy_from_slice(&rest[8..8 + length]),
        });
        rest = &rest[8 + length..];
    }
    frames
}

pub fn message_types(frames: &[Frame]) -> Vec<u16> {
    frames.iter().map(|frame| frame.message_type).collect()
}

/// The only frame of `M` in `frames`.
pub fn single<M: Message>(frames: &[Frame]) -> M {
    let matching: Vec<_> = frames
        .iter()
        .filter(|frame| frame.message_type == M::TYPE)
        .collect();
    assert_eq!(
        matching.len(),
        1,
        "expected one frame of type {:#06x} in {:x?}",
        M::TYPE,
        message_types(frames)
    );
    matching[0].decode()
}

pub fn value(content: &[u8]) -> Value {
    Value {
        content: Bytes::copy_from_slice(content),
    }
}

pub fn failure(code: u32, message: &str, metadata: &[(&str, &str)]) -> protocol::Failure {
    protocol::Failure {
        code,
        message: message.to_string(),
        metadata: metadata
            .iter()
            .map(|(key, value)| FailureMetadata {
                key: key.to_string(),
                value: value.to_string(),
            })
            .collect(),
    }
}

pub fn terminal_failure(code: i64, message: &str) -> TerminalFailure {
    TerminalFailure::new(code, message, vec![]).unwrap()
}

/// A retryable run failure, as `RetryableError` hands it over.
pub fn retryable(
    code: i64,
    message: impl Into<Vec<u8>>,
    metadata: &[(&str, &str)],
) -> RetryableFailure {
    RetryableFailure::new(
        code,
        message,
        metadata
            .iter()
            .map(|(key, value)| (key.to_string(), value.to_string()))
            .collect(),
    )
}

/// The built-in cancel signal (signal id 1), as Restate sends it when the
/// invocation is cancelled.
pub fn cancel_signal() -> Bytes {
    encode(&SignalNotificationMessage {
        signal_id: Some(signal_notification_message::SignalId::Idx(1)),
        result: Some(signal_notification_message::Result::Void(Void {})),
    })
}

impl Default for Invocation {
    fn default() -> Self {
        Self::new()
    }
}

/// The request Restate sends for one invocation: a start message, the input
/// command, and the rest of the journal.
pub struct Invocation {
    content_type: &'static str,
    start: StartMessage,
    input: InputCommandMessage,
    journal: Vec<Bytes>,
    close_input: bool,
    log_level: Option<LogLevel>,
}

impl Invocation {
    pub fn new() -> Self {
        Self {
            content_type: V5,
            start: StartMessage {
                id: Bytes::from_static(INVOCATION_ID.as_bytes()),
                debug_id: INVOCATION_ID.to_string(),
                random_seed: 42,
                ..StartMessage::default()
            },
            input: InputCommandMessage {
                value: Some(value(b"\"Mary\"")),
                ..InputCommandMessage::default()
            },
            journal: vec![],
            close_input: true,
            log_level: None,
        }
    }

    pub fn content_type(mut self, content_type: &'static str) -> Self {
        self.content_type = content_type;
        self
    }

    pub fn start(mut self, update: impl FnOnce(&mut StartMessage)) -> Self {
        update(&mut self.start);
        self
    }

    pub fn input(mut self, update: impl FnOnce(&mut InputCommandMessage)) -> Self {
        update(&mut self.input);
        self
    }

    /// An object or workflow invocation for `key`.
    pub fn key(self, key: &str) -> Self {
        let key = key.to_string();
        self.start(|start| start.key = key)
    }

    /// Restate sends no state up front; every read goes to Restate.
    pub fn lazy_state(self) -> Self {
        self.start(|start| start.partial_state = true)
    }

    /// Restate sends this state entry up front (eager state).
    pub fn state(self, key: &str, value: &[u8]) -> Self {
        let entry = protocol::start_message::StateEntry {
            key: Bytes::copy_from_slice(key.as_bytes()),
            value: Bytes::copy_from_slice(value),
        };
        self.start(|start| start.state_map.push(entry))
    }

    /// A journal entry after the input command, replayed in order.
    pub fn entry<M: Message>(mut self, message: M) -> Self {
        self.journal.push(encode(&message));
        self
    }

    /// Keeps the request body open after the journal, as in a streaming
    /// exchange, so completions can arrive while the handler runs.
    pub fn open(mut self) -> Self {
        self.close_input = false;
        self
    }

    /// The VM captures shared-core's log events at or above `level`, from its
    /// creation on (see [`Vm::with_log_level`]).
    pub fn log_level(mut self, level: LogLevel) -> Self {
        self.log_level = Some(level);
        self
    }

    /// The VM after it received the request, ready to execute but before the
    /// handler read its input.
    pub fn received(self) -> Vm {
        let mut start = self.start;
        start.known_entries = 1 + u32::try_from(self.journal.len()).unwrap();

        let mut vm = Vm::with_log_level(headers(self.content_type), self.log_level).unwrap();
        let (input, journal, close_input) = (self.input, self.journal, self.close_input);
        vm.logged(|vm| {
            vm.notify_input(encode(&start));
            vm.notify_input(encode(&input));
            for entry in journal {
                vm.notify_input(entry);
            }
            if close_input {
                vm.notify_input_closed();
            }
            assert!(vm.is_ready_to_execute().unwrap());
        });
        vm
    }

    /// The VM with the handler running: it read its input.
    pub fn running(self) -> Vm {
        let mut vm = self.received();
        vm.logged(|vm| vm.input().unwrap());
        vm
    }
}

/// Awaits `handle`, expecting it to be completed already, and takes its
/// result.
pub fn completed(vm: &mut Vm, handle: Handle) -> NotificationResult {
    assert_eq!(vm.do_await(handle).unwrap(), Progress::Completed(()));
    vm.take_notification_result(handle).unwrap()
}

/// Awaits `handle` while its completion has not arrived, delivers
/// `completion`, and awaits it again.
pub fn completed_after(vm: &mut Vm, handle: Handle, completion: Bytes) -> NotificationResult {
    assert_eq!(vm.do_await(handle).unwrap(), Progress::ReadFromInput);
    vm.notify_input(completion);
    completed(vm, handle)
}

/// The notifications a v7 suspension's (or awaiting-on message's) future
/// tree waits for, flattened into the v5/v6 suspension shape, in tree order.
pub fn flatten(future: &Future) -> SuspensionMessageV6 {
    let mut flat = SuspensionMessageV6 {
        waiting_completions: future.waiting_completions.clone(),
        waiting_signals: future.waiting_signals.clone(),
        waiting_named_signals: future.waiting_named_signals.clone(),
    };
    for nested in &future.nested_futures {
        let nested = flatten(nested);
        flat.waiting_completions.extend(nested.waiting_completions);
        flat.waiting_signals.extend(nested.waiting_signals);
        flat.waiting_named_signals
            .extend(nested.waiting_named_signals);
    }
    flat
}

/// The built-in cancel signal's id.
pub const CANCEL_SIGNAL_ID: u32 = 1;
