//! Request identity verification: shared-core's `IdentityVerifier` (its
//! `request_identity` feature), the verifier the official TypeScript and
//! Python SDKs use. It checks the `x-restate-jwt-v1` token Restate signs every
//! request with (EdDSA, `aud` = the request path after any mount prefix,
//! valid from `nbf` through `exp` with zero leeway) against the configured
//! `publickeyv1_…` keys.
//!
//! The crypto behind jsonwebtoken is its pure-Rust `rust_crypto` provider
//! (shared-core's `rust_crypto` feature), as in the TypeScript SDK's WASM
//! bindings. With exactly one provider feature enabled, jsonwebtoken selects
//! it on first use (a process-wide `OnceLock`), so nothing has to be
//! installed; the tests check that.

use crate::{Error, Result};
use restate_sdk_shared_core::IdentityVerifier as SharedCoreIdentityVerifier;

/// Verifies requests against a fixed set of identity keys. It keeps no
/// per-request state: build it once per endpoint and share it.
pub struct IdentityVerifier {
    inner: SharedCoreIdentityVerifier,
}

impl IdentityVerifier {
    /// A verifier for these keys (Restate's `publickeyv1_<base58>` format).
    /// Without keys it accepts every request. A malformed key is an
    /// [`crate::ErrorCategory::InvalidArgument`] naming its position (from 1)
    /// and shared-core's reason; the key itself is not repeated.
    pub fn new<K: AsRef<str>>(keys: &[K]) -> Result<Self> {
        let mut inner = SharedCoreIdentityVerifier::default();
        for (index, key) in keys.iter().enumerate() {
            inner = inner.with_key(key.as_ref()).map_err(|error| {
                Error::invalid_argument(format!(
                    "Invalid request identity key #{}: {error}",
                    index + 1
                ))
            })?;
        }
        Ok(Self { inner })
    }

    /// `None` when the request carries a valid identity for `path` (the
    /// request path without the query; shared-core strips the mount prefix
    /// before `/invoke/<Service>/<handler>` or `/discover`), otherwise
    /// shared-core's reason. Header names match case-insensitively.
    pub fn verify(&self, headers: &Vec<(String, String)>, path: &str) -> Option<String> {
        self.inner
            .verify_identity(headers, path)
            .err()
            .map(|error| error.to_string())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::ErrorCategory;

    #[test]
    fn a_verifier_without_keys_accepts_every_request() {
        let verifier = IdentityVerifier::new::<&str>(&[]).unwrap();

        assert_eq!(verifier.verify(&vec![], "/invoke/Greeter/greet"), None);
    }

    #[test]
    fn a_malformed_key_is_an_invalid_argument_naming_its_position() {
        let error = match IdentityVerifier::new(&[
            "publickeyv1_ChjENKeMvCtRnqG2mrBK1HmPKufgFUc98K8B3ononQvp",
            "publickeyv1_0",
        ]) {
            Ok(_) => panic!("the second key is malformed"),
            Err(error) => error,
        };

        assert_eq!(error.category(), ErrorCategory::InvalidArgument);
        assert!(
            error.message().starts_with(
                "Invalid request identity key #2: cannot decode the public key with base58"
            ),
            "{}",
            error.message()
        );
    }
}
