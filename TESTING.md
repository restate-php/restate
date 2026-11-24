# Testing

Run everything inside the dev shell (`devenv shell`, or prefix commands with `devenv shell --`). It provides nixpkgs' default PHP (8.5 now), Composer, the Rust toolchain pinned in `extension/rust-toolchain.toml`, `php-config`, libclang and the `BINDGEN_EXTRA_CLANG_ARGS` the extension build needs, Java 25 for the upstream suite, and `actionlint` and `shellcheck`. Every task is a Composer script (`composer run --list`). For another supported minor, start the shell with `devenv -O languages.php.version:string 8.4 shell` (or `8.6`): the extension then builds against that PHP (rebuild after switching).

## Before every commit

```bash
composer check            # cs + analyse + workflow/shell lint + unit tests + packages + cargo fmt/clippy/test
composer test:integration # real Restate server, real extension
```

`composer check` runs, in order and stopping at the first failure:

| Step | Command |
| --- | --- |
| Code style | `composer cs` (`php-cs-fixer fix --dry-run --diff`; fix with `composer cs:fix`) |
| Static analysis | `composer analyse` (PHPStan level 8 with `phpstan-strict-rules` and `phpstan-phpunit` over `packages/`, `bin/check-packages.php` and `tests/`, plus the public API boundary rule in `tests/PHPStan/`, see [Public API Boundary](docs/decisions/0016-public-api-boundary.md)) |
| Workflows and scripts | `composer check:scripts` (`actionlint` on `.github/workflows/`, `shellcheck` on `bin/*.sh` and `docker/*/*.sh`) |
| Unit tests | `composer test` (builds the extension, then runs the `unit` suite) |
| Release split | `composer test:release` (the `release` group, which `composer test` leaves out: `bin/split.sh` on a throwaway monorepo, pushing to local bare repositories; a release, a re-run, the next release, a push to `main`, a release of a commit later pushes passed, and the runs that must stop; see [Release](docs/decisions/0031-release.md)) |
| Packages | `composer check:packages` (`bin/check-packages.php` with the extension loaded: `composer validate --strict` per package, each package's code against its `composer.json` with `shipmonk/composer-dependency-analyser`, the root `composer.json` against the packages, and nothing declared in the extension's namespace `Restate\Extension\`; see [Package Split](docs/decisions/0029-package-split.md)) |
| Rust | `composer check:extension` (`cargo fmt --check`, `cargo clippy --all-targets -- -D warnings`, `cargo test` for every crate) |

`composer test:integration` needs a container runtime and is not part of `composer check`. Docs-only changes may skip it.

## Unit tests

```bash
composer test
vendor/bin/phpunit tests/Unit/Ingress                     # one package's unit tests
vendor/bin/phpunit tests/Unit/Sdk/EndpointRoutingTest.php   # one file; `extension` group tests need the extension already built
```

The `unit` suite (`tests/Unit` and the tests of the test support in `tests/Support`) is the default for plain `vendor/bin/phpunit`. Unit tests are grouped by the package they test, `tests/Unit/<Package>/` (`Common`, `Sdk`, `Ingress`, `Testing`); `tests/Unit/Packages/`, `tests/Unit/PHPStan/` and `tests/Unit/Release/` (`bin/split.sh`) test the repository's own tooling. `tests/Extension/` is the SDK's extension suite, `tests/Integration/` runs the packages together, `tests/Support/` is shared (`Repository::ROOT` is the repository root, `Packages` lists each package's directory and namespace). Tests in the `extension` group use the real extension from `extension/target/debug/` and fail, not skip, when it has not been built; `composer test` builds it first (`composer build:extension`). The extension cannot be loaded into a running process, and the rest of the suite runs without it, so its tests start a child `php -d extension=…` (`tests/Support/ExtensionProcess.php`; `RESTATE_PHP_EXTENSION_PATH` overrides the path).

`extension/restate.stub.php` describes the Extension Surface for PHPStan and IDEs. `tests/Unit/Sdk/Backend/ExtensionStubTest.php` compares what reflection sees of the loaded extension with what it sees of the stub (classes, functions, methods, parameter names and types, return types, properties, constants) and fails on any difference: when you change the extension's surface, update the stub, and bump the Contract Version if the change is incompatible and the current extension version has been released ([Bumping versions](RELEASING.md#bumping-versions)). A stub function marked `@restate-optional` exists only in some builds: `Restate\Extension\diagnostics()`, with the `diagnostics` cargo feature ([Soak suite](#soak-suite)). The test accepts a build without it and compares a build with it like everything else (`RESTATE_PHP_EXTENSION_PATH=… vendor/bin/phpunit tests/Unit/Sdk/Backend/ExtensionStubTest.php`).

`tests/Unit/Sdk/Backend/ExtensionPanicTest.php` checks the extension's panic guard with the panic probe (`extension/extension/src/probe.rs`): in a debug build the string `__restate_php_panic_probe__` makes the extension panic while converting an array argument, or inside `Vm::sysRun()`. Release builds compile the probe out, so this test needs the debug build (the default; it fails against a `RESTATE_PHP_EXTENSION_PATH` that points at a release build). Rust panics print their message to the child's stderr, which the test ignores. Next to it, the bailout probe `__restate_php_bailout_probe__` raises a PHP fatal error (`E_ERROR`) at the same points, so PHP longjmps across the Rust frames of the conversion; the soak suite's `BailoutSoakTest` uses it.

The rest of the suite drives the SDK through the internal `Backend` / `Backend\Invocation` / `InvocationOperations` interfaces with two test doubles from `tests/Support/`, and PHPUnit mocks of `Backend\Invocation` where a test checks what the endpoint does with one request:

- `ScriptedInvocation` implements `InvocationOperations` (and `ReplayStateProvider`) as a scripted journal that speaks the typed contract. Every operation that returns a handle allocates the next one (from 1); its result arrives when the operation is issued, after the results that arrived before it, and an await processes arrived results in that order (a tree await one at a time until the tree resolves, so the order decides races). Unscripted, it behaves like the VM: state reads and writes use `$state`, promises `$promises`, sleeps complete at once, runs execute and complete with what is proposed (a retryable proposal reports `$retryableRunStatus`; a pause proposal throws `$runPauseFailure` when set, as the VM does below protocol v7), and calls, sends, attaches and awakeables never complete; an await that cannot complete reports `$whenPending` (suspended). `onNext()` / `onEvery()` script an operation's interruptions (`AwaitState`s) and result (`NotificationResult`), `complete()` / `interrupt()` script a handle directly. It records every operation with its typed arguments (`operations()`, `calls()`, `handles()`, `awaited()`, `taken()`) and fails loudly on misuse (taking a result that was not awaited, proposing to a handle that is not a run, scripting an operation that has no handle). `ScriptedInvocation::unused()` fails on any operation, for a handler context whose test never reaches the backend. It is also a whole `Backend\Invocation` (always ready, input from `$input`, the output calls only recorded), so `Endpoint::create(new FakeBackend($scripted), …)` runs a handler, its runs and suspensions included, on the scripted journal.
- `FakeBackend` implements `Backend`: it hands out a given invocation, throws a given exception, or fails when the test should never create one, and reports the protocol range of `FakeProtocolVersions`.

`tests/Unit/Sdk/BackendUnavailableTest.php` builds endpoints in a child process without the extension and checks the `BackendUnavailableException`.

`tests/Unit/Sdk/EndpointStrayOutputTest.php` checks what the endpoint does with output PHP prints outside the protocol response ([Stray Output](docs/decisions/0032-stray-output.md)). The cases that depend on ini settings, or on output PHP sent before the script ran, run `tests/Unit/Sdk/Fixtures/stray-output-endpoint.php` (an endpoint on the fake backend) in a child PHP, under the CLI or under `php -S` on a free `127.0.0.1` port, for example to reproduce the `post_max_size` startup warning.

The [ingress client](docs/decisions/0022-ingress-client.md) is tested against `tests/Unit/Ingress/RecordingHttpClient.php`, a PSR-18 client that records requests and answers from a queue. `tests/Unit/Ingress/IngressClientWithoutExtensionTest.php` runs every ingress operation in a `php -n` child process (no php.ini, so no extension) and fails if it loads a type of any package other than `restate/ingress` and `restate/common`; `tests/Unit/Packages/PackagesLoadWithTheirRequirementsTest.php` loads every type of every package in a `php -n` child that refuses classes from packages the package does not require. The integration test `IngressClientTest` uses Guzzle (a dev dependency) as the real PSR-18 client.

`tests/Unit/Sdk/Backend/ExtensionSuiteTest.php` runs the `extension` suite (`tests/Extension`) in a child `php -d extension=…` process and fails with its output when any of it fails. That suite needs the extension loaded in-process and drives the SDK's extension backend (`Restate\Internal\Backend\Extension`) against a real VM, with protocol frames built by `FrameCodec`; where the VM's output is deterministic it is compared byte for byte with what `FrameCodec` encodes. Without the extension its tests fail, they never skip. To run it directly, with PHPUnit's own output:

```bash
php -d extension=extension/target/debug/restate.so vendor/bin/phpunit --testsuite extension
```

`tests/Extension/VmLifetimeTest.php` is the in-process VM lifetime check, about 2 seconds on a debug build. One endpoint serves 10,000 requests in the suite's process, as a long-lived worker does. The requests are a fixed mix of success, suspension, a journal mismatch that throws through the extension, a terminal error and, every 250th, the panic probe (so it needs the debug build, like `ExtensionPanicTest`). The logger keeps the newest 256 records with their exceptions, and the test sets `zend.exception_ignore_args=0`, PHP's compiled default. A `Backend` decorator records a `WeakReference` to each request's `Restate\Extension\Vm` (by reflection on `Extension\Invocation::$vm`). After every request it asserts that the Vm is gone. It also asserts that `memory_get_usage()` after `gc_collect_cycles()` grows by less than 16 KiB between request 1,000 and request 10,000 (measured: 0). The same file checks `Vm::free()`: a freed or poisoned Vm refuses every call, and freeing twice is harmless.

**Backend contract.** The contract is typed (`AwaitResult`, `NotificationResult`, …; see [Backend Architecture](docs/internals/architecture.md#internal-seam-the-backend-contract)), and the extension backend builds those values from the arrays the extension returns (`Extension\SurfaceValues`). The unit tests script typed values only (`ScriptedInvocation`, and `AwaitResult::suspended()`, `new InvocationInput(…)`, … in mocks), so they cannot return anything the typed contract cannot express. `tests/Support/BackendShapes.php` defines the extension's arrays in one place: every result variant of `doAwait`, `doAwaitTree`, `takeNotificationResult` and `proposeRunRetryable`, and the shapes of `sysAwakeable`, `responseHead`, `input` and `protocol_versions`. `tests/Extension/BackendContractTest.php` drives the real extension (`Restate\Extension\Vm`) into every variant listed there, checks each (failing on a missing, extra or mistyped field or an unknown variant), converts it with `SurfaceValues` to the typed value of the same variant, and fails when a listed variant has no scenario or when a case of `AwaitState`, `NotificationKind` or `RunCompletionStatus` is not listed. So every typed variant a unit test can script is one the extension produces; to change a shape or add a variant, change `BackendShapes`, the typed value and the contract test together.


PHPUnit fails the run on warnings, risky tests, and deprecations (`phpunit.xml.dist`). Shared test doubles live in `tests/Support/` (namespace `Restate\Tests\Support`) so every test file also passes on its own. `tests/Support/Protocol/FrameCodec.php` builds Restate protocol frames by hand for tests that need protocol input or expected output.

Tests write only below the system temp directory and remove what they create. Test services that keep counters on disk (`test-services/services/greeter.php`, `failing.php`) use `RESTATE_PHP_TEST_STATE_DIR`, which the unit and integration suites point at a temporary directory; without it they fall back to `var/` in the repository.

## Static analysis

```bash
composer analyse
```

PHPStan needs more than PHP's default 128M; `composer analyse` passes `--memory-limit=512M`. Running `vendor/bin/phpstan analyse` directly needs the same flag.

## Rust code

The two crates under `extension/` form one Cargo workspace (`extension/Cargo.toml`) with one lockfile (`extension/Cargo.lock`) and one output directory, `extension/target/`:

| Crate | Output |
| --- | --- |
| `core` (package `restate-php-core`) | library: the single implementation of every VM operation (plain Rust, no PHP dependency); the extension is glue over it |
| `extension` (package `restate`) | `extension/target/debug/restate.so` (PHP extension, module `restate`, built with `ext-php-rs`; `bin/build-extension.sh` copies Cargo's `librestate.so` to that name) |

```bash
composer build:extension  # PHP extension; needs php-config and the dev shell's BINDGEN_EXTRA_CLANG_ARGS
composer check:extension     # fmt, clippy, tests for the whole workspace
```

**Toolchain.** `extension/rust-toolchain.toml` pins the Rust toolchain (version, `clippy`, `rustfmt`). It is the only place the version is written: rustup reads it in the images built from `docker/Dockerfile` (they install rustup without a default toolchain and then `rustup toolchain install` from that file), and the dev shell and the extension's flake build the same toolchain from it (`languages.rust.toolchainFile` in `devenv.nix` and `fromRustupToolchainFile` in `extension/flake.nix`, through one rust-overlay, pinned by `extension/flake.lock`; the editor's `rust-analyzer` stays the nixpkgs one). To bump Rust, change the file, then re-enter the dev shell and rebuild the images. Outside both, a rustup installation picks up the file when Cargo runs in `extension/`.

**Building the extension.** `bin/build-extension.sh` (`composer build:extension`) prints the path of the module it built on stdout:

| Command | Builds against | Output |
| --- | --- | --- |
| `composer build:extension` | `PHP_CONFIG`, else `php-config` on `PATH` (in the dev shell, that of nixpkgs' default PHP, NTS, 8.5 now) | `extension/target/debug/restate.so` |
| `composer build:extension -- --release` | the same | `extension/target/release/restate.so` |
| `bash bin/build-extension.sh [--release] --php-config <path>` | the PHP that `<path>` describes | `extension/target/php<minor>-<nts\|zts>/{debug,release}/restate.so`, e.g. `extension/target/php8.4-zts/release/…` |

A module loads only into the PHP it was built for: the same minor version and the same thread safety (NTS or ZTS). `--php-config` gives each PHP its own target directory, so NTS and ZTS builds (or two PHP minors) are produced side by side and never overwrite each other or the default build. `ext-php-rs` reads the include paths from `php-config` and the thread safety and Zend API version from `php -i`, so the script points `PHP_CONFIG` and `PHP` at the same PHP (`php-config --php-binary`). The default build sets neither, so it shares Cargo's build cache with `composer check:extension`. The ZTS image stage in `docker/Dockerfile` builds with `--php-config`. Arguments after `--` go to `cargo build`.

**Nix builds.** The extension has a flake, `extension/flake.nix` ([Extension Flake](docs/decisions/0037-extension-flake.md)). It builds the extension with [ext-php-rs.nix](https://github.com/sagikazarmark/ext-php-rs.nix), whose README explains how. The dev shell takes the flake as an input (`ext-restate` in `devenv.yaml`), follows its nixpkgs and exposes its builds as `outputs`. Nix reads the flake from git, so it sees only tracked files: `git add` a new file under `extension/` before building.

| Command | Builds | Output |
| --- | --- | --- |
| `devenv build outputs.extension` | a release module against the dev shell's PHP, through the flake's overlay (another minor with `devenv -O languages.php.version:string 8.4 build …`), checked by loading it into that PHP; `php.withExtensions` accepts it | `/nix/store/…-php-restate-<version>/lib/php/extensions/restate.so` |
| `composer build:pie` (`bin/build-pie.sh [<dir>]`, `devenv build outputs.pie`) | the 24 prebuilt binaries of a release, cross-compiled for Linux from any platform with zig, each checked for its shared libraries and glibc floor | `dist/php_restate-<version>_php<minor>-<arch>-linux-<glibc\|musl>-<nts\|zts>.tgz`, each with its `.sha256` file, and `SHA256SUMS`; prints the archives' paths |
| `devenv build outputs.checks` (`nix flake check ./extension`) | the flake's checks, on Linux and macOS, without Docker: `extension/tests/*.phpt` with PHP 8.4, 8.5 and 8.6 and with a ZTS PHP (FrankenPHP's), the extension loaded through the overlay; and an invocation per request (`extension/tests/server/`) in FrankenPHP's workers (across worker restarts, 50 requests at once on its threads) and in PHP-FPM's children (children replaced after 5 requests, a reload). `outputs.checks.frankenphp` for one | — |
| `nix run ./extension#load-test -- [--arch <x86_64\|arm64>] <archives> extension/tests` | loads the Linux archives of this CPU (or another, emulated) into PHP's official images and runs `extension/tests/*.phpt` there with the module alone (ext-php-rs.nix's `loadPieArchives`, Docker). PHP 8.6's official images are ZTS only, so it skips the 8.6 NTS archives | — |

The prebuilt binaries are built against headers that Nix configures for each target from nixpkgs' PHP source ([Prebuilt Extension Binaries](docs/decisions/0030-prebuilt-extension-binaries.md)). Nothing here can load a module built for another CPU or for Linux on macOS; `.github/workflows/extension.yaml` loads each one on a runner of its CPU. A cross-compiled debug build (`buildType = "debug"`, see ext-php-rs.nix's README) passes the extension suite and `ExtensionPanicTest` in the official images, e.g. `docker run -v "$PWD:/src" -v <dir with restate.so>:/check -w /src php:8.5-zts-alpine php -d extension=/check/restate.so vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Extension`.

The release profile (`extension/Cargo.toml`) is `opt-level = 3`, thin LTO, one codegen unit, `strip = "debuginfo"`, and `panic = "unwind"`, which must stay: the extension's panic guard catches panics with `catch_unwind` and turns them into `ExtensionException`s. A release module is about 3 MB (2.1–2.5 MB as a prebuilt binary), a debug one about 42 MB. Release builds compile the panic probe out, so `ExtensionPanicTest` needs the debug build; the integration suite runs against either:

```bash
composer build:extension -- --release
RESTATE_PHP_EXTENSION_PATH=$PWD/extension/target/release/restate.so vendor/bin/phpunit --testsuite integration
```

Run Cargo from `extension/` (the scripts do): Cargo reads `extension/.cargo/config.toml` from the working directory. Because the workspace includes the extension, `cargo clippy` compiles it too, so workspace-wide commands need the same environment as `composer build:extension`; outside the dev shell bindgen fails with `'stdlib.h' file not found`. The extension crate has no Rust tests of its own (`test = false`): its logic and tests live in the core crate (`extension/core`), which builds and tests without PHP (`cargo test -p restate-php-core`).

Besides unit tests next to the code, `restate_php_core` has one integration test target, `extension/core/tests/vm/`, that drives a real shared-core VM through the core's `Vm`: it feeds protocol frames as Restate sends them and asserts on the frames the VM writes and the results it returns, one module per area (invocation, run, state, promise, communication, timers, awaiting and cancellation). Frames are built and decoded with `prost` types: shared-core keeps its generated protocol module private, so `tests/vm/protocol/dev.restate.service.protocol.rs` is a verbatim copy of it from the pinned shared-core version. Replace it with the new version's file whenever the pin changes. `tests/log_capture.rs` is a target of its own because the log capture's level is process-wide (`restate_php_core::logs`). It checks that a capture more verbose than all earlier ones re-enables their disabled callsites. Its ignored test measures VM creation cost with 0 and with 5,000 live capturing VMs, and fails if it grows threefold: `cargo test -p restate-php-core --test log_capture -- --ignored --nocapture` (about 1 µs per VM either way).

## Integration tests

The `integration` PHPUnit suite (`tests/Integration` and `examples/testing`) starts one real Restate server and one real PHP endpoint per run, registers the endpoint, and drives every scenario over HTTP through the Restate ingress. It runs on the public [test harness](docs/develop/testing.md) (`Restate\Testing\`, see [Test Harness](docs/decisions/0023-test-harness.md)), so the suite is also that harness's regression test.

```bash
composer test:integration  # PHP extension under php -S
composer test:integration:process # the same with Restate as a process: no Docker (macOS too)
devenv --profile frankenphp test  # the behaviour tests against FrankenPHP and Restate run by devenv
composer test:frankenphp   # extension, thread-safe build, under FrankenPHP
composer test:frankenphp-worker # the same in FrankenPHP worker mode, then the worker suite
```

`composer test:integration` builds the extension first (`composer build:extension`, debug), then runs `phpunit --testsuite integration`. Restate and the endpoint start in about a second once the Restate image is present; the whole suite takes about 50 seconds, most of it in tests that wait for retries, timers and delayed sends. To test another build, such as a release build, set `RESTATE_PHP_EXTENSION_PATH` (see [Rust code](#rust-code)).

How it works:

- `tests/Integration/Support/IntegrationTestCase.php` uses the harness's PHPUnit trait `InteractsWithRestate`; `IntegrationEndpoint.php` next to it says what to serve. Every test class shares one environment (`RestateTestEnvironment::shared()`).
- By default the endpoint is a `PhpCliServer`: `php -d extension=… -S` with `PHP_CLI_SERVER_WORKERS=8` and the harness's ini defaults (`display_errors=0`, `log_errors=1`, `enable_post_data_reading=0`: PHP's messages go to the endpoint log, never into a response), serving `tests/Integration/Support/endpoint.php`. That router builds the endpoints of `IntegrationEndpoints.php` for each request: the services listed once in `services.php` (shared with any other script that serves them, such as a worker loop) and their configuration, plus the `/state-preload` endpoint, all behind the fault injector (see [Failure injection](#failure-injection)). The harness finds the extension in `RESTATE_PHP_EXTENSION_PATH`, else `extension/target/debug/` (then `release/`). Before starting the server, it builds an endpoint once in a throwaway PHP process with the same settings, so an extension that does not load or does not match the package fails the run with its own error.
- With `RESTATE_PHP_TEST_SERVER=frankenphp` the endpoint is an `EndpointContainer` instead (see [FrankenPHP](#frankenphp-thread-safe-build)), serving the same router script from the repository mounted at `/app`. Its port is published on the bind address, so Restate reaches it the same way. The PHP-FPM values (`nginx-fpm`, `nginx-fpm-h2c`, `caddy-fpm`) do the same with the PHP-FPM image ([Serving](#serving-large-bodies-php-fpm-and-front-door-hazards)).
- Restate runs in a container by default (`RESTATE_PHP_TEST_RESTATE=container`), with plain `docker run` (no Compose, no `socat`, no Testcontainers), `--add-host host.docker.internal:host-gateway`, and its ports published on `127.0.0.1` ports the harness chose (free ones, retried with new ones if another process takes one before the container binds it; explicit mappings survive a restart). It works unchanged with Docker and with rootless podman (through `docker`, else `podman`, on `PATH`). Without either the suite fails; it never skips. The harness waits for Restate's health endpoints and for an admin SQL query to succeed (the partition is running) before it registers the endpoint.
- With `RESTATE_PHP_TEST_RESTATE=process` (`composer test:integration:process`), Restate is a `RestateProcess` instead: `restate-server` from the dev shell (nix-restate's `restate-bin`, at the version of `RestateContainer::DEFAULT_IMAGE`) as a process of the harness, with a fresh data directory, three free loopback ports and the same defaults, and none of the shell's `RESTATE_…` variables ([Native Restate for the Test Harness](docs/decisions/0038-native-restate-for-the-test-harness.md)). Nothing needs Docker, and Restate reaches the endpoint on loopback, so `php -S` listens on `127.0.0.1`. Every test runs against either kind, the lifecycle controls and the tests that start a Restate of their own (`IntegrationRestate`) included. `examples/testing` shows the default, a container, so `test:integration:process` runs `tests/Integration` without it. CI runs it on macOS, whose runners have no Docker.
- With `RESTATE_PHP_TEST_RESTATE=external` (`composer test:integration:external`), the harness starts nothing: it connects to a Restate and an endpoint that run outside it, those of a devenv profile (see [External environments](#external-environments)).
- The endpoint is registered through the admin API (`POST /deployments`). `StatePreloadTest` registers a second deployment, the part of `endpoint.php` served under `/state-preload`, with `registerEndpoint('/state-preload')`. When a test fails, the Restate log (the container's, or the process's output) and the endpoint log written during that test are printed to stderr. The containers, the servers, and the run's scratch directories are always removed at exit, including after a fatal error or Ctrl-C.
- `examples/testing/GreeterTest.php` uses the harness exactly as [Testing](docs/develop/testing.md) shows a user doing it: its own entrypoint, a second Restate container, Guzzle through `ingressClient()`.

**The endpoint binds to `0.0.0.0` by default with a Restate container**, because a server bound to `127.0.0.1` is not reachable from the container. This exposes the test endpoint on your LAN for the duration of the run. Set `RESTATE_PHP_TEST_BIND` to a narrower address that the container can still reach through `host.docker.internal` if that matters on your network, or run Restate as a process (`RESTATE_PHP_TEST_RESTATE=process`), which reaches the endpoint on `127.0.0.1`.

| Variable | Default | Purpose |
| --- | --- | --- |
| `RESTATE_PHP_TEST_SERVER` | `php-cli` | Endpoint server: `php-cli` (`php -S` on the host), `frankenphp` (container with the thread-safe extension), `frankenphp-worker` (the same in worker mode, see [FrankenPHP worker mode](#frankenphp-worker-mode)), or php-fpm in a container behind `nginx-fpm` (HTTP/1.1), `nginx-fpm-h2c` or `caddy-fpm` (both h2c); see [Serving](#serving-large-bodies-php-fpm-and-front-door-hazards). |
| `RESTATE_PHP_TEST_FRANKENPHP_IMAGE` | `restate.local/restate-php-frankenphp:extension-zts` | FrankenPHP endpoint image (built by `bin/test-frankenphp.sh`). |
| `RESTATE_PHP_TEST_FPM_IMAGE` | `restate.local/restate-php-fpm:extension` | PHP-FPM endpoint image (built by `bin/test-fpm.sh`). |
| `RESTATE_PHP_TEST_FPM_APACHE_IMAGE` | `restate.local/restate-php-fpm:extension-apache` | The same with Apache, for `ServingHazardsTest` (built by `composer test:serving-hazards`). |
| `RESTATE_PHP_TEST_REPORT_MEMORY` | unset | `1` prints the peak memory (RSS and PHP's) of `LargeBodyTest`'s large cases to stderr. |
| `RESTATE_PHP_TEST_RESTATE` | `container` | Restate server: `container` (`RestateContainer::default()`), `process` (`RestateProcess::default()`, `restate-server` from the dev shell; `composer test:integration:process`) or `external` (Restate and the endpoint run outside the harness; `composer test:integration:external`, see [External environments](#external-environments)). |
| `RESTATE_PHP_TEST_ENDPOINT_URL`, `RESTATE_PHP_TEST_ENDPOINT_SERVER`, `RESTATE_PHP_TEST_STATE_DIR` | set by a devenv profile | With `external`: the endpoint, what serves it (a `RESTATE_PHP_TEST_SERVER` value) and the scratch directory it shares with the tests. `RESTATE_ADMIN_URL` and `RESTATE_INGRESS_URL` (devenv-extras' `services.restate`) are Restate. `RESTATE_PHP_TEST_ENDPOINT_LOG` and `RESTATE_PHP_TEST_RESTATE_LOG` name log files for the log tails, if any. |
| `RESTATE_PHP_TEST_APP_DIR`, `RESTATE_PHP_TEST_LISTEN` | `/app`, `:8080` | The repository and the site address in the FrankenPHP Caddyfiles (`docker/frankenphp/`): the images' defaults, which the devenv profiles replace. |
| `RESTATE_PHP_TEST_BIND` | `0.0.0.0` (`127.0.0.1` with a Restate process) | Address the PHP endpoint binds to (harness setting). |
| `RESTATE_PHP_RESTATE_IMAGE` | `docker.restate.dev/restatedev/restate:1.8.1` | Restate server image (harness setting). `composer test:integration:restate-1.7` runs the suite against 1.7.14 (see [Server versions](#server-versions)). |
| `RESTATE_PHP_EXTENSION_PATH` | `extension/target/debug/restate.so` | Extension the endpoint loads (`php -d extension=…`; harness setting). |
| `RESTATE_PHP_TEST_FAULTS` | unset | Set to `1` (by `FaultInjectionTestCase`, not by hand) to enable the endpoint's fault injector. |
| `RESTATE_PHP_TEST_ENDPOINT_LOGGER` | unset | `off`: the main endpoint has no logger; an absolute path: its JSON-lines log goes there instead of `endpoint-log.jsonl` in the state directory (`/dev/null` in soak runs). Set by the soak suite. |
| `RESTATE_PHP_TEST_CORE_LOG_LEVEL` | `info` | PSR-3 level from which the main endpoint forwards shared-core's log events. Set by the soak suite. |
| `RESTATE_PHP_TEST_WORKER_NUM`, `RESTATE_PHP_TEST_WORKER_FAILURES`, `RESTATE_PHP_TEST_DISPLAY_ERRORS`, `RESTATE_PHP_TEST_POST_DATA_READING`, `MAX_REQUESTS` | `4`, `6`, `1`, `0`, `0` | Worker threads, `max_consecutive_failures`, `display_errors`, `enable_post_data_reading` and requests per worker script run of the worker-mode endpoint (`docker/frankenphp/worker.Caddyfile`). |
| `RESTATE_PHP_TEST_SOAK_CANARY` | unset | `php` or `vm`: the worker script plants a leak after every request (the soak suite's self-test). |
| `RESTATE_PHP_TEST_SCALE_REPORT` | unset | File the [scale tests](#scale-tests) append their measurements to, one JSON object per line. |

If a run is killed with `SIGKILL`, remove leftover containers with `docker rm -f $(docker ps -aq --filter label=restate-php-test-environment)`.

### External environments

```bash
devenv --profile frankenphp test                                     # FrankenPHP, classic mode
devenv --profile frankenphp-worker -O languages.php.version:string 8.4 test # worker mode, PHP 8.4
devenv --profile frankenphp up                                       # the environment on its own…
composer test:integration:external --filter VirtualObjectTest        # …and the suite against it, in its shell
```

A devenv profile runs the integration endpoint and a Restate server as devenv processes, and the harness only connects to them ([External Test Environments](docs/decisions/0039-external-test-environments.md)). `frankenphp` serves `endpoint.php` from `docker/frankenphp/integration.Caddyfile` and `frankenphp-worker` serves `worker.php` from `worker.Caddyfile`, the files the images use. Both use devenv-extras' `languages.php.frankenphp`, which builds FrankenPHP from the shell's PHP (`languages.php.version`) with the overlay's `restate` extension rebuilt thread-safe. They also run its `services.restate` with nix-restate's `restate-bin`. Each profile exports the endpoint's URL, its server name, its state directory and the processes' log files, and its `enterTest` runs `composer test:integration:external` (`frankenphp-worker` adds `tests/Worker`). That runs `tests/Integration` with `RESTATE_PHP_TEST_RESTATE=external`, without the groups `test:integration` excludes and without the `managed` group. So nothing needs Docker, on Linux or macOS, for any PHP minor. The extension is the Nix build of the working tree, not the cargo build, so Rust changes rebuild it on the next `devenv` command.

The `managed` group holds the tests that start, stop or configure their environment: the fault-injection and failure tests, the tests with request identity keys, the serving hazards, the wide-state tests that configure Restate, and the worker suite's single-thread endpoint. They run in the jobs that start their own environment. In external mode the suite's factories (`IntegrationRestate`, `IntegrationEndpoint::managed()`, `withFaults()`, `phpCli()`, `fpm()`) and the harness's lifecycle methods throw, naming the group, so a test that needs control but is not in it fails the external run instead of skipping. The suite's tests use fixed keys (`Counter/alice`, workflow ids) and expect an empty Restate, so the profiles empty Restate's data and the state directory whenever their processes start (`devenv up`, `devenv test`; not when a shell is entered): run the suite once per `devenv up`, then restart it.

The harness's configuration and command lines are unit-tested without a container runtime in `tests/Unit/Testing/` (`ProcessTest` ends a real `php -S` with its workers, by killing, stopping and signalling it, on Linux and macOS, and pauses a process with SIGSTOP); `TestingWithoutPhpUnitTest` checks that only the PHPUnit trait needs PHPUnit. `HarnessLifecycleTest` exercises the lifecycle controls against a real Restate once each, in a container or as a process.

### Failure injection

Tests that break things extend `tests/Integration/Support/FaultInjectionTestCase.php`. Its endpoint is the integration endpoint with `RESTATE_PHP_TEST_FAULTS=1` (`IntegrationEndpoint::withFaults()`), a configuration of its own, so the harness starts a second Restate for it and the shared one is never disturbed. What they have to work with:

- **Lifecycle** (`RestateTestEnvironment`, public API): `restartRestate(graceful: true|false)`, `pauseRestate()`/`unpauseRestate()`, `killEndpoint()`, `startEndpoint()`, `restartEndpoint(graceful: …)`, `signalEndpoint($signal)` (`kill --signal` for an endpoint container, e.g. `USR2` to php-fpm), `isEndpointRunning()`, `waitUntilReady()`. Restate keeps its ports and its storage (the container is never run with `--rm` or tmpfs; a process restarts on the same data directory); the endpoint keeps its port. Killing, stopping or signalling `php -S` ends its workers with it: on Linux they die with their server (`PR_SET_PDEATHSIG`), elsewhere the harness ends them. `pauseRestate()` under rootless podman needs cgroup v2 with the freezer delegated; for a process it is SIGSTOP.
- **Restate's view** of an invocation: `query($sql)`, `invocationRow($id, ...$columns)` (`status`, `retry_count`, `last_failure`, `last_failure_error_code`, …; null while there is no row) and `waitForInvocationStatus($id, $status, $timeout)`, with statuses `pending`, `scheduled`, `ready`, `running`, `backing-off`, `suspended`, `paused`, `completed`. Restate 1.8 keeps no `last_failure` on a paused row. `IntegrationTestCase` adds `sendInvocation()` and `attachInvocation()`.
- **Registration and containers:** `registerDeployment($uri, http2: …, force: …)` for any URI (a proxy, a second name), with `testHostForRestate()` as the test host's name; `EndpointContainer::withHttp2()`; `withRunArguments()` on both containers for `--cpus`/`--memory` and the like; `endpointPort()`, `containerRuntime()`, `restateContainerName()`, `endpointContainerName()`.
- **Chaos services** (`tests/Integration/Services/chaos.php`, on every integration endpoint): `Chaos` with `steps`, `slowRun`, `busyRun`, `noisy`, `exitInHandler`, `exitInRun`, `exhaustMemoryInHandler`, `exhaustMemoryInRun`, `deploy` (a run, then an awakeable; switchable to code whose replay mismatches), and the virtual object `ChaosObject` (`add`, `batch`: state, two sends and a run in one attempt, `abortable`: a 1 s abort timeout, `get`). Both keep completed journals for an hour (`sys_journal`). They take a JSON object; a fault fires while armed (`times`, default unlimited, or until the test calls `ChaosRecorder::disarm()`), Restate retries every 100 ms and pauses after 5 failed attempts. `ChaosRecorder` keeps, per invocation id (the `x-restate-invocation-id` header), the attempts that started and every execution of each side effect with its attempt. `ChaosServicesTest` checks each handler once.
- **Fault plans** (`tests/Support/Faults/`): a test writes a `FaultPlan` for an invocation id, or for the idempotency key it is about to send with (in the start message from protocol v7), mapping attempt numbers to a `Fault`: `killBeforeOutput()`, `killAfterFrames(n)`, `killAfterResponse()`, `truncateAfterFrames(n, halfFrame: …)`, `exit()`, `delay(ms)`, `respond(status, headers)` (the handler runs, the response is replaced, e.g. `503` with `Retry-After`). The endpoint's `FaultInjector` (usable from a router or a worker loop: `$faults->serve($endpoint, $_SERVER, $body)`) counts attempts per invocation id, lets the endpoint handle the request in full, and cuts the response at frame boundaries parsed from it (`FrameCodec`), never at byte offsets. `FaultInjector::attempts()` returns what each attempt answered (frame types, the fault, how it ended). A kill only ever hits a `php -S` worker or a php-fpm pool worker: under `php -S` the process kills itself only if its parent's command line has ` -S `, because the master serves requests too and dead workers are never replaced (restart the endpoint after a test that kills: `FaultInjectionTestCase::replaceKilledWorkers()`); php-fpm's master never serves and replaces a dead worker at once; elsewhere (FrankenPHP) a kill degrades to truncate-and-`exit`, which Restate sees the same way once output was written. `FaultInjectionTest` runs each kind of cut once.
- **Fault proxy** (`tests/Support/Faults/FaultProxy.php`, the forking `fault-proxy.php` started through the harness's `Process`; no image): a TCP proxy between Restate and the endpoint for faults the endpoint cannot produce itself, on the next n connections: `reset` (before the endpoint sees the request), `reset-response` (halfway through the response), `blackhole`, `latency`, `stall-request` (the body stops after 16 bytes), `truncate-request` (half the body as a complete request), `status` (`503`/`429` with `Retry-After: 2`). HTTP/1.1, one request per connection, every connection logged with the `x-restate-invocation-id` it carried. `ProxiedTestCase` registers the deployment through one.

### Failure scenarios

```bash
composer test:integration                # includes the PR failure scenarios
composer test:failure                    # the nightly group failure-nightly, php -S
RESTATE_PHP_TEST_SERVER=nginx-fpm bash bin/test-fpm.sh --group failure-nightly
bash bin/test-frankenphp.sh --group failure-nightly   # FrankenPHP, classic mode
```

These tests break the endpoint, the network or Restate on purpose (worker kills, abrupt ends, truncated responses, abort timeouts, Restate restarts, network faults, graceful shutdowns, request stalls, back-pressure, OOM kills, expired request identity) and check that invocations recover with their guarantees intact. They assert on Restate's view (`sys_invocation` status and `last_failure`, `sys_journal`) and on what `ChaosRecorder` recorded, count attempts as executions recorded by the endpoint (keyed by `x-restate-invocation-id`, never by the SDK's log lines), and assert orderings of recorded events, never durations (except the lower bound of a `Retry-After` delay, which is Restate's). A `ctx.run` side effect executes exactly once only where no attempt failed; elsewhere at least once, and more often only for the attempts that failed. Every class runs on every `RESTATE_PHP_TEST_SERVER`; a case that needs one server skips elsewhere.

What `php -S` does when a request ends badly, which the tests rely on: a SIGKILLed worker is never replaced, and the master serves requests too, so a kill that lands on it takes the endpoint down; responses carry `Connection: close` with neither `Content-Length` nor chunked encoding; `max_execution_time` is 30 s (not 0 as in the plain CLI); `exit()` gives an empty `200 text/html` and a fatal error a `200` HTML page with `display_errors` on (a `500` with it off), which Restate treats as transient errors; destructors, and so the Rust VM's `Drop`, still run at request shutdown after `exit()` or a fatal error, and only the Rust temporaries of frames a fatal error unwound past leak.

| Test | Runs | What it shows (Restate 1.8.1, measured) |
| --- | --- | --- |
| `WorkerKillTest` | PR | A worker killed before output, inside a run, after the run's proposed completion, after its whole response. A run executes again exactly when its completion was not journaled; earlier runs replay; an idempotent re-send executes nothing; the pool serves on. Behind nginx (`nginx-fpm`, `nginx-fpm-h2c`) a SIGKILLed php-fpm worker's output is lost in full, even a complete response: nginx logs `readv() failed (104: Connection reset by peer) while reading upstream` and Restate sees the body break off (RT0010). Caddy and `php -S` deliver what was written. |
| `AbruptEndTest` | PR | `exit()`, `set_time_limit(1)` with a CPU busy loop, and `memory_limit`, in a handler and in a run whose policy allows 2 attempts. Restate records RT0012: `unexpected content type 'Some("text/html; charset=UTF-8")'` after `exit` (an empty `200`), `unexpected http status code: 500` after a fatal error (`display_errors=0`), the same under `php -S`, nginx and Caddy. The run retry policy is bypassed: armed 3 times, the run executes 4 times; always armed, the invocation pauses after 5 attempts (the invocation policy), then completes once resumed. |
| `TruncatedResponseTest` | PR (5 cuts), nightly (every frame boundary and half frame, truncate and kill) | `ChaosObject/batch`'s response cut at and between its frames. Restate commits every whole frame and drops half a frame: the run executes again unless `ProposeRunCompletion` arrived whole; the counter its two sends add to is exactly 11; no attempt answers a journal mismatch. Kill variants on php-fpm only. |
| `AbortTimeoutTest` | PR; nightly: always slower | A 1 s abort timeout (per handler) against a 4 s run. The timeout does not cut the attempt off: Restate reports it (RT0001, `the invocation stream was closed after the 'abort timeout' (1s) fired`) only when the late response arrives, discards that complete response, and starts the retry then, which executes the run again. No overlap between attempts, but every attempt slower than its abort timeout is wasted, and a handler always slower never completes (paused). The same on every server. |
| `RestateRestartTest` | nightly | Restate stopped gracefully or killed with a run in flight: the run executes again (the endpoint had not answered yet; Restate does not wait for it); with an object lock held and an invocation queued, both complete in order; nothing is registered again. |
| `NetworkFaultTest` | nightly | Through the fault proxy: a reset before the endpoint is retried with nothing executed; a reset halfway through the response commits at most what arrived whole; 1 s of latency costs no retry; a 4 s blackhole under a 1 s abort timeout holds the attempt until the proxy closes it (the abort timeout does not fire without a response); Restate frozen 3 s (`pauseRestate()`) with a 1 s run in flight reads the response once thawed, no retry. |
| `GracefulShutdownTest`, `PhpFpmDrainTest` | nightly | php-fpm `USR2` (reload) and `QUIT` (graceful stop) during a 3 s run lose the attempt with php-fpm's default `process_control_timeout = 0`, and let it finish with `10s` (`RESTATE_PHP_FPM_PROCESS_CONTROL_TIMEOUT`); SIGTERM to `php -S` loses it; SIGTERM to FrankenPHP: at least once. New code under the same URI while an invocation is suspended: `[570 Journal mismatch]` (RT0016) on every attempt until paused, never a different journal; with the old code back the resumed invocation completes and the run is replayed, not executed again. |
| `RequestStallTest` | nightly | A request body stalled 3 s delays the attempt without a retry; 12 stalled bodies do not exhaust the pool (`/health` answers meanwhile: the servers read bodies before PHP runs); half the journal as a complete request fails cleanly with `[571 Protocol violation] The input was closed while still waiting to receive all journal to replay`, and the retry succeeds. |
| `BackPressureTest` | nightly | `503` and `429` with `Retry-After: 2`, from the proxy or from the endpoint (`Fault::respond()`): Restate waits for `Retry-After` (≥ 1.8 s instead of the policy's 100 ms), and such attempts do not count towards the retry policy (six in a row do not pause an invocation whose policy pauses after 5). |
| `ContainerOomTest` | nightly, php-fpm | The endpoint container limited to 256 MiB (`--memory`, no swap) and a handler that lifts `memory_limit` and allocates: the cgroup's OOM killer SIGKILLs the php-fpm worker (php-fpm logs `exited on signal 9 (SIGKILL)`, no PHP fatal error), php-fpm replaces it, Restate retries and the next attempt completes; the container survives. |
| `ClockSkewTest` | nightly | A signed request held back 62 s by the fault proxy, older than its token's 60 s lifetime when PHP checks it: refused with `401` before the handler runs, retried, and the retry (signed anew) completes. Behind nginx the held body hits nginx's `client_body_timeout` (60 s) first, which Restate retries the same way. Under FrankenPHP, whose PHP measures `max_execution_time` (30 s) in wall-clock time, the limit runs out while the endpoint waits for the body: a fatal error page (RT0012), retried the same way. A real clock skew could not be set up: under php-fpm with libfaketime 120 s ahead PHP's `time()` moved but the extension's token check did not (most likely PHP's `RTLD_DEEPBIND` for extensions), and restate-server 1.8.1 hangs at start under libfaketime. |

In FrankenPHP worker mode (`RESTATE_PHP_TEST_SERVER=frankenphp-worker`) the same classes pass (a kill degrades to `exit`, which ends the worker script; FrankenPHP starts it again), and `GracefulShutdownTest` adds SIGTERM to FrankenPHP during a run (classic and worker mode: at least once). FrankenPHP's image has no php.ini, so there a fatal error is a `200` HTML page (the same RT0012 as an `exit`). There is no worker-mode test of stale attempts starving a small pool: Restate starts the retry only after the slow attempt has answered (`AbortTimeoutTest`), so stale attempts never hold a worker while their retries wait; a slow attempt occupies its worker the way any slow request does.

### Workload library

`tests/Support/Workload/` builds realistic request bodies with `FrameCodec`, one named constructor of `Workload` per request class: `start()` (start message and input), `replayRuns(n)` (a retry after n completed runs), `eagerState(k)`, `largeInput(bytes)`, `suspension()`, `failingAttempt()`, `terminalFailure()`, `journalMismatch(token)` (its shared-core debug record names the token, so a test can attribute it), `logging(marker)`, and `run(name)` (not in the classes: with the panic probe's name it panics in a debug build); `Workload::classes()` lists them with typical parameters. Each request knows the frames a correct response has (`WorkloadResponse::isExpectedFor()`). The handlers they are journals of are `WorkloadServices` (`Workload`, `WorkloadObject`), served by the integration endpoint. Three drivers: `InProcessDriver` calls `Endpoint::handleHttpRequest()` directly (benchmarks; `forWorkloadServices()` builds an endpoint on the extension backend), `HttpDriver` sends chunked bodies with no `Content-Length` to an endpoint URL, as Restate 1.8 does, one connection per request, and `ConcurrentHttpDriver` does the same with curl_multi, many requests at a time on kept-alive connections, HTTP/1.1 or h2c, with extra headers per request (the soak suite: at a million requests, closed connections would pile up in TIME_WAIT). The bodies are checked in the unit suite, in process against the extension (`tests/Extension/WorkloadTest.php`) and over HTTP against the integration endpoint (`tests/Integration/WorkloadTest.php`).

### Scale tests

```bash
composer test:integration                                        # includes the small case of each scale test
composer test:scale                                              # the large cases: PHPUnit group `scale`
RESTATE_PHP_TEST_SCALE_REPORT=var/scale.jsonl composer test:scale   # and append the measurements, one JSON object per line
bash bin/test-frankenphp.sh --group scale --filter ContentionTest   # contention on FrankenPHP's threads
```

`tests/Integration/Scale/` (long journals, wide fan-out, wide state, contention) drives the scale services of `tests/Integration/Services/scale.php`, which every integration endpoint serves: `LongJournal`, `FanOut` (with its callee `FanOutCallee`), the virtual objects `WideState` and `Ledger`. Each test has a small case that runs with the integration suite (about a minute in all, three Restate starts included) and large cases in the PHPUnit group `scale`, which `phpunit.xml.dist` excludes; `composer test:scale` runs only that group, and `.github/workflows/nightly-scale.yaml` runs it every night (php -S), plus the contention cases under FrankenPHP, uploading the measurements as artifacts.

How they count:

- **Attempts.** Every scale handler records each attempt and its end (returned, thrown or suspended) with the `ChaosRecorder`, keyed by the `x-restate-invocation-id` header, so a test knows how many attempts an invocation took and how long each ran inside the handler. Restate keeps no `retry_count` once an invocation completed (the column is null on a completed row, and while running it counts the attempts since the last suspension), so "n + 1 + retries" is checked as: at least n + 1 attempts, exactly n + 1 when Restate recorded no `TransientError` in `sys_journal_events` for the invocation (it records one when an attempt fails, though not every repetition of the same error), and a run's side effect executed more than once only as often as attempts failed.
- **Determinism.** Values are deterministic and checked by hash (`ScaleValues`); awakeables are rejected one at a time, each after Restate accepted the one before, so their completion order is the rejection order; the lockstep select case resolves one awakeable and waits for the attempt it causes to end before the next.

| Test | Small case | `scale` group |
| --- | --- | --- |
| `LongJournalTest` | 50 runs: exactly 51 attempts, every step once; 10 runs of 1 MiB, whose last attempt's body (over 10 MiB) is above PHP's `post_max_size` and replays intact thanks to the harness's `enable_post_data_reading=0`; the abort-timeout ceiling: `LongJournal/slowReplay` spends 130 ms per step, replayed or not, under a 1 s abort timeout, so from step 8 on every attempt is aborted (130 ms keeps every attempt at least 40 ms away from the timeout; with 100 ms, attempt 10 landed on it) (RT0001), the endpoint still runs it to the end and executes that step again, and the invocation pauses after 3 failed attempts instead of completing | 500, 1000, 3000 runs; 100 runs of 256 KiB (24 MiB in the last body) |
| `FanOutTest` | 100 calls with `all()` (2 attempts on protocol v7) and with a `select()` loop, 100 sleeps through `DurableFuturesUnordered`, 100 awakeables resolved 32 at a time with `all()` (2 attempts), 50 awakeables in lockstep with `select()` (exactly 51 attempts, keys in completion order), 50 awakeables rejected one by one with `any()` (failures in rejection order); every case caps one attempt's handler time at 10 s | the same with 500 and 1000 futures; the lockstep `select()` with 200, 500 and 1000 |
| `WideStateTest`, `WideStateLazyTest`, `WideStatePartialTest` | 2000 keys eager (every handler 1 attempt), 100 keys lazy (`RESTATE_WORKER__INVOKER__DISABLE_EAGER_STATE=true`: K + 2 attempts with `stateKeys()`), 100 keys partially eager (`RESTATE_WORKER__INVOKER__EAGER_STATE_SIZE_LIMIT=1KiB`: between 2 and K + 2); then two keys and a missing one, `clearAll()` and reads in the same attempt (1 attempt in every mode), and reading the cleared object | 2000 keys in every mode, 10,000 eager, 40 keys of 64 KiB |
| `ContentionTest` | 40 `Ledger/add` on one key from 16 clients with 10 peeks, 5 keys x 8 adds, 10 clients x 3 idempotency keys: the old values are exactly 0…N-1 per key, the final value N, and one invocation per idempotency key | 1000 adds on one key from 64 clients with 200 peeks, 100 keys x 20 adds, 100 clients x 10 idempotency keys |

The lazy and partial state tests start a Restate of their own (`RestateContainer::withEnvironment()`); the eager one and the rest use the shared server. Both variable names and the `1KiB` value format were checked against Restate 1.8.1: with them `readAll` over 50 keys of 64 bytes took 52 and 38 attempts, against 1 by default.

#### Reference measurements

From `composer test:scale` on a 12-core development machine (PHP 8.4 NTS, debug extension, `php -S` with 8 workers, Restate 1.8.1, protocol v7), one run per case, some while other suites shared the machine. They give orders of magnitude and growth rates, not baselines. Handler time is the time an attempt spends inside the handler (replay included, bootstrap and transport not).


- **Long journals** (attempts / wall time / mean attempt cycle, start to start / handler ms at the first, middle and last attempt / handler time in all). No attempt failed in any of them, so each took exactly n + 1 attempts and ran every step once. The cycle grows by about 0.07 ms per journaled step (about 35 µs per entry, most of it outside the handler: Restate reading and sending the journal, the endpoint reading and decoding it): 26 ms at attempt 1 and 100 ms at attempt 1000 of the 1000-run case.

  | Runs x value | Attempts | Wall | Mean cycle | Handler ms | Handler total |
  | --- | --- | --- | --- | --- | --- |
  | 50 x 16 B | 51 | 1.7 s | 33 ms | | |
  | 500 x 16 B | 501 | 23 s | 46 ms | 2 / 7 / 15 | 4.1 s |
  | 1000 x 16 B | 1001 | 62 s | 62 ms | 1 / 18 / 56 | 20 s |
  | 3000 x 16 B | 3001 | 363 s | 121 ms | 1 / 44 / 83 | 164 s |
  | 100 x 256 KiB | 101 | 98 s | 966 ms | 8 / 21 / 31 | 2.5 s |

  The abort-timeout ceiling, measured with 100 ms per step (now 130 ms, so the ceiling is step 8) and a 1 s abort timeout: steps 1-9 completed, step 10 never did; attempts 10-12 were aborted with RT0001 and each ran step 10 again; the invocation paused 8.4 s after it started.
- **Fan-out** (attempts / wall time / the slowest attempt's handler time, 1000 futures). `all()` takes 2 attempts; `select()` and `DurableFuturesUnordered` 2-3, because many completions arrive per resumption.

  | Case | 1000 futures |
  | --- | --- |
  | `all()` over calls | 2 / 2.1 s / 773 ms |
  | `select()` loop over calls | 3 / 2.8 s / 1150 ms |
  | `DurableFuturesUnordered` over sleeps | 3 / 1.3 s / 560 ms |
  | `all()` over awakeables | 2 / 0.9 s / 252 ms |
  | `any()` over awakeables rejected one by one | 47 / 18 s / 609 ms |

  The lockstep `select()` loop, one completion per attempt, is the O(n³) case ([Wide Fan-out](docs/serving.md#wide-fan-out-costs-quadratic-time-per-attempt)): 200 awakeables took 201 attempts, 15.7 s, 4.5 s of handler time, slowest attempt 38 ms; 500 took 501 attempts, 97 s, 57 s, 206 ms; 1000 took 1001 attempts, 600 s, 448 s, 1102 ms. Doubling n multiplies the handler time by about 8: shared-core reduces the whole await tree once per result it processes, and the SDK passes over the futures left in each call ([Tree Awaits Report Their Completed Handles](docs/decisions/0036-tree-await-completed-handles.md)).
- **Wide state** (`readAll` attempts / time). Eager: 1 attempt for 2000 keys (0.14 s), 10,000 keys (0.49 s) and 40 keys of 64 KiB (0.76 s); filling 10,000 keys in one attempt took 0.28 s. Lazy: 2002 attempts and 159 s for 2000 keys, 42 attempts and 7.8 s for 40 keys of 64 KiB. Partial (1 KiB limit): 1953 attempts and 136 s for 2000 keys (Restate sent 49 of them), 42 attempts for 40 keys of 64 KiB (none fits).
- **Contention** (`php -S` / FrankenPHP 1.13.5 in classic mode with the debug ZTS extension). 1000 adds on one key: 121 s / 110 s, 8.3 / 9.1 adds per second: the key's lock serialises them, and each add is 2 attempts. 100 keys x 20 adds: 8.4 s / 7.4 s, 238 / 270 adds per second. No lost or repeated update and one execution per idempotency key in every run.

### Server versions

The minimum supported Restate server is 1.8; 1.7 is best-effort ([Shared Core](docs/decisions/0014-shared-core.md#server-compatibility)). The suite runs against 1.8.1 by default, which negotiates service protocol v7. Restate 1.7 negotiates v6, so a run against it is the regression check for the older protocol:

```bash
composer test:integration:restate-1.7   # RESTATE_PHP_RESTATE_IMAGE=docker.restate.dev/restatedev/restate:1.7.14
```

Any other image works the same way through `RESTATE_PHP_RESTATE_IMAGE`.

### FrankenPHP (thread-safe build)

```bash
composer test:frankenphp                                    # whole integration suite
bash bin/test-frankenphp.sh --filter 'BasicServiceTest|VirtualObjectTest'
```

`bin/test-frankenphp.sh` builds the `frankenphp-extension` target of `docker/Dockerfile`: the extension compiled inside the pinned `dunglas/frankenphp` image against its thread-safe (ZTS) PHP, with a Caddyfile that sends every request to the integration router script. It then runs the integration suite with `RESTATE_PHP_TEST_SERVER=frankenphp`. The container mounts the repository, so PHP changes need no rebuild; Rust changes do. Set `RESTATE_PHP_TEST_SKIP_BUILD=1` to reuse the image, or `RESTATE_PHP_FRANKENPHP_BASE_IMAGE` to build against another FrankenPHP/PHP version (for example `docker.io/dunglas/frankenphp:1.13.5-php8.5-bookworm`). The first build takes a few minutes (rustup and a debug build inside the image, with `bin/build-extension.sh --php-config` against the image's ZTS `php-config`). This is the regression check for thread-safe builds: run it after Rust changes.

### FrankenPHP worker mode

```bash
composer test:frankenphp-worker                     # integration suite, then the worker suite
bash bin/test-frankenphp.sh --worker --filter WorkerIsolationTest
```

`bin/test-frankenphp.sh --worker` runs the same image with `docker/frankenphp/worker.Caddyfile` (read from the mounted repository, so changing it needs no rebuild) and `RESTATE_PHP_TEST_SERVER=frankenphp-worker`, then `phpunit --testsuite integration,worker`. The worker script, `tests/Integration/Support/worker.php`, is the shape [docs/serving.md](docs/serving.md#frankenphp-worker-mode) documents: one `IntegrationEndpoints` per worker script run (each endpoint built on its first request and kept), `frankenphp_handle_request()` in a loop, `gc_collect_cycles()` after each request. Four worker threads by default. The image's PHP has no php.ini, so `display_errors` is on for the whole run, which makes the run the stray-output check in worker mode. Both FrankenPHP Caddyfiles set `enable_post_data_reading=0`, as docs/serving.md recommends: otherwise a request body over `post_max_size` (`LongJournalTest`'s 10 MiB journal, `LargeBodyTest`'s 9 MiB input) makes PHP print a warning into every attempt's response; in worker mode it comes from `frankenphp_handle_request()` in worker.php, so the endpoint names that line as where the output started.

Every response names the worker that served it (`WorkerProbe`: `x-restate-php-worker-instance`, random per worker script run, `-pid` and `-requests`), each run records itself under `<state dir>/workers/`, and `GET /__worker` answers with its statistics (ini settings, PHP heap after `gc_collect_cycles()`, `diagnostics()` when the extension has them, RSS and file descriptors). `GET /__worker/echo` prints and flushes, so PHP sends its own headers. worker.php refuses to serve outside worker mode.

The worker suite (`tests/Worker`, its own PHPUnit suite; it fails, never skips, against another server):

- `WorkerModeTest` guards against a silent fallback to classic mode: some worker script run served more than 50 requests, every response names its worker and its counter grows, one process, `display_errors` on.
- `WorkerIsolationTest` (`tests/Integration/Services/worker_isolation.php`), on an endpoint with one worker thread (`IntegrationEndpoint::withWorkerThreads(1)`, an environment of its own) so consecutive requests reach the same PHP thread: instance-bound services are one object for every invocation and factory-bound ones new per attempt; a context stashed by one invocation throws "already been freed" when the next uses it; every log record carries the invocation that logged it; `headers_sent()` is reset after a request whose output PHP sent itself; `exit()` and a fatal error (`Chaos/exitInHandler`, `Chaos/exhaustMemoryInHandler`) restart the worker script (a new instance), and they and ten invocations sent meanwhile all complete.

The whole integration suite runs in worker mode unchanged.

### Serving: large bodies, PHP-FPM and front-door hazards

```bash
composer test:fpm                                             # the suite on nginx-fpm, nginx-fpm-h2c, caddy-fpm
RESTATE_PHP_TEST_SERVER=caddy-fpm bash bin/test-fpm.sh --filter LargeBodyTest
composer test:large-bodies                                    # nightly group large-bodies, php -S
RESTATE_PHP_TEST_SERVER=nginx-fpm bash bin/test-fpm.sh --group large-bodies
composer test:serving-hazards                                 # nightly group serving-hazards
```

**PHP-FPM.** `bin/test-fpm.sh` builds the `fpm-extension` target of `docker/Dockerfile`: the official `php:8.4-fpm-trixie` image with the extension compiled inside it, nginx 1.26 and Caddy 2.11.9, `php.ini-production` plus `docker/fpm/php.ini` (`display_errors=Off`, `log_errors=On`, `enable_post_data_reading=0`, OPcache on) and the front-door settings docs/serving.md recommends. It then runs the integration suite once per value in `RESTATE_PHP_TEST_SERVER` (default all three): `nginx-fpm` (nginx, registered for HTTP/1.1), `nginx-fpm-h2c` (nginx with `http2 on`, registered for HTTP/2: Restate talks h2c with prior knowledge) and `caddy-fpm` (Caddy `php_fastcgi`, h2c). The container mounts the repository at `/app` like the FrankenPHP one, so PHP changes need no rebuild; Rust changes and changes under `docker/fpm/` do. `RESTATE_PHP_TEST_SKIP_BUILD=1` reuses the image, `RESTATE_PHP_FPM_BASE_IMAGE` builds against another php-fpm image (for example `docker.io/library/php:8.5-fpm-trixie`), `RESTATE_PHP_FPM_EXTENSION_PROFILE=release` builds a release module. `docker/fpm/entrypoint.sh` starts php-fpm (8 static workers, running as root so they can write the bind-mounted state directory) and one front door, configured by `RESTATE_PHP_FPM_*` variables: `FRONT` (`nginx`, `nginx-h2c`, `caddy`, `apache`), `PROFILE` (`recommended`, or `defaults`: the front door's own body and timeout defaults), `SCRIPT` (router script), `READ_TIMEOUT`, `REQUEST_TERMINATE_TIMEOUT`, `CLEAR_ENV`, `MAX_CHILDREN`. `IntegrationEndpoint::fpm($front, $settings)` starts one from a test. The entrypoint forwards `USR1`/`USR2` to the php-fpm master and stops gracefully on `QUIT`, the image's stop signal. The first build takes a few minutes, like the FrankenPHP one.

**What runs where.**

- `LargeBodyTest` (PR, every server): inputs of 1 and 9 MiB through Restate to `LargeBodies/ingest` (`tests/Integration/Services/large_bodies.php`), with handlers that `echo`, raise an `E_DEPRECATED` and raise an `E_WARNING`, under `display_errors` {1, 0} × `enable_post_data_reading` {1, 0}. The harness's settings are the shared endpoint; the other three combinations are `php -S` endpoints with their own Restate (`IntegrationEndpoint::phpCli()->withIniSetting(…)`), which run only on `php-cli` and skip elsewhere. Every case completes, except `display_errors=1` with `enable_post_data_reading=1` above `post_max_size`: there every attempt fails with `unexpected content type`, the endpoint logs one error per attempt naming the startup warning, and the handler never runs. A 40 MiB input gets `413` from the ingress. The `large-bodies` group adds 31 MiB inputs and a 31 MiB output (complete) and a 33 MiB output, state value and run result (RT0003 "hit message size limit", retried until the service's policy of three attempts kills the invocation). The large cases' peak RSS, read from `/proc/self/status` (`MemoryProbe`, which also counts the extension's copies that `memory_get_peak_usage()` misses), is checked against a generous bound; `RESTATE_PHP_TEST_REPORT_MEMORY=1` prints it.
- `ServingTest` (PR, every server): Restate reaches the endpoint over the HTTP version it is registered for, under FPM PHP sees a `CONTENT_LENGTH` the front door synthesised and OPcache is on, and direct requests (`/health`, `/discover` with `accept-encoding: gzip`, an unknown path, an invocation without a content type) get the endpoint's own status, content type and no content encoding.
- `ServingHazardsTest` (group `serving-hazards`, nightly): each case starts its own environment from the `fpm-extension-apache` image (`fpm-extension` plus Apache httpd `mod_proxy_fcgi`): Caddy, nginx and Apache at their defaults (411 above 4 KiB, 413 above 1 MiB, an empty body above 16 KiB without `proxy-sendcl`; each fails every attempt until the retry policy ends it, the handler never runs, and a 31 MiB response passes), Apache with `proxy-sendcl` (9 MiB passes), nginx `fastcgi_read_timeout`, Caddy `read_timeout` and php-fpm `request_terminate_timeout` of 2 s against `Chaos/slowRun` (the attempt fails, the run executes again, the third attempt completes; an always-slow run is paused by its policy after five attempts), and `clear_env = yes` with an identity key from `getenv()` (`tests/Integration/Support/identity-from-env.php`: the endpoint refuses to build and answers 500; with `clear_env = no` unsigned discovery is 401 and signed 200). docs/serving.md, "PHP-FPM and FastCGI", records the measured outcomes.

The `large-bodies` and `serving-hazards` groups are excluded in `phpunit.xml.dist`; `--group` runs them. The upstream suite runs against php-fpm behind nginx with `composer test:upstream -- upstream-fpm-nginx` (the `upstream-fpm-nginx` target: the repository baked in, `test-services/index.php` on `:9080`, h2c and HTTP/1.1).

## Upstream SDK test suite

```bash
composer test:upstream                                   # bin/conformance-upstream-local.sh: upstream-extension-caddy full
composer test:upstream -- upstream-extension-caddy basic # the `default` suite only
RESTATE_PHP_UPSTREAM_TEST_NAME=ProxyRequestSigning composer test:upstream   # one test class
```

`composer test:upstream [-- <target> [basic|full]]` (`bin/conformance-upstream-local.sh [target] [basic|full]`) runs the upstream Restate SDK test suite, `sdk-tests.jar` of [`restatedev/e2e`](https://github.com/restatedev/e2e) **v2.3**, the release the official SDKs run, against this repository's test services. What a pass means, and what it does not, is in [Conformance](docs/internals/conformance.md). The script:

1. downloads the jar to `var/conformance/upstream/` and checks its pinned SHA-256;
2. builds the given `docker/Dockerfile` target (default `upstream-extension-caddy`: Caddy h2c in front of `php -S` serving `test-services/index.php`; or `upstream-fpm-nginx`: php-fpm behind nginx) as `restate.local/restate-php-test-services:<target>`;
3. runs the suite with `java -jar`: `full` (the default) runs all eight suite configurations, `basic` only `default`;
4. prints a pass/fail table per suite from the JUnit reports, and fails a run that reports no tests.

- **Java 25** (`jdk25_headless` in the dev shell); the script stops up front on an older `java`.
- **Container runtime.** Docker or rootless podman. Under podman (detected from `docker version` reporting "Podman Engine"; podman's `docker --version` shim does not say so), unless `DOCKER_HOST` is already set, the script starts a private `podman system service --time=0` socket for Testcontainers, sets `TESTCONTAINERS_RYUK_DISABLED=true` and runs the suite sequentially. The socket-activated `podman.service` is not used: it exits after 5 s idle and breaks Testcontainers' pooled connections (`java.io.IOException: Broken pipe` in a test's setup). `RESTATE_PHP_UPSTREAM_SEQUENTIAL=1` runs sequentially under Docker too. `docker/Dockerfile` names its base images fully qualified (`docker.io/library/…`), because short names do not resolve without unqualified-search registries.
- **Restate image.** The integration suite's (`RestateContainer::DEFAULT_IMAGE`, which the script reads); override with `RESTATE_PHP_RESTATE_CONTAINER_IMAGE`. The suite needs Restate 1.8's admin API, so there is no run against 1.7; `composer test:integration:restate-1.7` covers 1.7.
- **Exclusions.** Cases the SDK cannot pass are excluded by exact name in `test-services/exclusions.yaml`. The file is empty: every case passes. `RESTATE_PHP_UPSTREAM_EXCLUSIONS_FILE` points at another file; set it empty to run everything.
- **Narrowing a run.** `RESTATE_PHP_UPSTREAM_TEST_NAME=<class>` runs one test class in every suite of the mode that has it; `RESTATE_PHP_UPSTREAM_SKIP_BUILD=1` reuses an image already built.
- **Artifacts.** `var/conformance/upstream/<target>/<mode>/` (`RESTATE_PHP_UPSTREAM_OUTPUT_DIR` moves the root): per suite a directory of JUnit XML and container logs, plus the run's `exclusions.new.yaml` and console output (`sdk-tests.log`).
- **Request signing.** `test-services/index.php` reads `E2E_REQUEST_SIGNING`, which the suite sets on the service container (comma-separated `publickeyv1_…` keys, as in the TypeScript test services), as the endpoint's identity keys, so `ProxyRequestSigning` runs through PHP's request identity verification; with a wrong key it fails at discovery with the endpoint's `401`.
- **Contracts.** The services the suite drives are specified in the e2e sources at the same tag, under [`sdk-tests/src/main/kotlin/dev/restate/sdktesting/contracts/`](https://github.com/restatedev/e2e/tree/v2.3/sdk-tests/src/main/kotlin/dev/restate/sdktesting/contracts); [test-services/README.md](test-services/README.md) lists the PHP implementations.

In CI, `.github/workflows/upstream.yaml` runs the same suite through Restate's own action (see [CI](#ci)), and `nightly-serving.yaml` runs it against `upstream-fpm-nginx`.

## Performance

Three layers, cheapest first: PHP micro-benchmarks (phpbench) and Rust micro-benchmarks (gungraun, criterion) on every relevant pull request, and load tests (k6 against Restate) on a dedicated runner. The benchmark jobs in CI report without gating (`continue-on-error`) until their thresholds are calibrated on the runners. Instruction counts (callgrind) are near-deterministic; wall-clock numbers on a shared machine are not (see the A/A run below), so instruction counts are what should gate first ([Performance Benchmarks](docs/decisions/0033-performance-benchmarks.md)).

```bash
composer bench                          # PHP micro-benchmarks (release extension), aggregate report
composer bench -- --filter=benchReplay  # arguments go to phpbench
composer bench:compare                  # this checkout vs the merge base with main (bin/bench-compare.sh)
composer bench:compare -- --base HEAD   # A/A: the same code on both sides, i.e. the noise
composer bench:extension                # Rust core: gungraun instruction counts + replay linearity check
bash bin/bench-extension.sh criterion   # Rust core: criterion wall clock (about 4 min)
bash bin/bench-extension.sh compare     # Rust core: instruction counts vs the merge base, fails above +2%
composer load -- <scenario> --quick     # load test against Restate (bin/load-test.sh)
```

### PHP micro-benchmarks

`tests/Benchmark/*Bench.php` are phpbench 1.7 benchmarks (`phpbench.json`: bootstrap `tests/Benchmark/bootstrap.php`, attributes only). The bootstrap loads `vendor/autoload.php`, then resets PHP's peak memory, so a subject's peak memory leaves out compiling Composer's class map, which changes whenever a dev dependency adds classes. Every iteration runs in its own PHP process with the **release** extension (`extension/target/release/restate.so`; `composer bench` builds it), `opcache.enable_cli=1`, JIT off and `zend.assertions=-1`. Each subject sends its request once before it is timed and throws if the response is not the expected one, so a benchmark cannot silently time an error path. PHPStan and php-cs-fixer cover them like the rest of `tests/`; PHPUnit ignores them (no `*Test.php`).

| Benchmark | Subjects |
| --- | --- |
| `EndpointBench` | A warm endpoint (built once, as in a worker) and one request each: `benchHealth` (through a 30-key `$_SERVER`), `benchGreetScripted` (`Workload/echo` on a fresh `ScriptedInvocation` per request, no VM: `tests/Benchmark/Support/ScriptedBackend.php`), `benchGreetExtension` (the same through the extension), `benchReplay` (the last attempt after N ∈ {0, 10, 100, 1000} completed runs), `benchEagerState` (100 eager state keys), `benchLargeIo` (1 MiB in and out), `benchLogger` (start and replay of 100 runs, without a logger and with one that discards: the SDK's logging plus shared-core's log capture at INFO). The request bodies are the [workload library](#workload-library)'s, sent with its `InProcessDriver`. |
| `ColdEndpointBench` | `benchColdServe`: define 1, 20 or 100 services (3 handlers each, one taking a DTO), build the endpoint and serve one request, per revolution: the per-request bootstrap of php-fpm and `php -S`. Classes are loaded after the first revolution, as with OPcache in production. |
| `DiscoveryBench` | `benchManifest`: a fresh endpoint with the integration suite's services and its `/discover` answer (manifest v4); `benchSchemaInference`: the same for one service whose handlers take and return a three-level, 20-field DTO (JSON Schema inferred from the PHP types). |
| `JsonPayloadBench` | `benchMapOrder`: hydrate and normalize that DTO in a handler on a scripted invocation, with the built-in mapper, `SymfonyJsonPayloadAdapter` (ObjectNormalizer with property info) and `ValinorJsonPayloadAdapter` (no cache), each cold (mapper and endpoint built per request) and warm. |
| `IngressBench` | `benchCall` (a request-response call decoding the result) and `benchSend` (a send with an idempotency key and a header) over a PSR-18 client that answers at once. |
| `IdentityBench` | `benchBuildVerifier` (once per endpoint, so per request under php-fpm) and `benchVerifyValid` / `benchVerifyWrongAudience` (Restate's Ed25519 token through the extension). |
| `FanOutBench` | `benchFanOut`: the last attempt of a handler that combines n ∈ {10, 100, 1000, 2000} futures whose results are all in the journal, through the extension: `all`, `allSettled` and a `select()` loop over n completed sleeps, `any` over n awakeables Restate rejected (the wide fan-out cost; [Wide Fan-out](docs/serving.md#wide-fan-out-costs-quadratic-time-per-attempt)). 1 revolution, 3 iterations. |

**Base vs head.** `bin/bench-compare.sh` (`composer bench:compare`) checks the base revision out in a temporary git worktree (`var/bench/compare/base-tree`, removed at exit) with its own `composer install`; it reuses head's release module when `extension/` is the same in both and builds the base's own otherwise. Each revision runs **its own** benchmark files, in the order base, head, head, base, all in the same job. `tests/Benchmark/compare.php` (`Tooling/BenchComparison`, unit-tested in `tests/Unit/Benchmark/`) compares only variants (`Class::subject#set`) present in every run, so a benchmark a pull request adds, renames or breaks is listed, never fails the comparison. A variant regresses when its median time per revolution is more than 20% slower in **both** pairs and no run of it had an rstdev above 10% (`noisy` otherwise), or when its peak memory grew more than 10% in both pairs; the thresholds are `compare.php` options (`composer bench:compare -- -- --threshold=15`). The script then counts instructions per revolution with callgrind for three subjects of `tests/Benchmark/callgrind.php` (`greet-extension`, `replay-runs:100`, `cold-serve:20`: the measured revolutions run inside one `array_map()` call and valgrind collects only inside it, `--toggle-collect=zif_array_map`, OPcache off) and flags more than 3% growth. It prints the Markdown report, writes `var/bench/compare/report.{md,json}` with the raw dumps, appends the report to the GitHub job summary, and exits 1 on a regression. A base without `phpbench.json` (before this suite existed) runs head once and compares nothing.

### Rust micro-benchmarks

`extension/core/benches/` benchmarks the core's `Vm` behind the crate's `bench` feature (`required-features`, so `composer check:extension` never compiles them: checked by putting a `compile_error!` in a bench, which `clippy --all-targets` and `cargo test` did not see; `criterion` and `gungraun` are dev-dependencies, so they are still built for `cargo test`). `vm_instructions` (gungraun 0.20, the renamed iai-callgrind: callgrind instruction counts, request building excluded as setup) and `vm` (criterion 0.8, wall clock) measure the same subjects, built from `tests/vm`'s frames on protocol v7 (`#[path]` includes of `protocol.rs` and `support.rs`): VM start, the last attempt of N replayed runs, eager state with a `clear_state` then state keys, 1 MiB output, an all-of-N await tree over fired sleeps, suspension on N awakeables, plain vs logged VMs (`LogLevel::Info`), and the identity verifier (build; verify a valid, wrong-audience and expired token, fixed timestamps). Every operation goes through `vm.logged()` and `take_logs()`, as the extension calls it.

`composer bench:extension` (`bin/bench-extension.sh`) installs `gungraun-runner` at exactly the gungraun version `Cargo.lock` pins into `extension/target/gungraun-runner/` (it is not in nixpkgs), lints the benches (`cargo clippy -p restate-php-core --features bench --benches -- -D warnings`, since nothing else compiles them), runs gungraun, and fails if the instructions per replayed run at 1000 runs exceed 1.25× those at 100 (`BENCH_EXTENSION_LINEAR_LIMIT`; gungraun cannot assert ratios between benchmarks, so the script reads its `summary.json`). `bash bin/bench-extension.sh compare [<base>]` builds the base in a worktree (`CARGO_TARGET_DIR=extension/target/bench-base`), saves its counts as gungraun baseline `base`, and fails (exit 3) when head's instructions grow more than 2% (`BENCH_EXTENSION_IR_LIMIT`); a base without the benches is skipped. Valgrind comes from the dev shell.

### Load tests

`composer load -- <scenario> [--quick]` (`bin/load-test.sh`; `--list` lists the scenarios, `all` runs every one) builds the `frankenphp-extension-release` target of `docker/Dockerfile` (FrankenPHP classic mode, release ZTS extension, OPcache on, JIT off, `display_errors=0`: `docker/frankenphp/load.{Caddyfile,ini}`), then `tests/Load/run.php` starts, through the harness, a fresh Restate 1.8.1 that signs its requests (an Ed25519 key per run, 4 partitions) and the endpoint with the matching identity keys, serving `tests/Load/Support/endpoint.php` (`LoadServices`, plus a log line per request keyed by `x-restate-invocation-id`). k6 on the host (`tests/Load/k6/load.js`) drives constant-arrival-rate phases (an open model, so a slow endpoint does not slow the arrivals) through the ingress.

- **Scenarios** (`tests/Load/LoadScenario.php`): `noop` (one attempt, nothing journaled), `state` (50 eager keys of 256 B on 200 objects), `runs` (5 runs: 6 attempts), `fanout` (10 calls and `all()`), `long-journal` (50 runs of 1 KiB: 51 attempts) and `overload` (the endpoint capped at 1 CPU and 4 PHP threads; nominal rate, about 20× capacity, nominal again; it reports calls that timed out while queued, duplicated run executions from an effects ledger, peak invocations in flight and the seconds until in-flight is back to nominal).
- **Gate:** endpoint CPU per invocation, the endpoint container's cgroup v2 `cpu.stat` `usage_usec` delta (read with `<runtime> exec`) over the invocations of a repetition, which ends only once nothing is in flight. **Reported only:** k6 latency, throughput, Restate's CPU, `memory.peak`, and the time split per invocation (Restate vs SDK: `created_at`, `running_at` and `completed_at` from `sys_invocation` against the SDK time in the router's log). **Hard failures:** any 401 (request signing is always on), attempts minus `retry_count` other than the scenario's count, more than 0.1% failed calls or (outside `overload`) more than 1% arrivals k6 dropped.
- **Results:** one JSON file per run in `$LOAD_RESULTS_DIR/<scenario>/` (default `var/load/results`), compared with the median of the last 5 comparable runs (same scenario, mode, image and host fingerprint) and with a pinned `tests/Load/baselines/<scenario>.json` when there is one (the rolling median alone misses slow creep). CPU per invocation more than 10% above either is a regression; with `--gate` it exits 3. `tests/Unit/Load/LoadHistoryTest.php` tests the comparison.
- **Length:** `--quick` is a 5 s warm-up and about 20 s per scenario, one repetition (a minute or two with start-up); the default is a 30 s warm-up, 90 s windows and 3 repetitions.
- **Other endpoints:** `LOAD_ENDPOINT_IMAGE=<tag> LOAD_SKIP_BUILD=1` (or `--image`) runs against any image that serves `tests/Load/Support/endpoint.php` on port 8080 with the repository mounted at `/app` and `/load-out` writable, such as a FrankenPHP worker-mode or php-fpm image (a worker loop needs its own script around the same `LoadServices` and log line). `--cpuset-endpoint` / `--cpuset-restate` pin the containers to CPUs.
- **CI:** `.github/workflows/load.yaml` runs on `workflow_dispatch` only (one scenario or `all`, 3 repetitions) and needs a **self-hosted runner with the label `restate-php-load`** (declared in `.github/actionlint.yaml`), which has to be registered for the workflow to run. A nightly rotation (one scenario per night by day of year, 3 repetitions) is written in the workflow, commented out; enable it once the runner exists. Numbers from GitHub's shared runners would not be comparable.
- Not implemented: the cross-check of the time split against Restate's OpenTelemetry spans, and a direct-replay variant without Restate.

### Reference measurements

One machine, one run each, **not baselines**: an AMD Ryzen 5 5500 (6 cores, 12 threads, 31 GB), Linux (NixOS), PHP 8.4 NTS from the dev shell, release extension, while other builds and container runs shared the machine (load average 9-12). Use them for orders of magnitude and ratios, not for comparisons with other machines.

**PHP (phpbench, median time per request, mean of the two head runs of an A/A `composer bench:compare -- --base HEAD`, 10 iterations each):**

| Subject | µs | | Subject | µs |
| --- | ---: | --- | --- | ---: |
| `benchHealth` | 8.5 | | `benchColdServe` 1 / 20 / 100 services | 58 / 369 / 1,677 |
| `benchGreetScripted` (no VM) | 13.9 | | `benchManifest` (integration services) | 1,586 |
| `benchGreetExtension` | 23.7 | | `benchSchemaInference` (one DTO service) | 391 |
| `benchReplay` 0 / 10 / 100 / 1000 runs | 27 / 90 / 562 / 5,352 | | `benchMapOrder` built-in cold / warm | 225 / 177 |
| `benchEagerState` (100 keys) | 543 | | `benchMapOrder` Symfony cold / warm | 1,505 / 310 |
| `benchLargeIo` (1 MiB in and out) | 7,329 | | `benchMapOrder` Valinor cold / warm | 1,597 / 136 |
| `benchLogger` start off / on | 24.9 / 31.4 | | `benchCall` / `benchSend` (ingress) | 22.3 / 25.4 |
| `benchLogger` replay 100 off / on | 555 / 737 | | `benchBuildVerifier` / `benchVerifyValid` | 0.95 / 35.8 |

- Replay is linear: about 5.3-5.6 µs per replayed run from 100 to 1000 runs (callgrind: 4.56 M instructions per request at 100 runs).
- 100 eager state keys cost about 5 µs per key, and 1 MiB in and out about 7 ms; a logger adds about 25% to the start request and 33% to a replay of 100 runs.
- The built-in mapper costs nearly as much warm as cold (177 vs 225 µs), so most of its cost recurs on every request even in a worker; Symfony's and Valinor's cold set-up costs 1.2-1.5 ms per request, which a worker pays once.
- **A/A noise on this machine:** pair deltas up to ±60% and per-variant rstdev up to 70% for the wall-clock times; the callgrind counts of the same code differed by at most 0.012% (greet 154,662 vs 154,680 instructions; replay-runs:100 4,559,740 vs 4,559,597; cold-serve:20 3,931,882 vs 3,931,841). One phpbench run takes about 45 s; a whole `composer bench:compare` about 4.5 min.

**Rust (`composer bench:extension`, instructions; criterion wall clock):**

| Subject | Instructions | Criterion |
| --- | ---: | ---: |
| VM start, plain / logged | 9,216 / 11,541 | 0.89 / 0.90 µs |
| Replay of 0 / 10 / 100 / 1000 runs | 13,508 / 127,533 / 1,138,822 / 11,347,173 | 1.15 / 11.7 / 84.6 / 834 µs (10,000 runs: 8.55 ms) |
| Replay of 100 runs, logged | 1,176,042 (+3.3%) | 89.4 µs |
| Eager state, 10 / 1000 keys | 121,188 / 10,667,210 | 11.4 / 896 µs |
| 1 MiB output | 221,170 | 48.6 µs |
| All-of-N await tree, 10 / 100 | 140,465 / 3,311,084 | 11.5 / 237 µs |
| Suspension, 1 / 100 awakeables | 18,267 / 308,487 | 1.53 / 22.8 µs |
| Identity verifier build | 9,574 | 0.63 µs |
| Verify valid / wrong audience / expired | 401,480 / 405,747 / 408,025 | 36 / 57 / 49 µs (noisy) |

- Replay is linear in the core too: 11,253 instructions per run at 100 runs, 11,334 at 1000 (ratio 1.007).
- **The all-of-N await tree is superlinear:** about 14 k instructions per sleep at N=10, 33 k at N=100. callgrind_annotate puts about three quarters in shared-core's `AsyncResultsState::try_resolve` under `do_await_tree`, which re-checks the tree after each result, so it is O(N²) in shared-core (relevant to the wide fan-out scenario). [An upstream fix](https://github.com/restatedev/sdk-shared-core/pull/122) addresses it; shared-core 8.7.2 does not include it.
- gungraun A/A noise: at most 0.3%. The gungraun run takes about 6 s (20 s with the script's lint and runner check), criterion about 4 min.

**Wide fan-out (`FanOutBench`, in process: `Endpoint::handleHttpRequest()` on a `FrameCodec` journal of n completed sleeps, or for `any()` n rejected awakeables, no logger, one attempt; ms, best of 5 with the release build or of 2 with the debug build):**

| n | `all()`, release | `select()` loop, release | `any()`, release | `select()` loop, debug | `any()`, debug |
| --- | --- | --- | --- | --- | --- |
| 100 | 0.66 | 1.7 | 2.3 | | |
| 1000 | 20 | 91 | 76 | 543 | 489 |
| 2000 | 72 | 351 | 271 | 1939 | 1913 |

- `all()` and `allSettled()` await one tree, and their cost is shared-core's (about 1 s at n=2000 with the debug build). A tree await reports which of its handles completed ([Tree Awaits Report Their Completed Handles](docs/decisions/0036-tree-await-completed-handles.md)), so the SDK makes one extension call per await, not one per future.
- Of the 351 ms a release `select()` loop over 2000 futures still takes, about 100 ms is the SDK's pass over the futures left in each call, 65 ms the extension converting each tree, and the rest shared-core reducing it ([Wide Fan-out](docs/serving.md#wide-fan-out-costs-quadratic-time-per-attempt)).

**Load tests (`composer load -- all --quick`, rootless podman 5.8.6 on cgroup v2, k6 1.7.1, quick mode; re-runs moved CPU per invocation by up to 27%):**

| Scenario | Invocations/s | Endpoint CPU µs per invocation (per attempt) | p50 / p99 ms (k6) | Attempts (minus retries) | 401s |
| --- | ---: | ---: | ---: | ---: | ---: |
| `noop` | 100 | 1,890-2,129 | 137-243 / 810-1,018 | 1 (1) | 0 |
| `state` | 50 | 3,119 | 159 / 1,086 | 1 (1) | 0 |
| `runs` | 30 | 12,373-14,304 (about 2,100-2,400) | 1,040 / 1,667-4,348 | 6 (6) | 0 |
| `fanout` | 10 | 21,347 (1,779) | 701 / 1,797 | 2 (2) | 0 |
| `long-journal` | 2 | 135,452 (2,656) | 6,888 / 10,801 | 51 (51) | 0 |
| `overload` | 118 completed | 7,056 | 5,000 (timeout) | 4 (4) | 0 |

- The SDK's own time per `noop` request was 1.4-1.6 ms (p50); Restate and the round trips account for most of the end-to-end latency here.
- `overload`: 4,897 calls timed out at 5 s while queued, 0 run executions were duplicated, at most 4,045 invocations were in flight (36 at the nominal rate), and in-flight was back to nominal 22 s after the overload phase. OPcache's hit rate was 99.9%.

## Soak suite

```bash
composer test:soak                      # nightly set: frames, concurrency, bailout (about 15 minutes)
composer test:soak -- --quick           # a few percent of the requests, for a local run (about 1 minute)
bash bin/test-soak.sh restate           # RestateSoakTest through Restate, 300,000 invocations (about 14 minutes)
RESTATE_PHP_SOAK_INVOCATIONS=30000 bash bin/test-soak.sh restate
```

`bin/test-soak.sh [--quick] [nightly|frames|concurrency|bailout|restate|all]` builds the worker images the chosen tests need and runs the `soak` PHPUnit suite (`tests/Soak`; not part of `composer check`; see [Worker-Mode and Soak Testing](docs/decisions/0035-worker-soak-testing.md)). Its images are the last targets of `docker/Dockerfile`: `frankenphp-worker-release` (on `extension-bridge-zts-release`: the release ZTS module, built again with the `diagnostics` cargo feature) and `frankenphp-worker-debug` (the debug module with `diagnostics`, so the panic and bailout probes work). Both serve the integration endpoint's worker script (see [FrankenPHP worker mode](#frankenphp-worker-mode)) from the repository mounted at `/app`, with `GODEBUG=madvdontneed=1`; `tests/Soak/Support/SoakEndpoint` runs one without Restate on a free `127.0.0.1` port and reads `/__worker`. Reports (checkpoint CSVs, summaries) go to `var/soak/<run>-<time>/` (`RESTATE_PHP_SOAK_REPORT_DIR`). Image tags: `RESTATE_PHP_SOAK_RELEASE_IMAGE`, `RESTATE_PHP_SOAK_DEBUG_IMAGE`; `RESTATE_PHP_SOAK_SKIP_BUILD=1` reuses them.

**Diagnostics.** The Rust heap is invisible to `memory_get_usage()`. With the `diagnostics` feature (`extension/extension/src/diagnostics.rs`, off by default, so no release has it, and without it the extension is unchanged) a counting global allocator wraps the system allocator and `Restate\Extension\diagnostics()` returns `live_vms`, `live_identity_verifiers`, `rust_live_bytes` and `rust_allocations`, process-wide. An idle VM holds no Rust heap at all, so `live_vms` is the signal for a leaked VM and `rust_live_bytes` for leaked buffers. RSS and open file descriptors come from `/proc/self` as a backstop.

- **`FrameReplaySoakTest`.** One worker thread, release build, the SDK logging every request through the JSON-lines logger to `/dev/null` (shared-core at info), `display_errors=0`, every request signed (Restate's identity headers, re-signed every 30 s). A `ConcurrentHttpDriver` replays the workload classes straight to the worker (chunked, no `Content-Length`, 4 in flight): a 50,000-request mixed warm-up, then each class alone (50,000 requests, fewer for large bodies; plus a 9 MiB body over `post_max_size` and a forged identity, which must get a 401), then 500,000 mixed, about 950,000 in all. Every response is checked. After every batch (20 per class, 40 mixed) a checkpoint (`Checkpoints`) takes the worker's numbers, and the run passes when, after the warm-up: no VM is live at any checkpoint, the verifier count and the included files stay the same, file descriptors stay within ±4, the worker script never restarts, and the Theil–Sen slope of each class's checkpoints after its first quarter stays under 1 byte per request for the Rust heap and for PHP's heap after `gc_collect_cycles()`, and under 16 bytes per request for anonymous RSS (reported, not gated, in a quick run). Calibration (two full clean runs, 940,000 and 990,000 requests, about 13 minutes each): both heaps exactly flat (0.000 bytes per request in every class; the Rust heap at 6,739 bytes at every checkpoint), RSS slopes from -14.9 to +8.5 bytes per request, except the first class after a 4,000-request warm-up in the first run (+32.8: RSS was still settling, hence the 50,000-request warm-up, after which the first class measured +1.4). Recalibrate from the nightly artifacts: about twice the worst clean slope. `testPlantedLeaksAreCaught` is the self-test: with `RESTATE_PHP_TEST_SOAK_CANARY=php` (256 bytes of PHP heap kept per request) and `=vm` (a VM holding 1 KiB of input kept per request) the checks must fail, naming `phpBytes`, `live_vms` and `rustBytes`.
- **`ConcurrencySoakTest`.** Debug build, 8 worker threads (at most the CPUs), 16 requests in flight, shared-core's events at debug. 100,000 requests, 40% journal mismatches whose core record (`Failed with error (570) …`) names a token unique to the request, and every hundredth request the panic probe. Passes when every mismatch's record carries its own request's invocation id, exactly once, no core record names an invocation that was not sent, every probe gets its 500 (`Rust panic`) and every other request its expected frames, no VM is left live, and the only panics in the log are the probe's. A second test alternates the probe with ordinary requests on one thread: all served by the same worker script run. A third reports throughput with the logger off and on (release build), not gated. Measured: 5,700 requests per second with 8 threads (debug), 0 misattributed records out of 40,000 mismatches; logger on/off 0.92 (10,370 against 9,507 requests per second, release build, 60,000 requests; 0.31 when the frame-replay soak ran on the same machine at the same time).
- **`BailoutSoakTest`.** Debug build, one worker thread. 100 times: a request with a 12 KiB header and the bailout probe in another header (the extension raises `E_ERROR` while it converts the headers), then 10 ordinary requests; then 20 bailouts back to back. Passes when every bailout gets a 500 and restarts the worker script, the requests after it succeed on the new run, no VM is left live, the Rust heap grows by at most the header bytes plus 1 KiB per fatal error on average, and FrankenPHP never logs `too many consecutive` failures (its `max_consecutive_failures`, 6 by default, counts only scripts that fail before reaching `frankenphp_handle_request()`). Measured: 10,756 bytes per fatal error on average with 12,317 bytes of headers (FrankenPHP passes headers in random order, so the 12 KiB header is converted before the probe about half the time), anonymous RSS about 30 KiB per restart (reported).
- **`RestateSoakTest`.** Restate 1.8.1 signing its requests (a fresh Ed25519 key), the release worker with 8 threads, the Soak services (`tests/Integration/Services/soak.php`: journal retention 0, idempotency retention 1 minute). 300,000 invocations (`RESTATE_PHP_SOAK_INVOCATIONS`; 3,000 quick), 64 in flight through the ingress: greetings, counter adds over 1,000 keys, three sleeps (suspensions), three runs, two failing attempts before success, awakeables resolved by another invocation. Gated exactly: every answer, every counter's final value, no unfinished invocation in `sys_invocation`, no live VM, 0 × 401 (the endpoint's own count of its answers by status, which `WorkerProbe` records, plus Restate's log) and 0 × `RT0012` in Restate's log. Latency p50 and p99 per tenth of the run are reported only. Reference run: 300,000 invocations in 822 s (365 per second), all gates clean, the worker at 0 live VMs and 10,334 bytes of Rust heap at the end; 30,000 invocations: 300 per second, p50 92–147 ms and p99 0.3–1.7 s per tenth.

CI: `.github/workflows/nightly-soak.yaml` runs `bin/test-soak.sh nightly` every night and `bin/test-soak.sh restate` every Sunday, and by hand (`workflow_dispatch`, any suite, optionally quick); the reports are kept as the `soak-reports` artifact.

## CI

GitHub Actions runs the same commands, in the same dev shell. Nothing in CI is CI-only: when a job fails, run its command locally.

| Workflow | Job | Command | Matrix |
| --- | --- | --- | --- |
| `.github/workflows/ci.yaml` | `check` | `devenv shell -- composer check` | PHP 8.4, 8.5, 8.6 (`devenv -O languages.php.version:string <version> shell`) |
| | `integration` | `devenv shell -- composer test:integration` | Restate 1.8.1 (`test:integration`), 1.7.14 (`test:integration:restate-1.7`) |
| | `integration-macos` | on `macos-15`: checks that the dev shell's `restate-server` is `RestateContainer::DEFAULT_IMAGE`'s version, then `devenv shell -- composer test:integration:process` (Restate as a process, no Docker) | — |
| | `frankenphp` | `devenv shell -- composer build:extension`, then `composer test:frankenphp` (the examples in the integration suite serve with `php -S` and the host's extension) | PHP 8.4 ZTS (the Dockerfile default), 8.5 ZTS (`RESTATE_PHP_FRANKENPHP_BASE_IMAGE=docker.io/dunglas/frankenphp:1.13.5-php8.5-bookworm`), 8.6 ZTS (`…:1.13.6-php8.6-bookworm`; 1.13.5 has no 8.6 image) |
| | `frankenphp-worker` | `devenv shell -- composer build:extension`, then `composer test:frankenphp-worker` | PHP 8.4 ZTS |
| | `fpm` | `devenv shell -- composer test:fpm` | `RESTATE_PHP_TEST_SERVER`: `nginx-fpm`, `nginx-fpm-h2c`, `caddy-fpm` (PHP 8.4 NTS php-fpm) |
| | `external` | `devenv --profile <profile> -O languages.php.version:string <php> test` ([External environments](#external-environments)) | `ubuntu-24.04`, `macos-15` × `frankenphp`, `frankenphp-worker` × PHP 8.4, 8.5, 8.6 |
| `.github/workflows/nightly-soak.yaml` | `soak` | `bash bin/test-soak.sh nightly` nightly, `bash bin/test-soak.sh restate` on Sundays, any suite by hand; reports as an artifact ([Soak suite](#soak-suite)) | — |
| `.github/workflows/nightly-scale.yaml` | `scale`, `contention-frankenphp` | `composer test:scale`; `bin/test-frankenphp.sh --group scale --filter ContentionTest` ([Scale tests](#scale-tests)), each uploading its measurements | — (nightly and manual runs) |
| `.github/workflows/upstream.yaml` | `sdk-tests` | `docker build --target upstream-extension-caddy …`, then the [`restatedev/e2e/sdk-tests@v2.3`](https://github.com/restatedev/e2e/blob/main/sdk-tests/action.yml) action | — |
| `.github/workflows/quickstart.yaml` | `local`, `released` | `examples/quickstart/scripts/smoke-test.sh --local` on pull requests and pushes touching the quickstart, `docker/Dockerfile`, `packages/` or `extension/` (the [Docker quickstart](examples/quickstart/README.md) built from the commit); nightly also `smoke-test.sh` against the released extension and packages, skipped until a version is released | `local`: `ubuntu-24.04`, `ubuntu-24.04-arm` |
| `.github/workflows/nightly-serving.yaml` | `large-bodies` | `composer test:large-bodies`, or `bash bin/test-fpm.sh --group large-bodies` with `RESTATE_PHP_TEST_REPORT_MEMORY=1` | `php-cli`, `nginx-fpm`, `nginx-fpm-h2c`, `caddy-fpm` |
| | `serving-hazards` | `devenv shell -- composer test:serving-hazards` | — |
| | `upstream-fpm-nginx` | `docker build --target upstream-fpm-nginx …`, then the `restatedev/e2e/sdk-tests@v2.3` action | — |
| `.github/workflows/nightly-failure.yaml` | `failure` | `composer test:failure`, `bash bin/test-fpm.sh --group failure-nightly`, or `bash bin/test-frankenphp.sh --group failure-nightly` | `php-cli`, `nginx-fpm`, `nginx-fpm-h2c`, `caddy-fpm`, `frankenphp` |
| `.github/workflows/extension.yaml` | `build`, `verify`, `flake-macos` | `build`: `composer build:pie -- dist` in the dev shell on x86_64, all 24 archives as one artifact, then `nix flake check ./extension`. `verify`, per CPU (`ubuntu-24.04-arm` for arm64), with plain Nix: every checksum, then `nix run ./extension#load-test` (each archive in the clean `php:<minor>-{cli,zts}-{trixie,alpine}` of its PHP, running `extension/tests/*.phpt`; PHP 8.6's official images are ZTS only, `php:8.6-cli-*`), then the 8.6 NTS glibc archive with nixpkgs' PHP 8.6 (at `extension/flake.lock`'s nixpkgs, the same tests with its `run-tests.php`); 8.6 NTS musl has nothing to load into yet. `flake-macos`: `nix flake check ./extension` on `macos-15` (arm64) | `verify`: x86_64, arm64 |
| `.github/workflows/bench.yaml` | `compare`, `calibrate` | `bash bin/bench-compare.sh --base <PR base>` on pull requests touching `packages/`, `extension/`, `tests/Benchmark/`, `tests/Support/`, the Composer files or the scripts; nightly and by hand `bash bin/bench-compare.sh --base HEAD` (A/A), reports kept 90 days. Both `continue-on-error` (see [Performance](#performance)) | — |
| `.github/workflows/bench-extension.yaml` | `compare`, `nightly` | `bash bin/bench-extension.sh compare <merge base>` on pull requests touching `extension/`; nightly `bash bin/bench-extension.sh` and `criterion` (report artifact). `continue-on-error` | — |
| `.github/workflows/load.yaml` | `load` | `bash bin/load-test.sh <scenario\|all> --repetitions 3 --gate` on `workflow_dispatch` only, on a self-hosted runner labelled `restate-php-load` | — |
| `.github/workflows/release.yaml` | `version`, `ci`, `upstream`, `extension`, `attest`, `publish` | Checks the version and the tree, runs `ci.yaml`, `upstream.yaml` and `extension.yaml` on the release commit, then publishes ([Release](docs/decisions/0031-release.md)) | — |
| `.github/workflows/split.yaml` | `split` | `bash bin/split.sh publish --remote <url>`: each published directory's history to its repository's `main`, no tags ([Release](docs/decisions/0031-release.md) decision 9) | — |

- **Triggers.** `ci.yaml`: pushes to `main`, every pull request, and manual runs (`workflow_dispatch`, any branch). `upstream.yaml`: the same, limited to changes under `packages/`, `extension/`, `test-services/`, `docker/`, `composer.json`/`composer.lock`, `bin/build-extension.sh` and the workflow itself. `extension.yaml`: the same, limited to changes under `extension/` (the flake included), to `bin/build-pie.sh`, the devenv files and the workflow itself. `nightly-serving.yaml`: daily at 03:17 UTC and manual runs. `nightly-failure.yaml`: daily at 03:47 UTC and manual runs. A newer push to the same branch or pull request cancels the running workflow; manual runs are never cancelled. `ci.yaml`, `upstream.yaml` and `extension.yaml` are also reusable (`workflow_call`): `release.yaml` runs them on the release commit. `release.yaml`: manual runs on `main` only, one at a time. `split.yaml`: pushes to `main` that touch `extension/`, `packages/`, `bin/split.sh` or the workflow, hourly at :23, and manual runs on `main`, one at a time; push and hourly runs only when the repository variable `SPLIT_ON_PUSH` is `true`.
- **Dev shell in CI.** [`sagikazarmark/setup-devenv`](https://github.com/sagikazarmark/setup-devenv) installs Nix, adds the `devenv` and `restate-php` Cachix caches, installs devenv from nixpkgs and builds the dev shell. Jobs run their steps through `devenv shell -- bash -e {0}`; `composer check`'s PHP matrix skips the up-front shell build, since its first step builds the shell for the matrix PHP.
- **Caches.** Composer's download cache (`.github/actions/composer-cache`, keyed on `composer.lock`) and, per job, Cargo's registry plus `extension/target/` (keyed on `extension/Cargo.lock` and `extension/rust-toolchain.toml`). The Nix store is cached in [restate-php.cachix.org](https://restate-php.cachix.org): every job pulls from it, and pushes what it builds when the `CACHIX_AUTH_TOKEN` secret is set (not in pull requests from forks), so the Rust toolchain, the shell of each PHP minor and the extension's Nix builds (`extension.yaml`'s 24 modules, the flake's checks) are downloaded once their inputs have been built before. The rest comes from cache.nixos.org, and the PHP headers and cargo-zigbuild of the prebuilt binaries from ext-php-rs.cachix.org (`cachix.pull` in `devenv.nix`, which also pulls from restate-php locally). Docker builds have no layer cache, so each FrankenPHP, FPM and upstream job compiles the extension inside the image (a few minutes).
- **Upstream suite.** The action sets up Temurin 25, downloads `sdk-tests.jar` from the release named after the action's ref, runs every suite against `restate.local/restate-php-test-services:upstream-extension-caddy` with `test-services/exclusions.yaml`, uploads the reports as the `upstream-sdk-tests-report` artifact and publishes the JUnit results as a check run and pull request comment (hence the workflow's `checks: write` and `pull-requests: write`). The workflow's Restate image must equal `RestateContainer::DEFAULT_IMAGE`; its first step fails otherwise. A last step fails the job when no tests ran. The action is pinned by tag, not commit SHA: a SHA ref breaks its jar download URL. Locally, `bin/conformance-upstream-local.sh` pins the jar by SHA-256 instead.
- **Action pins.** Every third-party action is pinned by commit SHA, with the release tag in a comment (`actions/checkout@<sha> # v7.0.1`); bump them together with the comment. The one exception is the upstream suite (`restatedev/e2e/sdk-tests@v2.3`), pinned by release tag because a SHA ref breaks its jar download.
- **Release archives.** `.github/workflows/extension.yaml` cross-compiles the extension's PIE archives (PHP 8.4, 8.5 and 8.6 × NTS and ZTS × x86_64 and arm64 × glibc and musl) with Nix in one job (`composer build:pie`), where each Nix build checks its module's shared libraries and glibc floor, then loads every archive into a clean PHP on a runner of its CPU and runs `extension/tests/*.phpt` there, runs the flake's checks on Linux and macOS, and keeps the archives as an artifact; it publishes nothing ([Prebuilt Extension Binaries](docs/decisions/0030-prebuilt-extension-binaries.md)).
- **Release.** `.github/workflows/release.yaml` releases every package and the extension at the extension crate's version: it checks the version and the tree, runs the three workflows above on the release commit, attests the archives, and pushes each package directory and `extension/` to its read-only repository (`git subtree split`, with a GitHub App's token) before creating the GitHub releases ([Release](docs/decisions/0031-release.md), checklist in [RELEASING.md](RELEASING.md)).
- **Not covered.** The suites run on x86_64 glibc only; the extension workflow only builds and loads the other targets.
