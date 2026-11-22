//! The one error type every core operation returns, and the classification of
//! `restate-sdk-shared-core` errors into categories.

use std::fmt;

/// The shared-core invocation error codes the classification depends on, as
/// plain `u16`s so they can be matched on (shared-core's own
/// `restate_sdk_shared_core::error::codes` are `InvocationErrorCode`s).
mod codes {
    use restate_sdk_shared_core::error::codes;

    pub const UNSUPPORTED_MEDIA_TYPE: u16 = codes::UNSUPPORTED_MEDIA_TYPE.code();
    pub const JOURNAL_MISMATCH: u16 = codes::JOURNAL_MISMATCH.code();
    pub const PROTOCOL_VIOLATION: u16 = codes::PROTOCOL_VIOLATION.code();
    pub const AWAITING_TWO_ASYNC_RESULTS: u16 = codes::AWAITING_TWO_ASYNC_RESULTS.code();
    pub const UNSUPPORTED_FEATURE: u16 = codes::UNSUPPORTED_FEATURE.code();
    pub const CLOSED: u16 = codes::CLOSED.code();
    pub const SUSPENDED: u16 = codes::SUSPENDED.code();
}

/// What kind of failure an [`Error`] is, independent of its message.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ErrorCategory {
    /// The caller broke the core's contract: an argument out of range, a
    /// malformed structured argument, an unknown handle. Kept apart from
    /// [`ErrorCategory::Internal`] on purpose: it points at a bug on the PHP
    /// side of the bridge, not inside shared-core or the core.
    InvalidArgument,
    /// The invocation suspended; the attempt must stop without output.
    Suspended,
    /// Restate sent something the protocol does not allow, or the protocol
    /// version or content type is not supported.
    Protocol,
    /// The handler did something different from what the journal recorded.
    JournalMismatch,
    /// The VM is already closed (ended, failed or suspended) and refuses
    /// further operations.
    Closed,
    /// Anything else: a shared-core internal error, or a result the bridge
    /// did not expect.
    Internal,
}

impl ErrorCategory {
    pub fn as_str(self) -> &'static str {
        match self {
            ErrorCategory::InvalidArgument => "invalid_argument",
            ErrorCategory::Suspended => "suspended",
            ErrorCategory::Protocol => "protocol",
            ErrorCategory::JournalMismatch => "journal_mismatch",
            ErrorCategory::Closed => "closed",
            ErrorCategory::Internal => "internal",
        }
    }

    /// Classifies a shared-core invocation error code.
    pub fn from_vm_code(code: u16) -> Self {
        match code {
            codes::SUSPENDED => ErrorCategory::Suspended,
            codes::CLOSED => ErrorCategory::Closed,
            codes::JOURNAL_MISMATCH => ErrorCategory::JournalMismatch,
            codes::UNSUPPORTED_MEDIA_TYPE
            | codes::PROTOCOL_VIOLATION
            | codes::AWAITING_TWO_ASYNC_RESULTS
            | codes::UNSUPPORTED_FEATURE => ErrorCategory::Protocol,
            _ => ErrorCategory::Internal,
        }
    }
}

impl fmt::Display for ErrorCategory {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(self.as_str())
    }
}

/// A failed core operation.
///
/// `message` is the text the PHP surface shows today. For errors raised by
/// shared-core it is shared-core's rendering (`(code) message`, plus the
/// related command when there is one), and `code` carries the code on its own.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Error {
    category: ErrorCategory,
    code: Option<u16>,
    message: String,
}

pub type Result<T> = std::result::Result<T, Error>;

impl Error {
    pub fn new(category: ErrorCategory, message: impl Into<String>) -> Self {
        Self {
            category,
            code: None,
            message: message.into(),
        }
    }

    pub fn invalid_argument(message: impl Into<String>) -> Self {
        Self::new(ErrorCategory::InvalidArgument, message)
    }

    pub fn internal(message: impl Into<String>) -> Self {
        Self::new(ErrorCategory::Internal, message)
    }

    pub fn category(&self) -> ErrorCategory {
        self.category
    }

    /// The shared-core error code, when the error came from shared-core.
    pub fn code(&self) -> Option<u16> {
        self.code
    }

    pub fn message(&self) -> &str {
        &self.message
    }
}

impl From<restate_sdk_shared_core::Error> for Error {
    fn from(error: restate_sdk_shared_core::Error) -> Self {
        Self {
            category: ErrorCategory::from_vm_code(error.code()),
            code: Some(error.code()),
            message: error.to_string(),
        }
    }
}

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.message)
    }
}

impl std::error::Error for Error {}

#[cfg(test)]
mod tests {
    use super::*;

    fn vm_error(code: u16, message: &'static str) -> Error {
        Error::from(restate_sdk_shared_core::Error::new(code, message))
    }

    #[test]
    fn shared_core_suspension_is_suspended() {
        let error = vm_error(599, "Suspended invocation");

        assert_eq!(error.category(), ErrorCategory::Suspended);
        assert_eq!(error.code(), Some(599));
        assert_eq!(error.message(), "(599) Suspended invocation");
    }

    #[test]
    fn shared_core_codes_map_to_categories() {
        let cases = [
            (415, ErrorCategory::Protocol),
            (570, ErrorCategory::JournalMismatch),
            (571, ErrorCategory::Protocol),
            (572, ErrorCategory::Protocol),
            (573, ErrorCategory::Protocol),
            (598, ErrorCategory::Closed),
            (599, ErrorCategory::Suspended),
            (500, ErrorCategory::Internal),
            (400, ErrorCategory::Internal),
        ];

        for (code, category) in cases {
            assert_eq!(vm_error(code, "x").category(), category, "code {code}");
        }
    }

    #[test]
    fn shared_core_error_keeps_its_rendering_as_message() {
        let error = Error::from(
            restate_sdk_shared_core::Error::internal("boom").with_stacktrace("at handler"),
        );

        assert_eq!(error.to_string(), "(500) boom\nStacktrace: at handler");
        assert_eq!(error.code(), Some(500));
    }

    #[test]
    fn bridge_errors_have_no_code() {
        let error = Error::invalid_argument("bad");

        assert_eq!(error.category(), ErrorCategory::InvalidArgument);
        assert_eq!(error.code(), None);
        assert_eq!(error.to_string(), "bad");
    }

    #[test]
    fn categories_have_stable_names() {
        assert_eq!(ErrorCategory::JournalMismatch.as_str(), "journal_mismatch");
        assert_eq!(
            ErrorCategory::InvalidArgument.to_string(),
            "invalid_argument"
        );
    }
}
