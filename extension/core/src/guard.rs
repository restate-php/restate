//! Containing Rust panics at the boundary to the host language.
//!
//! A panic must never unwind into PHP, and an invocation whose VM panicked
//! mid-operation must not be driven any further: its state may be half
//! updated. [`catch_panic`] turns a panic into an [`Error`] with category
//! [`ErrorCategory::Internal`](crate::ErrorCategory::Internal);
//! [`Poisonable`] does the same for operations on a value and refuses every
//! later operation on it once one panicked.

use crate::error::Error;
use std::any::Any;
use std::panic::{self, AssertUnwindSafe};

/// The text of a panic payload (`panic!` with a literal or a format string),
/// or a placeholder for any other payload.
pub fn panic_message(payload: &(dyn Any + Send)) -> &str {
    if let Some(message) = payload.downcast_ref::<&'static str>() {
        message
    } else if let Some(message) = payload.downcast_ref::<String>() {
        message
    } else {
        "non-string panic payload"
    }
}

fn panic_error(message: &str) -> Error {
    Error::internal(format!("Rust panic: {message}"))
}

/// Runs `operation`, turning a panic into an internal [`Error`]
/// (`Rust panic: <message>`).
///
/// The process-wide panic hook still runs first, so the panic message and
/// location are printed to stderr as for any panic.
pub fn catch_panic<T, E: From<Error>>(operation: impl FnOnce() -> Result<T, E>) -> Result<T, E> {
    // Unwind safety: whatever `operation` captured is either dropped with it
    // or, for `Poisonable`, never used again after a panic.
    panic::catch_unwind(AssertUnwindSafe(operation))
        .unwrap_or_else(|payload| Err(panic_error(panic_message(&*payload)).into()))
}

/// A value whose operations run under [`catch_panic`], and which refuses
/// every operation after one of them panicked.
pub struct Poisonable<T> {
    value: T,
    /// The message of the panic that poisoned the value.
    poisoned: Option<String>,
}

impl<T> Poisonable<T> {
    pub fn new(value: T) -> Self {
        Self {
            value,
            poisoned: None,
        }
    }

    pub fn is_poisoned(&self) -> bool {
        self.poisoned.is_some()
    }

    /// Runs `operation` on the value. A panic becomes an internal error and
    /// poisons the value; on a poisoned value `operation` does not run and the
    /// result is an internal error naming the original panic. An `Err` that
    /// `operation` returns does not poison.
    pub fn with<R, E: From<Error>>(
        &mut self,
        operation: impl FnOnce(&mut T) -> Result<R, E>,
    ) -> Result<R, E> {
        if let Some(message) = &self.poisoned {
            return Err(Error::internal(format!(
                "Restate VM is unusable: an earlier call on it panicked (Rust panic: {message})."
            ))
            .into());
        }

        let value = &mut self.value;
        match panic::catch_unwind(AssertUnwindSafe(|| operation(value))) {
            Ok(result) => result,
            Err(payload) => {
                let message = panic_message(&*payload).to_string();
                let error = panic_error(&message);
                self.poisoned = Some(message);
                Err(error.into())
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::error::{ErrorCategory, Result};

    #[test]
    fn catch_panic_passes_results_through() {
        assert_eq!(catch_panic(|| Ok::<_, Error>(7)), Ok(7));
        assert_eq!(
            catch_panic(|| Err::<(), _>(Error::invalid_argument("bad"))),
            Err(Error::invalid_argument("bad"))
        );
    }

    #[test]
    fn catch_panic_turns_a_panic_into_an_internal_error() {
        let error = catch_panic(|| -> Result<()> { panic!("boom {}", 42) }).unwrap_err();

        assert_eq!(error.category(), ErrorCategory::Internal);
        assert_eq!(error.code(), None);
        assert_eq!(error.message(), "Rust panic: boom 42");
    }

    #[test]
    fn panic_message_handles_literal_formatted_and_other_payloads() {
        let literal = panic::catch_unwind(|| panic!("literal")).unwrap_err();
        let formatted = panic::catch_unwind(|| panic!("formatted {}", 1)).unwrap_err();
        let other = panic::catch_unwind(|| panic::panic_any(5_u8)).unwrap_err();

        assert_eq!(panic_message(&*literal), "literal");
        assert_eq!(panic_message(&*formatted), "formatted 1");
        assert_eq!(panic_message(&*other), "non-string panic payload");
    }

    #[test]
    fn a_panic_poisons_the_value_and_every_later_operation_fails() {
        let mut counter = Poisonable::new(0_u32);

        assert_eq!(counter.with(|n| -> Result<u32> { Ok(*n) }), Ok(0));

        let error = counter
            .with(|n| -> Result<()> {
                *n += 1;
                panic!("half way")
            })
            .unwrap_err();
        assert_eq!(error.category(), ErrorCategory::Internal);
        assert_eq!(error.message(), "Rust panic: half way");
        assert!(counter.is_poisoned());

        let mut ran = false;
        let error = counter
            .with(|_| -> Result<()> {
                ran = true;
                Ok(())
            })
            .unwrap_err();
        assert!(!ran, "a poisoned value is never handed out again");
        assert_eq!(error.category(), ErrorCategory::Internal);
        assert_eq!(
            error.message(),
            "Restate VM is unusable: an earlier call on it panicked (Rust panic: half way)."
        );
    }

    #[test]
    fn an_error_does_not_poison() {
        let mut value = Poisonable::new(());

        assert!(value
            .with(|_| -> Result<()> { Err(Error::internal("no")) })
            .is_err());
        assert!(!value.is_poisoned());
        assert_eq!(value.with(|_| -> Result<u8> { Ok(1) }), Ok(1));
    }

    #[test]
    fn poisoning_is_per_value() {
        let mut first = Poisonable::new(());
        let mut second = Poisonable::new(());

        let _ = first.with(|_| -> Result<()> { panic!("only the first") });

        assert!(first.is_poisoned());
        assert_eq!(second.with(|_| -> Result<u8> { Ok(2) }), Ok(2));
    }
}
