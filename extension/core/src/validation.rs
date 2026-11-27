//! Checks on values the PHP side hands to the core. Each rule lives here once;
//! the glue crates convert their raw arguments with these functions instead of
//! repeating the checks.

use crate::error::{Error, Result};
use std::ops::RangeInclusive;
use std::time::Duration;

/// Status codes a terminal failure written by the SDK may carry: the 4xx and
/// 5xx ranges, as `TerminalError` enforces on the PHP side.
pub const TERMINAL_FAILURE_CODES: RangeInclusive<i64> = 400..=599;

/// Validates the status code of a terminal failure the SDK writes: handler
/// output, a run's terminal result, a promise rejection, an awakeable
/// rejection.
pub fn terminal_failure_code(code: i64) -> Result<u16> {
    if !TERMINAL_FAILURE_CODES.contains(&code) {
        return Err(Error::invalid_argument(
            "Terminal failure code must be between 400 and 599.",
        ));
    }

    // In range, so it fits.
    Ok(code as u16)
}

/// The code a retryable failure is reported with: a handler's (the attempt's
/// error code) or a run's (which becomes the run's terminal failure code once
/// its retry policy is exhausted). `code` when it is a failure code
/// ([`TERMINAL_FAILURE_CODES`]) other than 570, else 500. Any exception may
/// fail an attempt (PHP's default exception code is 0), so this never fails.
/// 570 is excluded because shared-core treats it as a journal mismatch (on v7
/// it rewrites the error behaviour of 570 errors).
pub fn retryable_failure_code(code: i64) -> u16 {
    const DEFAULT: u16 = 500;
    const JOURNAL_MISMATCH: u16 = restate_sdk_shared_core::error::codes::JOURNAL_MISMATCH.code();

    if code != i64::from(JOURNAL_MISMATCH) && TERMINAL_FAILURE_CODES.contains(&code) {
        // In range, so it fits.
        code as u16
    } else {
        DEFAULT
    }
}

/// Text the PHP side hands over as bytes (failure messages, stack traces):
/// PHP strings need not be UTF-8, the protocol needs it. Valid UTF-8 is kept
/// as is (without copying); anything else has its invalid sequences replaced
/// by U+FFFD, so a Latin-1 message still reaches Restate instead of failing
/// the error path.
pub fn lossy_text(bytes: impl Into<Vec<u8>>) -> String {
    String::from_utf8(bytes.into())
        .unwrap_or_else(|error| String::from_utf8_lossy(error.as_bytes()).into_owned())
}

/// Converts a notification handle from PHP's integer.
pub fn handle(value: i64) -> Result<u32> {
    u32::try_from(value).map_err(|_| Error::invalid_argument("Handle is outside the u32 range."))
}

/// Converts the handle of the run whose completion is proposed. Handle `0`
/// never names a run.
pub fn run_completion_handle(value: i64) -> Result<u32> {
    match u32::try_from(value) {
        Ok(handle) if handle != 0 => Ok(handle),
        _ => Err(Error::invalid_argument(
            "Run completion handles must be valid uint32 values.",
        )),
    }
}

/// Converts a non-negative millisecond count; `what` names it in the error,
/// e.g. `"Sleep delay"`.
pub fn non_negative_millis(value: i64, what: &str) -> Result<Duration> {
    u64::try_from(value)
        .map(Duration::from_millis)
        .map_err(|_| {
            Error::invalid_argument(format!(
                "{what} must be greater than or equal to 0 milliseconds."
            ))
        })
}

/// An empty idempotency key means "none".
pub fn idempotency_key(key: Option<String>) -> Option<String> {
    key.filter(|value| !value.is_empty())
}

/// The scope and limit key of a call or send target: each, when given, must be
/// non-empty, and a limit key needs a scope. Their format is the
/// PHP side's concern (`Restate\Target::scoped()`); Restate enforces it too.
pub fn scope_and_limit_key(
    scope: Option<String>,
    limit_key: Option<String>,
) -> Result<(Option<String>, Option<String>)> {
    let scope = scope_value(scope)?;
    if limit_key.as_deref() == Some("") {
        return Err(Error::invalid_argument("Limit key must not be empty."));
    }
    if limit_key.is_some() && scope.is_none() {
        return Err(Error::invalid_argument("A limit key needs a scope."));
    }

    Ok((scope, limit_key))
}

/// The scope of an attach target: non-empty when given.
pub fn scope_value(scope: Option<String>) -> Result<Option<String>> {
    if scope.as_deref() == Some("") {
        return Err(Error::invalid_argument("Scope must not be empty."));
    }

    Ok(scope)
}

