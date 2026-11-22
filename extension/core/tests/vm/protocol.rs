//! The Restate service protocol messages, as `prost` types.
//!
//! shared-core keeps its generated protocol module private, so
//! `protocol/dev.restate.service.protocol.rs` is a verbatim copy of
//! `src/service_protocol/generated/dev.restate.service.protocol.rs` from
//! `restate-sdk-shared-core` 8.7.2 (MIT), the version the core pins. Replace
//! it with the file of the new version whenever the pin changes, so the tests
//! speak the protocol the core does.

#![allow(dead_code, clippy::all)]

include!("protocol/dev.restate.service.protocol.rs");

/// The suspension message as protocol v5 and v6 define it: the flat sets of
/// notifications the invocation waits for. From v7 on the VM writes
/// [`SuspensionMessage`], whose `awaiting_on` is a [`Future`] tree;
/// shared-core flattens that tree into this shape on older versions
/// (`SuspensionMessageV6` in its `src/service_protocol/messages.rs`).
#[derive(Clone, PartialEq, ::prost::Message)]
pub struct SuspensionMessageV6 {
    #[prost(uint32, repeated, tag = "1")]
    pub waiting_completions: ::prost::alloc::vec::Vec<u32>,
    #[prost(uint32, repeated, tag = "2")]
    pub waiting_signals: ::prost::alloc::vec::Vec<u32>,
    #[prost(string, repeated, tag = "3")]
    pub waiting_named_signals: ::prost::alloc::vec::Vec<::prost::alloc::string::String>,
}

/// The message type in each frame header, per protocol message.
pub trait Message: prost::Message + Default {
    const TYPE: u16;
}

macro_rules! message_types {
    ($($message:ident = $type:literal,)*) => {
        $(impl Message for $message {
            const TYPE: u16 = $type;
        })*
    };
}

message_types! {
    StartMessage = 0x0000,
    SuspensionMessage = 0x0001,
    SuspensionMessageV6 = 0x0001,
    ErrorMessage = 0x0002,
    EndMessage = 0x0003,
    ProposeRunCompletionMessage = 0x0005,
    AwaitingOnMessage = 0x0006,
    ProposeRunCompletionAckMessage = 0x0007,
    GetStateEphemeralCommandMessage = 0x0200,
    GetStateEphemeralNotificationMessage = 0x0300,
    InputCommandMessage = 0x0400,
    OutputCommandMessage = 0x0401,
    GetLazyStateCommandMessage = 0x0402,
    GetLazyStateCompletionNotificationMessage = 0x8002,
    SetStateCommandMessage = 0x0403,
    ClearStateCommandMessage = 0x0404,
    ClearAllStateCommandMessage = 0x0405,
    GetLazyStateKeysCommandMessage = 0x0406,
    GetLazyStateKeysCompletionNotificationMessage = 0x8006,
    GetEagerStateCommandMessage = 0x0407,
    GetEagerStateKeysCommandMessage = 0x0408,
    GetPromiseCommandMessage = 0x0409,
    GetPromiseCompletionNotificationMessage = 0x8009,
    PeekPromiseCommandMessage = 0x040A,
    PeekPromiseCompletionNotificationMessage = 0x800A,
    CompletePromiseCommandMessage = 0x040B,
    CompletePromiseCompletionNotificationMessage = 0x800B,
    SleepCommandMessage = 0x040C,
    SleepCompletionNotificationMessage = 0x800C,
    CallCommandMessage = 0x040D,
    CallCompletionNotificationMessage = 0x800D,
    CallInvocationIdCompletionNotificationMessage = 0x800E,
    OneWayCallCommandMessage = 0x040E,
    SendSignalCommandMessage = 0x0410,
    RunCommandMessage = 0x0411,
    RunCompletionNotificationMessage = 0x8011,
    AttachInvocationCommandMessage = 0x0412,
    AttachInvocationCompletionNotificationMessage = 0x8012,
    GetInvocationOutputCommandMessage = 0x0413,
    GetInvocationOutputCompletionNotificationMessage = 0x8013,
    CompleteAwakeableCommandMessage = 0x0414,
    SignalNotificationMessage = 0xFBFF,
}
