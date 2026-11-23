# Request Identity Through sdk-shared-core

## Status

Accepted.

## Context

Endpoints configured with identity keys check the `x-restate-jwt-v1` token Restate signs every request with ([SERVING.md](../serving.md#request-identity-validation)). There are three ways to verify it. PHP JWT libraries (`firebase/php-jwt`, `lcobucci/jwt`) each failed parts of a 101-case suite pinned to sdk-shared-core's `IdentityVerifier` (`src/request_identity.rs`). A verifier of the SDK's own in PHP passed it (about 290 lines: base58 key decoding, strict base64url, `sodium_crypto_sign_verify_detached()`, the claim checks), but had to be kept in step with upstream by hand. And the extension already links sdk-shared-core, whose `request_identity` feature is the verifier itself. The TypeScript SDK uses it through its WASM bindings (features `request_identity`, `rust_crypto`), the Python SDK through PyO3 (`request_identity`, `aws_lc_rs`), both as one object built from the keys and asked per request.

The upstream verifier, as of shared-core 8.7.2: `IdentityVerifier::new(&[&str])` / `with_key()` parse `publickeyv1_` + base58 keys (`bs58`) into Ed25519 keys; `verify_identity(&impl HeaderMap, path)` reads `x-restate-signature-scheme` (`v1`, `unsigned` or anything else) and `x-restate-jwt-v1` (names case-insensitive), strips the mount prefix before `/invoke/<Service>/<handler>` or `/discover`, and decodes the token with jsonwebtoken 10 (`Validation`: EdDSA only, zero leeway, `exp`/`nbf`/`aud` validated and required). Errors carry a reason (`missing header: x-restate-signature-scheme`, `invalid JWT: ExpiredSignature`, …).

### Evaluation

**Crypto backend.** jsonwebtoken 10 does its crypto through a `CryptoProvider`. With exactly one of its `rust_crypto` / `aws_lc_rs` features enabled it selects that provider on first use (`CryptoProvider::get_default()`, a process-wide `OnceLock`, so ZTS-safe); only the crate's tests install one. Nothing has to be installed: `extension/core/tests/identity.rs` is its own test binary, installs nothing, and verifies tokens (also from four threads at once). Clean builds of the extension (`cargo build -p restate`, scratch target directory, 12 cores with other builds running, so times are rough), before and after the verifier is wired in:

| Build | Clean build | `restate.so` | Fully stripped |
| --- | --- | --- | --- |
| debug, before | 29 s | 27.0 MB | 3.9 MB |
| debug, `rust_crypto` | 27 s | 41.7 MB | 6.8 MB |
| release, before | 30 s | 1.93 MB | 1.58 MB |
| release, `rust_crypto` | 38 s | 2.99 MB | 2.51 MB |
| release, `aws_lc_rs` | 46 s | 5.11 MB | 4.54 MB |

`rust_crypto` adds about 1 MB to the release module (jsonwebtoken's provider links every algorithm it supports, RSA and ECDSA included, because its verifier factory reaches them all) and 56 crates on Linux (`ed25519-dalek`, `rsa`, `p256`, `p384`, `hmac`, `sha2`, `bs58`, …; 68 lockfile entries counting other platforms), all pure Rust. `aws_lc_rs` compiled here without cmake (aws-lc-sys 0.47 builds AWS-LC with the `cc` crate), but it compiles a C library into every build, NTS and ZTS, on every platform the extension targets, and doubles the size again. **`rust_crypto` is chosen**: pure Rust, nothing beyond the Rust toolchain the build already needs, the same choice as the TypeScript SDK.

**Surface.** The core wraps it as `restate_php_core::IdentityVerifier` (`new(&[keys])`: a malformed key is an `invalid_argument` "Invalid request identity key #<n>: <shared-core's reason>", without repeating the key; `verify(&headers, path) -> Option<String>`: `None` or shared-core's reason). The extension exposes `Restate\Extension\IdentityVerifier` (`__construct(array $keys)`, `verify(array $headers, string $path): ?string`), both under the panic guard; it keeps no per-request state, so no poisoning. Returning the reason instead of throwing keeps a rejected request off the exception path and needs no new error category.

**The 101-case suite.** The suite written for the PHP verifier was run unchanged against the native verifier, through an adapter with the PHP verifier's interface (the clock argument ignored). With its fixed clock (`NOW = 1_800_000_000`, in 2027) 53 cases failed: every accepting case, because the native verifier reads the system clock and the tokens are not yet valid, and every malformed-key case, on the message. With `NOW` set to the current time, 23 failed:

- 16 malformed-key cases: still rejected, with shared-core's message (`identity v1 jwt public keys are expected to start with publickeyv1_`, `cannot decode the public key with base58: …`, `decoded key should have length of 32, was 31`; an empty key is "length … was 0"). The non-string case is checked in PHP before the backend.
- 3 cases of the fixed token jsonwebtoken signed with restate-server's test key in 2024 (accepted at its `nbf`, `iat`, `exp`): the native verifier cannot be given a clock.
- `missing iat` and `negative iat` are accepted: jsonwebtoken's `required_spec_claims` only knows `exp`, `nbf`, `aud`, `iss` and `sub`, so shared-core's `"iat"` there has no effect, and `iat` is never parsed.
- `fractional exp` is accepted: jsonwebtoken reads `exp` and `nbf` as numbers and rounds a fraction to the nearest second.
- The ext-sodium child-process case: the native verifier does not use sodium.

Everything else matched: the `nbf`–`exp` inclusive window with zero leeway, the audience rules (string or list of strings, list members must all be strings, no object), the schemes and headers, `EdDSA` only, strict unpadded base64url (padding, standard base64, non-canonical trailing bits and whitespace in the signature are all rejected), the 64-byte signature, string and negative `exp`/`nbf`, `null` claims.

## Decision

1. **Verify request identity with sdk-shared-core's `IdentityVerifier`**, through the extension, crypto `rust_crypto`. Upstream alignment is the point: the official SDKs run this code, so PHP accepts exactly what they accept and follows changes to Restate's scheme by upgrading shared-core.
2. **Align fully; keep no PHP pre-checks.** Where the PHP verifier was stricter (`iat` required and a non-negative integer, fractional `exp`/`nbf` rejected), the SDK follows shared-core: restate-server always sends integer `exp`, `iat`, `nbf`, so neither ever rejected a token Restate signed, and keeping them would mean parsing the token in PHP again.
3. **The verifier is part of the Backend Contract**: `Backend::identityVerifier(non-empty-list<string> $keys): Backend\IdentityVerifier` (`verify(array $headers, string $path): ?string`). The endpoint asks for it once, at construction, and only when it has keys; it passes the request path (the URI's path, `/` when empty, bytes that are not UTF-8 replaced by U+FFFD as in headers: the native method takes UTF-8 strings, and a raw byte in the path would otherwise be a `TypeError` and a 500 instead of a 401) and shared-core strips the mount prefix. A malformed key is an `InvalidArgumentException` at endpoint construction. The unit tests' `FakeBackend` returns a `FakeIdentityVerifier` (accepts a fake token for its key and the exact path, records each call); the real verifier is tested in the extension suite.
4. **The 401 log record names the reason** (`restate.identity.error`, for example `invalid JWT: ExpiredSignature`), as the TypeScript SDK logs it ("Rejecting request as its JWT did not validate: …"). Message, level and the other context keys are unchanged.

## Consequences

- The SDK has no verifier of its own. The 101-case suite is in the extension suite (`tests/Extension/IdentityVerifierTest.php`, run by `composer test` through `ExtensionSuiteTest`), pinned to the upstream behaviour above: tokens are signed relative to the current second (each check retried if the second changed while it ran, so "expires exactly now" and "one second before nbf" stay exact), the 2024 token is only checked as expired, the Restate-key window cases sign fresh tokens with restate-server's test key, and rejections assert shared-core's reason where it is the point (`missing header: …`, `invalid JWT: InvalidSignature`).
- ext-sodium is not needed to serve with identity keys; the extension, which serving already needs, does it all. Nothing changes for the ingress client or the test harness, which never verified identity.
- Under PHP-FPM or `php -S` the endpoint, and so the verifier, is built per request (parsing the keys: base58 and a copy, no I/O); in a worker (FrankenPHP, RoadRunner) it is built once and reused, and PHP frees the native object with the endpoint.
- The extension is about 1 MB larger in release builds. If that matters later, an EdDSA-only `CryptoProvider` installed by the core (enabling neither provider feature) would drop RSA and ECDSA, at the price of crypto glue this project owns.
- Changes to Restate's request identity scheme arrive with shared-core upgrades; the extension suite pins what PHP accepts.
