//! [`IdentityVerifier`] against tokens jsonwebtoken signs with restate-server's
//! test key, as restate-server signs them
//! (`crates/service-client/src/request_identity/v1.rs`).
//!
//! A test binary of its own, so nothing in the process installs a
//! jsonwebtoken crypto provider: these tests also show that the provider the
//! enabled feature selects is found without one.

use restate_php_core::IdentityVerifier;
use std::time::{SystemTime, UNIX_EPOCH};

/// restate-server's test signing key, PKCS#8 DER.
const RESTATE_TEST_PRIVATE_KEY_DER: [u8; 48] = [
    0x30, 0x2e, 0x02, 0x01, 0x00, 0x30, 0x05, 0x06, 0x03, 0x2b, 0x65, 0x70, 0x04, 0x22, 0x04, 0x20,
    0xf7, 0xbe, 0xfb, 0x86, 0x53, 0x3d, 0x00, 0xc5, 0xf3, 0x5a, 0x2d, 0xa6, 0x85, 0x3f, 0x98, 0xe1,
    0x87, 0x0b, 0x6b, 0xce, 0x01, 0x5a, 0x7d, 0x59, 0xb8, 0x82, 0x6e, 0x5b, 0xe7, 0xde, 0x75, 0xce,
];

/// Its public key as an identity key.
const RESTATE_TEST_IDENTITY_KEY: &str = "publickeyv1_AfQwmwfgEZhrWpvv8N52SHpRtZqGGaFr4AZN6qtYWSiY";

/// A valid key that did not sign anything here (sdk-shared-core's `bad_key` test).
const OTHER_IDENTITY_KEY: &str = "publickeyv1_ChjENKeMvCtRnqG2mrBK1HmPKufgFUc98K8B3ononQvp";

/// What restate-server signs for `audience` now, with its default 60 s
/// `request-identity-expiration`.
fn restate_token(audience: &str) -> String {
    let now = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap()
        .as_secs();
    let header = jsonwebtoken::Header {
        typ: Some("JWT".into()),
        kid: Some(RESTATE_TEST_IDENTITY_KEY.into()),
        alg: jsonwebtoken::Algorithm::EdDSA,
        ..Default::default()
    };
    let claims = serde_json::json!({
        "aud": audience,
        "exp": now + 60,
        "iat": now,
        "nbf": now - 60,
    });

    jsonwebtoken::encode(
        &header,
        &claims,
        &jsonwebtoken::EncodingKey::from_ed_der(&RESTATE_TEST_PRIVATE_KEY_DER),
    )
    .unwrap()
}

fn signed(token: String) -> Vec<(String, String)> {
    vec![
        ("X-Restate-Signature-Scheme".into(), "v1".into()),
        ("x-restate-jwt-v1".into(), token),
    ]
}

#[test]
fn accepts_what_restate_signs_for_the_path_after_the_mount_prefix() {
    let verifier = IdentityVerifier::new(&[OTHER_IDENTITY_KEY, RESTATE_TEST_IDENTITY_KEY]).unwrap();

    assert_eq!(
        verifier.verify(
            &signed(restate_token("/invoke/Greeter/greet")),
            "/base/invoke/Greeter/greet"
        ),
        None
    );
    assert_eq!(
        verifier.verify(&signed(restate_token("/discover")), "/discover"),
        None
    );
}

#[test]
fn rejects_a_token_with_the_reason() {
    let verifier = IdentityVerifier::new(&[OTHER_IDENTITY_KEY]).unwrap();
    let headers = signed(restate_token("/invoke/Greeter/greet"));

    assert_eq!(
        verifier.verify(&headers, "/invoke/Greeter/greet"),
        Some("invalid JWT: InvalidSignature".to_string())
    );
    assert_eq!(
        IdentityVerifier::new(&[RESTATE_TEST_IDENTITY_KEY])
            .unwrap()
            .verify(&headers, "/invoke/Greeter/other"),
        Some("invalid JWT: InvalidAudience".to_string())
    );
    assert_eq!(
        verifier.verify(&vec![], "/discover"),
        Some("missing header: x-restate-signature-scheme".to_string())
    );
}

#[test]
fn one_verifier_serves_requests_on_several_threads() {
    let verifier = IdentityVerifier::new(&[RESTATE_TEST_IDENTITY_KEY]).unwrap();

    std::thread::scope(|scope| {
        for _ in 0..4 {
            scope.spawn(|| {
                assert_eq!(
                    verifier.verify(&signed(restate_token("/discover")), "/discover"),
                    None
                );
            });
        }
    });
}
