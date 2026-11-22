//! Tests that drive a real shared-core VM through the core's [`Vm`]: they
//! feed it protocol frames as Restate sends them and assert on the frames it
//! writes and the results it returns.
//!
//! [`Vm`]: restate_php_core::Vm

mod awaiting;
mod combinators;
mod communication;
mod invocation;
mod logs;
mod pause;
mod promise;
mod protocol;
mod run;
mod scope;
mod signals;
mod state;
mod support;
mod timers;
mod v7;
