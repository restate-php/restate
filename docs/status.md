# Status

What the PHP SDK does, the tests that show it, where it differs from the official SDKs, what is missing, and what it runs on.

This is the evidence-oriented reference: it names suites and test classes. To learn what a feature does and how to use it, read its guide instead ([Documentation](README.md)).

Passing the upstream suite is not an official Restate SDK conformance claim ([Official Conformance](internals/conformance.md#official-conformance)).

## Evidence

All Restate traffic goes through [`restate-sdk-shared-core`](https://crates.io/crates/restate-sdk-shared-core) inside the PHP extension (`extension/extension`), the only backend ([Single Native Backend](decisions/0012-single-native-backend.md), which also says why there is no pure-PHP backend). Without the extension, building an endpoint throws `BackendUnavailableException`. How the pieces fit: [Backend Architecture](internals/architecture.md).

| Suite | Command | What it covers |
| --- | --- | --- |
| Unit, static analysis, Rust crates | `composer check` ([TESTING.md](../TESTING.md#before-every-commit)) | Must pass before every commit. Includes the extension suite (`tests/Extension/`, byte-exact protocol frames against the real extension) and the core VM tests in `extension/core/tests/vm/`. |
| Integration | `composer test:integration` (`tests/Integration` plus the example tests, against a real Restate in a container; [TESTING.md](../TESTING.md#integration-tests)) | Every feature below end to end, PHP 8.4 NTS under `php -S`, on Restate 1.8.1 and, with `composer test:integration:restate-1.7`, on 1.7.14. 182 tests on 1.8.1, 5 of them skipped because they run on another server. |
| Integration, thread-safe | `composer test:frankenphp`, `composer test:frankenphp-worker` | The same suite with the ZTS build under FrankenPHP, in classic and in worker mode; worker mode adds the worker suite (`tests/Worker`). |
| Integration, PHP-FPM | `composer test:fpm` (`bin/test-fpm.sh`) | The same suite against php-fpm (OPcache on) behind nginx over HTTP/1.1 and h2c, and behind Caddy `php_fastcgi` over h2c. |
| Upstream SDK suite | `composer test:upstream` (`bin/conformance-upstream-local.sh`; [TESTING.md](../TESTING.md#upstream-sdk-test-suite)) | `restatedev/e2e` v2.3 `sdk-tests.jar`, the suite the official SDKs run: 322 test cases across its eight suite configurations (`default`, `alwaysSuspending`, `threeNodes`, `threeNodesAlwaysSuspending`, `singleThreadSinglePartition`, `lazyState`, `lazyStateAlwaysSuspending`, `persistedTimers`), on Restate 1.8.1, with no exclusions (`test-services/exclusions.yaml` is empty). Also run against php-fpm behind nginx (`composer test:upstream -- upstream-fpm-nginx`). |
| Nightly groups | `composer test:failure`, `test:scale`, `test:large-bodies`, `test:serving-hazards`, `test:soak` | Failure injection, scale, large bodies, front-door hazards and worker soak ([TESTING.md](../TESTING.md)). |

Every suite passes locally. GitHub Actions runs the same commands (`.github/workflows/ci.yaml`, `upstream.yaml` and the nightly workflows; [TESTING.md](../TESTING.md#ci)).

The tables below name integration test classes (`tests/Integration/`) and upstream test classes (`restatedev/e2e` `sdk-tests`, run across its eight suite configurations). Unit tests are named where they are the main evidence.

## What Works

| Feature | Integration tests | Upstream suite | Other evidence | Server |
| --- | --- | --- | --- | --- |
| Basic Services, JSON and raw input and output, non-UTF-8 text substituted on the way out | `BasicServiceTest`, `NonUtf8Test` | every case; `Ingress` (header pass-through, raw handler) | unit `EndpointRoutingTest` | |
| Service definitions: attributes, builders, docs and metadata, invocation configuration and retry policy metadata in discovery (manifest v2–v5) | `DiscoveryTest` | every deployment | unit `DiscoveryTest`, `TestServicesDiscoveryTest`, `ServiceDefinitionConstructionTest` | |
| Terminal errors with status code and metadata | `TerminalErrorTest`, `NonUtf8Test` | `UserErrors` | unit `TerminalErrorTest` | |
| `ctx.run`: replay, retry policies, retry-after delays, terminal failure once exhausted | `RunTest`, `RetryTest` | `RunRetry`, `RunFlush` | unit `ContextRunRetryTest` | |
| Pause (`PauseError`; run policy `OnMaxAttempts::Pause`) ([Pause](decisions/0021-pause.md)) | `PauseTest` | none exist (e2e's `PauseResume*` are server tests) | extension `ExtensionPauseTest`; unit `PauseTest` | 1.8 |
| Calls, sends, delayed sends, idempotency keys, call/send headers | `CommunicationTest` | `ServiceToServiceCommunication`, `CallOrdering` | unit `ClientRequestTest`, `KeyedClientTest` | |
| Scope and limit key on calls, sends and attach ([Scope and Limit Key](decisions/0024-scope-and-limit-key.md)) | `ScopeTest` | `ServiceToServiceScopeConcurrency` | extension `ExtensionScopeTest`; unit `ScopeAndLimitKeyTest` | 1.8 |
| Invocation handles: attach (`output()`), get output (`peekOutput()`), cancel | `InvocationHandleTest`, `InvocationOutputTest` | `Cancellation` | unit `InvocationHandleTest` | |
| The invocation request (`$ctx->request()`: id, headers, raw body, idempotency key, scope, limit key) | `RequestTest` | | unit `RequestTest` | idempotency key, scope, limit key: 1.8 |
| Virtual Objects: state get, set, clear, keys, clear all; lazy state | `VirtualObjectTest` | `State`, `KillRuntime`, `StopRuntime`, the `lazyState*` suites | unit `VirtualObjectTest` | |
| Selective state preloading (`statePreload: true \| false \| list`) ([Selective State Preloading](decisions/0020-selective-state-preloading.md)) | `StatePreloadTest` | none exist | unit `StatePreloadTest` | key list: 1.8 |
| Workflows and durable promises (get, peek, resolve, reject) | `WorkflowTest` | `WorkflowAPI` | unit `DurablePromiseTest` | |
| Durable timers | `TimerTest` | `Sleep`, `SleepWithFailures`, the `persistedTimers` suite | | |
| Awakeables | `AwakeableTest` | `Combinators` | unit `AwakeableTest` | |
| Named signals ([Named Signals](decisions/0019-named-signals.md)) | `SignalTest` | `Signals`, `SignalsFuzz`, `Combinators.signal*` | unit `ContextSignalTest`; core VM `signals` tests | |
| Durable futures: all, race, any, allSettled, select, unordered ([Durable Futures](develop/concurrency.md)) | `FutureTest` | `Combinators` | unit `ContextFutureTest`, `DurableFutureTest`, `DurableFuturesUnorderedTest` | |
| Deterministic random, UUID and `Context::now()` | `RandomTest` | | unit `ContextTimeTest` | |
| Non-determinism detection (a replay that diverges from the journal) | | `NonDeterminismErrors` | extension `ExtensionInvocationTest`; core VM `invocation` tests | |
| Custom serializers: per payload, endpoint and service defaults, journal-value codecs, per context operation | `PayloadMappingTest` (per payload, from the ingress and from a handler) | | unit `ContextCustomSerializationTest` | |
| DTO and enum mapping, JSON Schema inference, `#[JsonName]`, Symfony and Valinor JSON Payload Adapters ([DTO Mapping](develop/serialization.md#dtos-and-enums)) | `PayloadMappingTest` (DTO, enum, null and binary payloads through `HandlerSerdes` from the ingress and from a handler; the schemas Restate registered) | | unit `DtoRuntimeMappingTest`, `Reflection/`, `JsonSchema/`, adapter tests, `HandlerSerdesTest` | |
| Runtime execution hooks and error mapper ([Runtime Execution Hooks](decisions/0017-runtime-execution-hooks.md)) | `HooksTest` | | unit `RuntimeExecutionHooksTest`, `RunInterceptorTest` | |
| Service instances per invocation from a factory or PSR-11 container ([Handler Instantiation](decisions/0018-handler-instantiation.md)) | `HandlerFactoryTest` | | unit `HandlerFactoryTest` | |
| Ingress client for non-handler code (`Restate\Ingress\`, package `restate/ingress`, any PSR-18 client, no extension needed) ([Ingress Client](decisions/0022-ingress-client.md)) | `IngressClientTest`, `IngressScopeTest` | | unit `Ingress/` incl. `IngressClientWithoutExtensionTest` | scopes: 1.8 |
| Test harness for users (`Restate\Testing\`, package `restate/testing`) ([Test Harness](decisions/0023-test-harness.md), [Native Restate for the Test Harness](decisions/0038-native-restate-for-the-test-harness.md), [External Test Environments](decisions/0039-external-test-environments.md)) | the whole integration suite runs on it, with Restate in a container (Linux CI) and as a process (`RestateProcess`, macOS CI, no Docker); its behaviour tests also against the FrankenPHP and Restate of a devenv profile (`ExternalEndpoint`, `ExternalRestate`; Linux and macOS CI); `examples/testing/GreeterTest.php` | | unit `Testing/` | |
| Request identity validation (`identityKeys`; sdk-shared-core's verifier, as in the TypeScript and Python SDKs; configured keys fail closed: an empty list, an empty key or `false` from `getenv()` is rejected when the endpoint is built) ([SERVING.md](serving.md#request-identity-validation), [Request Identity Through sdk-shared-core](decisions/0028-request-identity-shared-core.md)) | `RequestIdentityTest` (Restate signing with a generated key: calls succeed; an endpoint trusting another key fails registration with its `401`) | `ProxyRequestSigning` (the test services take its `E2E_REQUEST_SIGNING` key; fails with a wrong key) | extension `IdentityVerifierTest`, core `tests/identity.rs`; unit `EndpointRoutingTest` (incl. the rejected key configurations), `PsrEndpointHandlerTest`, `EndpointLoggingTest` (fake verifier) | |
| Serving: `Endpoint::serve()` under `php -S`, FrankenPHP and php-fpm (behind nginx over HTTP/1.1 and h2c, and Caddy `php_fastcgi` over h2c, OPcache on), Caddy h2c in front of `php -S`, PSR-15 handler; front-door hazards at their defaults ([SERVING.md](serving.md#php-fpm-and-fastcgi), [PHP-FPM Serving](decisions/0034-php-fpm-serving.md)) | the whole suite (`php -S`, FrankenPHP, three php-fpm front doors); `ServingTest`; nightly `ServingHazardsTest` | the whole suite (Caddy h2c image) | unit `PsrEndpointHandlerTest`; Slim and Mezzio examples | |
| Stray output: what PHP prints during a request (an `echo`, a displayed warning or deprecation) is discarded and logged; output sent before the endpoint ran is reported with its origin and fix ([Stray Output](decisions/0032-stray-output.md)) | `StrayOutputTest`, `LargeBodyTest` (display_errors × enable_post_data_reading × 1, 9, 31 MiB inputs; values over the 32 MiB message limit) | | unit `EndpointStrayOutputTest` (incl. the `post_max_size` startup warning under `php -S`) | |
| SDK logging to a PSR-3 logger (`EndpointBuilder::logger()`), replay-aware handler logging (`Context::logger()`), shared-core's log events forwarded ([SDK Logging](decisions/0027-sdk-logging.md)) | `LoggingTest` (a failed attempt logged with its invocation id; the handler's record not repeated on replay) | | unit `EndpointLoggingTest`, `ReplayAwareLoggerTest`, `CoreLogForwarderTest`; extension `SharedCoreLogsTest` (a journal mismatch reaches the logger); core `logs` tests and VM `logs` tests | |

"Server: 1.8" means the feature needs Restate 1.8 (service protocol v7 or manifest v5); what happens on 1.7 is under [Restate 1.7](#restate-17).

## Parity with the Official SDKs

The upstream suite is the runtime comparison: the official SDKs run the same `restatedev/e2e` suite, and PHP passes all of it. For the API surface, the feature decision docs compared each feature against the TypeScript (`492920d`), Java (`6b0a96c`) and Python (`377d634`) SDK sources; the differences that matter:

| Area | PHP | Official SDKs |
| --- | --- | --- |
| Named signals | `$ctx->signal($name)`; `$ctx->invocation($id)->signal($name)->resolve()/reject(TerminalError)` | Same in TypeScript and Java; Python resolves through `ctx.resolve_signal()`/`reject_signal()`. TypeScript also rejects with a string. |
| State preloading | `InvocationConfiguration(statePreload: true\|false\|list)`; replaces lazy state (no alias) | TypeScript `state: { preload }` and Python `state_preload`, both keeping a deprecated `enableLazyState`; Java has only `enableLazyState`. |
| Pause | `PauseError` from a handler, a `ctx.run` callback or an error mapper; run retry policies with `OnMaxAttempts::Pause` | TypeScript has `PauseError` for runs only (a handler-level one is retried); Java and Python only have the invocation retry policy's `onMaxAttempts`, which PHP emits too. |
| Scope and limit key | `Target::scoped()`, `$ctx->scope()`, `limitKey:`; also on attach targets and the ingress client | TypeScript, Java and Python have scopes and limit keys on calls and sends; none on attach targets. |
| Ingress client | `Restate\Ingress\IngressClient` over PSR-18; Java-style `SendResponse` and invocation handles | TypeScript `connect()` (with optional retries), Java `Client`. No signals route exists in Restate's ingress for any SDK. |
| Typed clients | None: requests name the handler (`$ctx->serviceClient('Greeter')->request('greet', …)`) | TypeScript types clients from the service definition value; Java deprecated its annotation processor for reflection proxies; Python passes the handler function. |
| Test harness | Restate in a container through the `docker`/`podman` CLI, or as a local `restate-server` process without a container runtime; the endpoint under `php -S` or in an image; a PHPUnit trait | All three use Testcontainers and serve in-process; TypeScript has `stateOf()`, Python always-replay and no-retry switches. |
| Hooks and tracing | Handler and run interceptors plus an error mapper (TypeScript's `asTerminalError`); no tracing integration (one can be built on the interceptors) | TypeScript hooks and `openTelemetryHook`; Java interceptors and an OpenTelemetry module; Python context managers (no run hook, no error mapper) and a tracing extra. |
| Logging | Any PSR-3 logger on the endpoint; attempt lifecycle at debug; structured `restate.invocation.*` context keys; `$ctx->logger()` drops records on replay | TypeScript `setLogger()` transport and `ctx.console`, lifecycle at info; Java log4j with thread-context keys, lifecycle at info; Python standard logging, `restate.getLogger()` drops records on replay. |
| Service instances | One instance per invocation from a factory or PSR-11 container | Handlers are functions (TypeScript, Python) or singletons (Java). |
| Serialization | JSON by default, custom `Serde`, a dependency-free DTO subset, Symfony and Valinor adapters | TypeScript Standard Schema/Zod, Python Pydantic/msgspec/dataclasses: the type or schema value drives both mapping and the published schema. PHP adapters publish no schema unless one is given. |
| Serving | Request/response only (`REQUEST_RESPONSE`) | Bidirectional streaming over HTTP/2 where the runtime allows; Lambda and fetch-style targets. |

## Known Gaps and Limitations

- **Bidirectional streaming is not shipped.** Discovery advertises `REQUEST_RESPONSE` only, so every suspension costs a new request and replay. FrankenPHP cannot stream request bodies to PHP; `amphp/http-server` over h2c can, and a throwaway prototype served Restate 1.8.1 as a `BidiStream` deployment ([Bidirectional Streaming](decisions/0007-bidirectional-streaming.md), which also designs the entrypoint). No supported entrypoint exists. Until then N sequential steps take N + 1 attempts with O(N²) replay work, and an invocation whose replay alone exceeds its abort timeout cannot finish ([Long Journals](serving.md#long-journals-cost-quadratic-replay)); the scale tests measure both ([Scale tests](../TESTING.md#scale-tests)).
- **Serving targets.** No framework adapters beyond the PSR-15 handler and the Slim and Mezzio examples, no Lambda or fetch-style target, no bundled PSR-7 implementation. FrankenPHP worker mode is tested ([FrankenPHP Worker Mode](serving.md#frankenphp-worker-mode)). PHP-FPM is tested behind nginx and Caddy with the documented settings; at their defaults the front doors fail every attempt above 4 KiB (Caddy), 16 KiB (Apache) or 1 MiB (nginx), and their read timeouts must cover the abort timeout ([PHP-FPM and FastCGI](serving.md#php-fpm-and-fastcgi)).
- **Ingress client:** no signals (Restate has no ingress route for them), no client-side retries. Symfony HttpClient cannot call handlers without input (it adds a content type to bodiless POSTs); use Guzzle.
- **Test harness:** no state inspection or mutation, no always-replay or retry-disabling switches (`RestateContainer::withEnvironment()` passes server settings).
- **DTO mapping** covers a safe subset (scalars, backed enums, constructor and property DTOs); arrays with item types, maps, generics, recursive DTOs and mixed class unions need explicit schemas, a custom mapper or a `Serde` ([DTO Mapping](develop/serialization.md#dtos-and-enums)). The Symfony and Valinor adapters publish no JSON Schema by default.
- **Direct `ServiceDefinition` construction** keeps its data but is not structurally validated.
- **Durable futures** are Restate futures, not PHP async primitives ([Durable Futures](develop/concurrency.md)). Combining n of them costs O(n²) per attempt, and a `select()` loop whose futures complete one per attempt O(n³) per invocation ([Wide Fan-out](serving.md#wide-fan-out-costs-quadratic-time-per-attempt)). The SDK makes one extension call per await (a tree await reports which of its handles completed, [Tree Awaits Report Their Completed Handles](decisions/0036-tree-await-completed-handles.md)); what remains is shared-core reducing the whole tree once per result it processes (in `all()` as in every `select()`), which [an upstream fix](https://github.com/restatedev/sdk-shared-core/pull/122) addresses but shared-core 8.7.2 does not include, and the O(r) pass over the r futures each call is given.

### Restate 1.7

Restate 1.8 is the minimum; 1.7 is best-effort and the integration suite passes on 1.7.14. What 1.7 (service protocol v6, manifest v4) cannot do:

- **Pause:** the attempt fails with 573 ("Pausing the invocation needs service protocol v7 …") and Restate keeps retrying.
- **Scope and limit key:** a scoped call, send or attach from a handler fails the attempt with 573 and is retried; the ingress client gets 400 ("scoped invocations require vqueues to be enabled"). `$ctx->request()` reports no idempotency key, scope or limit key.
- **State preloading with a key list:** registration fails with the SDK's message. `true` and `false` work.

The upstream suite needs Restate 1.8's admin API, so it does not run against 1.7.

## Supported Platforms

| | Supported | Evidence |
| --- | --- | --- |
| PHP | 8.4, 8.5 and 8.6 (`~8.4.0 \|\| ~8.5.0 \|\| ~8.6.0` in every `composer.json`), NTS and ZTS | 8.4 NTS: every suite above, under `php -S` and php-fpm. 8.4 ZTS: the integration and worker suites under FrankenPHP. 8.5 is less tested: the extension builds and loads on 8.5 NTS and ZTS, and CI runs `composer check` on 8.5 and the integration suite under FrankenPHP on 8.5 ZTS. 8.6 (8.6.0) is the least tested: `composer check` (unit and extension suites included) passes on 8.6 NTS, the extension's Nix checks load it into 8.6 NTS, and CI runs `composer check` on 8.6 and the FrankenPHP integration suite on 8.6 ZTS. No integration suite has run on 8.6 locally. The official PHP images of 8.6 are ZTS only (php-fpm included). The release modules for 8.6 load into `php:8.6-cli-trixie` and `php:8.6-cli-alpine` (ZTS) and, NTS, into nixpkgs' PHP 8.6 (arm64 glibc, checked by hand); the NTS musl ones have not been loaded anywhere. 8.6's `php -S` answers 413 for a body over `post_max_size` before the script runs, whatever `enable_post_data_reading` says ([Serving](serving.md#php-settings)), so `PhpCliServer` endpoints (`restate/testing`) and the large-body suite need `post_max_size` raised on 8.6. |
| Restate server | 1.8 or newer; 1.7 best-effort | Integration suite pinned to 1.8.1 (`RestateContainer::DEFAULT_IMAGE`) and run on 1.7.14; upstream suite on 1.8.1 ([Shared Core](decisions/0014-shared-core.md#server-compatibility)) |
| Protocol | Service protocol v5–v8 (`restate-sdk-shared-core` 8.7.2), discovery manifest v2–v5 | Restate 1.8 negotiates v7, 1.7 v6 |
| Operating system | Linux x86_64 and arm64, glibc 2.28+ and musl (the prebuilt binaries, [Prebuilt Extension Binaries](decisions/0030-prebuilt-extension-binaries.md)) | The suites run on Linux x86_64 with glibc (dev shell, Debian images). `.github/workflows/extension.yaml` cross-compiles every prebuilt target with Nix and loads each module into a clean official PHP image on a runner of its CPU. All 16 release modules load into the official images (8.4 and 8.5, NTS and ZTS, Debian and Alpine, x86_64 and arm64) and their bindings equal those generated from the images' own headers. Cross-compiled debug modules pass the extension suite and `ExtensionPanicTest` in `php:8.4-cli-trixie` (x86_64) and `php:8.5-zts-alpine` (arm64); the glibc 2.28 modules for PHP 8.4 also load into `php:8.4-cli-bullseye` (glibc 2.31, PHP 8.4.11), on both CPUs. No other suite runs on arm64. macOS has no prebuilt binaries, but CI builds the module there for nixpkgs' PHP and runs the extension's Nix checks (`.phpt` tests with PHP 8.4, 8.5, 8.6 and ZTS, an invocation per request in FrankenPHP's workers and php-fpm's children); no SDK suite runs on macOS. Windows is out of scope. |
| Build | `ext-php-rs` 0.16.5, Rust 1.95.0 (`extension/rust-toolchain.toml`), debug and release profiles | `bin/build-extension.sh [--release] [--php-config …]`; with Nix, `devenv build outputs.extension` and `composer build:pie` (`extension/flake.nix`, [TESTING.md](../TESTING.md#rust-code)) |