/// A signal name: any non-empty string.
pub fn signal_name(name: String) -> Result<String> {
    if name.is_empty() {
        return Err(Error::invalid_argument("Signal name must not be empty."));
    }

    Ok(name)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::error::ErrorCategory;

    #[test]
    fn lossy_text_keeps_utf8_and_substitutes_everything_else() {
        assert_eq!(lossy_text("caf\u{e9} \u{2603}"), "caf\u{e9} \u{2603}");
        assert_eq!(lossy_text(b"caf\xe9".to_vec()), "caf\u{fffd}");
        assert_eq!(lossy_text(b"\xff\xfe ok".as_slice()), "\u{fffd}\u{fffd} ok");
        assert_eq!(lossy_text(Vec::new()), "");
    }

    #[test]
    fn terminal_failure_code_accepts_the_4xx_and_5xx_range() {
        assert_eq!(terminal_failure_code(400).unwrap(), 400);
        assert_eq!(terminal_failure_code(409).unwrap(), 409);
        assert_eq!(terminal_failure_code(599).unwrap(), 599);
    }

    #[test]
    fn terminal_failure_code_rejects_everything_else() {
        for code in [i64::MIN, -1, 0, 200, 399, 600, 65_535, 65_536, i64::MAX] {
            let error = terminal_failure_code(code).unwrap_err();

            assert_eq!(error.category(), ErrorCategory::InvalidArgument);
            assert_eq!(
                error.message(),
                "Terminal failure code must be between 400 and 599."
            );
        }
    }

    #[test]
    fn handle_accepts_the_u32_range() {
        assert_eq!(handle(0).unwrap(), 0);
        assert_eq!(handle(u32::MAX as i64).unwrap(), u32::MAX);
    }

    #[test]
    fn handle_rejects_values_outside_u32() {
        assert!(handle(-1).is_err());
        assert!(handle(u32::MAX as i64 + 1).is_err());
    }

    #[test]
    fn run_completion_handle_rejects_zero_and_out_of_range() {
        assert_eq!(run_completion_handle(1).unwrap(), 1);
        for value in [0, -1, u32::MAX as i64 + 1] {
            assert_eq!(
                run_completion_handle(value).unwrap_err().message(),
                "Run completion handles must be valid uint32 values."
            );
        }
    }

    #[test]
    fn non_negative_millis_converts_and_names_the_value() {
        assert_eq!(
            non_negative_millis(250, "Sleep delay").unwrap(),
            Duration::from_millis(250)
        );
        assert_eq!(
            non_negative_millis(0, "Sleep delay").unwrap(),
            Duration::ZERO
        );
        assert_eq!(
            non_negative_millis(-1, "Sleep delay")
                .unwrap_err()
                .message(),
            "Sleep delay must be greater than or equal to 0 milliseconds."
        );
    }

    #[test]
    fn signal_name_must_not_be_empty() {
        assert_eq!(signal_name("approved".to_string()).unwrap(), "approved");
        let error = signal_name(String::new()).unwrap_err();
        assert_eq!(error.category(), ErrorCategory::InvalidArgument);
        assert_eq!(error.message(), "Signal name must not be empty.");
    }

    #[test]
    fn scope_and_limit_key_must_be_non_empty_and_a_limit_key_needs_a_scope() {
        let some = |value: &str| Some(value.to_string());
        assert_eq!(scope_and_limit_key(None, None).unwrap(), (None, None));
        assert_eq!(
            scope_and_limit_key(some("tenant-1"), None).unwrap(),
            (some("tenant-1"), None)
        );
        assert_eq!(
            scope_and_limit_key(some("tenant-1"), some("user/42")).unwrap(),
            (some("tenant-1"), some("user/42"))
        );

        for (scope, limit_key, message) in [
            (some(""), None, "Scope must not be empty."),
            (some("tenant-1"), some(""), "Limit key must not be empty."),
            (None, some("user"), "A limit key needs a scope."),
        ] {
            let error = scope_and_limit_key(scope, limit_key).unwrap_err();
            assert_eq!(error.category(), ErrorCategory::InvalidArgument);
            assert_eq!(error.message(), message);
        }
    }

    #[test]
    fn empty_idempotency_key_is_none() {
        assert_eq!(idempotency_key(None), None);
        assert_eq!(idempotency_key(Some(String::new())), None);
        assert_eq!(
            idempotency_key(Some("idem-1".to_string())),
            Some("idem-1".to_string())
        );
    }
}
