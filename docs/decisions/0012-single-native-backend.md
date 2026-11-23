# Single Native Backend

## Status

Accepted.

## Context

A Restate SDK has to implement the service invocation protocol: replaying the journal, detecting journal mismatches, suspending, retrying runs, and following every protocol version the server speaks. The official SDKs share one implementation of it, the Rust crate [`restate-sdk-shared-core`](https://crates.io/crates/restate-sdk-shared-core): TypeScript through WASM, Python through PyO3, Java through its foreign function interface. A PHP SDK can implement the protocol in PHP, load shared-core through PHP's FFI, or embed shared-core in a PHP extension.

## Decision

The SDK has exactly one backend: the PHP extension (`extension/extension`, module `restate`, built with [`ext-php-rs`](https://github.com/extphprs/ext-php-rs)), which embeds `restate-sdk-shared-core` through the core crate `extension/core`. All Restate traffic goes through it. Without the extension, or with one that speaks another contract version, building an endpoint throws `BackendUnavailableException`. The internal Backend Contract ([Backend Architecture](../internals/architecture.md)) has this one production implementation, and fakes for the unit tests.

### Why not a pure-PHP implementation

A pure-PHP backend was prototyped to offer an install path with Composer alone. It never became a protocol implementation:

- It was fitted to captured traffic: it accepted a handful of fixed request shapes and could encode only the outputs those produced.
- Virtual Object state lived in a local file, not in Restate's journal and state map, which only worked against one endpoint process.
- It spoke one protocol version, capped request bodies at 4 KiB, and could not mix `ctx.run` and a sleep in one invocation.
- It leaked into the shared code path: the endpoint special-cased its errors and its VM.

Matching shared-core (replay, journal mismatch detection, suspension, retries, every protocol version) would mean re-implementing shared-core in PHP and following every upstream release, and a second implementation implied a support level it never had. It was removed. Only its frame encoders survive, as test support for tests that build protocol frames by hand (`tests/Support/Protocol/FrameCodec.php`, not shipped).

### Why not FFI

A backend that loaded the same Rust core through PHP's FFI came first, and the extension replaced it once it passed the same integration and upstream suites, on every PHP build the SDK supports (below). Both run the same core; the extension installs and loads like any PHP extension (prebuilt binaries through PIE, [Prebuilt Extension Binaries](0030-prebuilt-extension-binaries.md)), while FFI needs `ext-ffi`, which production PHP builds often lack or restrict (`ffi.enable=preload` by default), plus a shared library shipped beside the PHP code. Keeping both would have meant two bridges to one core to test and release.

## Evidence

**Thread safety.** The extension builds against thread-safe (ZTS) PHP and serves the whole integration suite under FrankenPHP, many PHP threads in one process, on PHP 8.4 and 8.5, and in worker mode on 8.4 (`composer test:frankenphp`, `composer test:frankenphp-worker`, [TESTING.md](../../TESTING.md)). Each attempt's VM is owned by its own PHP object: there is no process-wide registry or lock, so concurrent requests do not serialize on the extension, and one request cannot disable another.

**PHP versions.** The extension supports PHP 8.4, 8.5 and 8.6, NTS and ZTS, with `ext-php-rs` pinned to `=0.16.5` (`extension/extension/Cargo.toml`). A new PHP minor needs an `ext-php-rs` release that supports it, possibly a code change for its breaking changes, and a rebuild of every prebuilt binary before users can upgrade. That lag has been 79 days for PHP 8.4 (`ext-php-rs` 0.13.0) and 16 days for 8.5 (0.15.2); For 8.6 there was none: 0.16.4 accepted the Zend API of the release candidates (up to 20260924) but not 8.6.0's (20261112), which 0.16.5 accepted three days before 8.6.0's release. 0.16.5 needs Rust 1.95 (`parking_lot` 0.13). Older `ext-php-rs` releases refuse newer PHP outright (0.14.2 cannot build against 8.5). The supported minors are listed in every package's `php` constraint ([Composer Metadata](0026-composer-metadata.md)).

**Panics.** A Rust panic must not take the PHP process down. Since 0.16, `ext-php-rs` turns a panic that reaches PHP into an `\Error`; before that a panic aborted the process (under `php -S` it killed the whole server). The extension does not rely on that: it builds with `panic = "unwind"`, and its guard (`restate_php_core::guard`) catches every panic with `catch_unwind` and throws the extension's own `ExtensionException` with a category. A panic inside a VM poisons that VM only, so the invocation fails while the process and every other VM keep working. The debug-only probe (`extension/extension/src/probe.rs`) and `tests/Unit/Sdk/Backend/ExtensionPanicTest.php` pin this.

## Consequences

- There is no install path without the extension: serving needs it. The ingress client and the test harness do not ([Package Split](0029-package-split.md)).
- Protocol behaviour comes from shared-core and follows its releases ([Shared Core](0014-shared-core.md)).
- Supporting a new PHP minor means an `ext-php-rs` that supports it, then adding the minor to the package constraints, the CI matrix and the prebuilt binaries in one change.
- Tests that need protocol frames build them with the test-side `FrameCodec`.
