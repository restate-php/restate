# Context

## Glossary

### Discovery Manifest

Metadata published by a service endpoint during Restate registration. It describes the available services, handlers, and payload shapes that Restate can use for downstream capabilities.

Discovery fields are emitted according to the requested manifest version. The endpoint answers with the newest manifest version both Restate (its `Accept` header) and the SDK support, v2 to v5, and advertises the service protocol range the Backend reports. Definition documentation and user metadata are discovery-facing fields and should be omitted from manifest versions that do not support them.

### JSON Schema Metadata

Schema information attached to handler input and output payloads. Restate can use this metadata to understand payload shapes.

JSON Schema Metadata describes the payload's JSON Wire Shape when that shape is known, not necessarily the original PHP DTO property surface.

Primitive PHP payload types map directly to JSON Schema primitive types. Nullable payload types mean the JSON value may be `null`; they do not decide whether an HTTP request body is required.

Union payload types may be represented as a JSON Schema type array only when every union arm has a safe primitive or null mapping. Native PHP `array` and `iterable` remain unknown because they do not promise a JSON list; associative PHP arrays encode as JSON objects. `stdClass` maps to a generic JSON object. User-defined DTO classes are covered by DTO Schema Support rather than primitive inference.

Explicit handler JSON Schema Metadata is authoritative. Inferred metadata is produced only when a schema metadata source is configured to infer it, and never changes bytes-level serialization. DTO schema support may share a reflected shape with DTO mapping, but the schema metadata remains discovery-facing. Built-in inferred DTO schema metadata should be known only for DTO JSON Wire Shapes that are safe for the built-in DTO Mapping; mapping-unsafe DTO payloads should publish unknown metadata unless explicit handler JSON Schema Metadata or a custom provider supplies a known schema.

Each handler payload publishes at most one JSON Schema Metadata value. Choosing between multiple metadata sources is distinct from composing the JSON Schema document itself.

Unsupported or ambiguous PHP types should degrade to unknown JSON Schema Metadata rather than making the service definition invalid. Primitive inferred schemas should stay minimal and deterministic. DTO-generated schemas may use explicit raw JSON Schema overrides when PHP reflection cannot safely describe the JSON Wire Shape, such as array item types or map value types, but DTO-level generation hooks do not bypass the built-in endpoint inference gate by themselves. Inferred discovery metadata does not include JSON Schema dialect declarations by default.

Unknown JSON Schema Metadata is intentional when a payload is JSON but its JSON Wire Shape cannot be safely described. It is distinct from the absence of an applicable metadata source, and it is not a schema-provider fallback signal.

JSON Schema Metadata may contain a JSON Schema Document, but the metadata concept also covers the intentional unknown state.

### JSON Schema Document

A JSON Schema value that describes a known JSON Wire Shape for a handler payload. A JSON Schema Document may be a JSON object schema or a JSON Schema Boolean Schema, and it is distinct from unknown JSON Schema Metadata.

JSON Schema Documents are distinct from validating JSON values against a schema and from generating schemas from PHP types; validation and generation are separate capabilities that may consume or produce documents.

User-provided JSON Schema Documents may use the complete JSON Schema vocabulary for their dialect. SDK-inferred documents may describe a narrower subset when only a safe JSON Wire Shape is known.

### JSON Schema Dialect

The versioned JSON Schema vocabulary and semantics a JSON Schema Document declares. The SDK builds and generates only Draft 2020-12, the dialect of the OpenAPI 3.1 document Restate derives from discovery. A JSON Schema Document a user supplies as decoded JSON may declare another dialect; it is published unchanged.

### JSON Schema Boolean Schema

A schema value represented by JSON `true` or `false`.

### JSON Schema Subschema

A nested schema value inside a JSON Schema Document. Subschemas may be JSON objects or JSON Schema Boolean Schemas.

### JSON Wire Shape

The JSON-compatible payload structure visible to Restate after DTO Mapping has been applied and before bytes-level JSON serialization.

### DTO Schema Support

Richer JSON Schema Metadata and runtime mapping for user-defined PHP classes used as handler input or output payloads. DTO schema support feeds handler payload metadata; it is not a separate service definition model.

DTO schema support is separate from service definition ergonomics and can evolve without changing the meaning of service definitions.

When JSON Schema Metadata is generated from a DTO class, it is accurate only when the DTO class metadata matches the payload's JSON Wire Shape.

### Mapping-Safe DTO Shape

A user-defined DTO shape whose JSON-compatible fields can be converted by built-in DTO Mapping without guessing about arrays, objects, recursion, or ambiguous unions.

Built-in inferred DTO schema metadata may be known for Mapping-Safe DTO Shapes; other DTO shapes require explicit handler JSON Schema Metadata or a custom provider to publish a known schema, and require a custom mapper for runtime DTO Mapping.

A mapping-unsafe DTO payload that reaches runtime without a custom mapper is a service-side DTO Mapping capability failure. Bad request failures are reserved for JSON values that do not fit a mapping-safe payload shape.

### DTO JSON Schema Generation

Derivation of a JSON Schema Document from PHP DTO class metadata. DTO JSON Schema Generation describes the DTO's intended JSON Wire Shape. Built-in discovery schema inference delegates DTO class payloads to DTO JSON Schema Generation, while handler payload selection, runtime DTO Mapping, and bytes-level serialization remain separate concerns.

DTO JSON Schema Generation may describe DTO shapes that are broader than Mapping-Safe DTO Shapes. Using a generated DTO schema as built-in inferred endpoint metadata is gated by whether the payload has a Mapping-Safe DTO Shape; after that gate passes, DTO JSON Schema Generation remains the source of the JSON Schema Document.

### DTO Mapping

Runtime conversion between JSON-compatible handler payload values and PHP DTO objects for handler input and output.

DTO mapping is separate from bytes-level serialization. Serialization converts payload bytes to and from PHP values; DTO mapping converts JSON-compatible PHP values to and from typed DTO objects.

### JSON Value Mapping

Runtime conversion and compatibility checking between decoded JSON-compatible PHP values and declared handler payload types.

JSON Value Mapping includes DTO Mapping and scalar type compatibility checks. It does not replace Custom Serializers; it runs after JSON bytes have been decoded and before JSON-compatible values are encoded.

### JSON Payload Adapter

An external integration that contributes JSON Value Mapping, JSON Schema Metadata, or both for handler payloads while leaving bytes-level JSON serialization to the SDK's JSON payload path.

JSON Payload Adapters are distinct from Custom Serializers: they operate on JSON-compatible PHP values rather than payload bytes.

Runtime mapping and schema metadata may come from different integrations when an application needs one tool for DTO Mapping and another tool for JSON Schema Metadata.

### Endpoint JSON Payload Configuration

Endpoint-scoped configuration that selects the JSON Value Mapping and JSON Schema Metadata sources for handler payloads.

For a given endpoint, runtime JSON Value Mapping and Discovery Manifest JSON Schema Metadata should use the same Endpoint JSON Payload Configuration.

### Custom Serializer

A user-provided bytes-level `Serde` serializer/deserializer attached to handler input/output metadata or supplied explicitly to a context operation. Custom serializers affect SDK encode/decode for the selected payload or operation and bypass JSON Value Mapping. Handler-level custom serializers can also affect discovery metadata; operation-level custom serializers are runtime-only choices and do not publish discovery metadata by themselves.

### Default Serializer

A serializer selected at a broader scope than an individual handler payload, such as endpoint-level or service-level defaults. They are set with `EndpointBuilder::defaultSerde()` or on a service, and are distinct from explicit per-payload custom serializers.

Default serializers are bytes-level `Serde` selections used when no explicit handler payload serializer or operation-level serializer is supplied. They do not replace JSON Payload Adapters, and they should remain distinct from Journal Value Codecs.

### Journal Value Codec

An endpoint-scoped reversible byte transform applied after serializer output and before journal/wire writes, then reversed before deserialization on replay or value reads. Journal Value Codecs are for opaque byte transforms such as compression or encryption; they do not define logical PHP value mapping, content types, or JSON Schema Metadata.

### OpenAPI Definition

An API description produced by Restate after a service endpoint is registered. PHP services provide discovery metadata rather than producing OpenAPI definitions directly.

### Official SDK Feature Parity

Alignment between the PHP SDK and the reference Restate SDK capability surface. A capability is feature-parity complete only when the public PHP API exists, the backend protocol behavior exists, and local evidence verifies the behavior.

Upstream Conformance Evidence complements feature parity but does not establish it: the upstream suite exercises the protocol through the test services, not every public PHP API.

### Upstream Conformance Evidence

Evidence from the upstream Restate SDK test suite. Exclusions (`test-services/exclusions.yaml`) can classify failures, but an excluded case is not evidence that a feature works.

### Integration Harness

The repository's `integration` PHPUnit suite setup in `tests/Integration/Support`: the Test Harness (shared, one Restate server and one PHP endpoint per test run) serving the repository's test services, which the suite drives over HTTP. The endpoint serves through the PHP extension, under `php -S` on the host by default, or in a container: FrankenPHP running the Thread-Safe Extension Build (classic or worker mode), or php-fpm behind nginx or Caddy (`RESTATE_PHP_TEST_SERVER`); Restate runs in a container by default, or as a process (`RESTATE_PHP_TEST_RESTATE`). It is the local regression net for the Extension Backend and for the Test Harness itself.

### Test Harness

`Restate\Testing\`: the public API for testing services against a real Restate server. `RestateTestEnvironment` starts Restate in a container (`RestateContainer`: `docker` or `podman` CLI, no Testcontainers) or as a process of the harness (`RestateProcess`: `restate-server` on the test host, no container runtime), serves the user's endpoint entrypoint (`PhpCliServer`: `php -S` with the extension loaded; or `EndpointContainer`: an image), registers it, and hands out an Ingress Client; the PHPUnit trait `InteractsWithRestate` shares one environment per configuration and test run and dumps the logs of failed tests. It also restarts, pauses, kills and signals either side and reads Restate's admin SQL (`query()`, `invocationRow()`, `waitForInvocationStatus()`), for failure tests. Framework-agnostic except for that trait; the `restate/testing` package, which needs neither `restate/sdk` nor the extension in the test process (see `docs/decisions/0023-test-harness.md` and `docs/decisions/0038-native-restate-for-the-test-harness.md`).

### Fault Plan

Test support (`tests/Support/Faults/`): the faults a test wants for one invocation, by attempt number (kill before output, kill or truncate after n frames, kill after the response, exit, delay, replace the response), written to the state directory the test shares with the endpoint and applied by the endpoint's `FaultInjector` around `Endpoint::handleRequest()`. Frame boundaries come from parsing the response, never byte offsets. A kill only ever hits a `php -S` worker (never its master) or a php-fpm pool worker; elsewhere (FrankenPHP) it degrades to truncate-and-exit. Enabled by `RESTATE_PHP_TEST_FAULTS=1`.

### Workload

Test support (`tests/Support/Workload/`): realistic request bodies, journals as Restate sends them for the `WorkloadServices` handlers, one per request class (start, replay of n runs, eager state, large input, suspension, failing attempt), each with the frames a correct response has; sent in process (`InProcessDriver`, benchmarks) or as chunked HTTP without `Content-Length`, one request per connection (`HttpDriver`) or many at a time on kept-alive connections (`ConcurrentHttpDriver`, soak tests).

### Package

One of the Composer packages the SDK is released as, all developed in this repository under `packages/<name>/` and released together at one version (they require each other with `self.version`): `restate/sdk` (serving; requires the extension), `restate/common` (the types the others share: serdes, targets, terminal errors, the JSON Value Mapping, `Reflection\HandlerSerdes`), `restate/ingress`, `restate/testing`; `restate/ext-restate` is the name reserved for the extension's PIE package. Namespaces do not follow packages: `restate/common` and `restate/sdk` both map `Restate\`. The repository root (`restate/monorepo`) is development-only and replaces them all. Each package's `composer.json` lists exactly what its code uses (`composer check:packages`). See `docs/decisions/0029-package-split.md`.

### Extension Backend

The backend that carries real Restate traffic by driving `restate-sdk-shared-core` compiled into the PHP extension (module `restate`, file `restate.so`, `ext-restate` to Composer; built from `extension/extension`, loaded with `extension=restate`). It is the only backend, so there is no backend selection; building an endpoint without it throws `BackendUnavailableException`. See `docs/decisions/0012-single-native-backend.md`.

### Public API Boundary

The line between what users may rely on and what may change in any release. A type under `Restate\` is public unless a namespace segment below `Restate\` is `Internal` or it is in `Restate\Extension\`; internal types carry `@internal`, and no public type names one in its public API. Handler-facing types (contexts, durable futures, awakeables, durable promises, invocation handles) are public interfaces with internal implementations. A PHPStan rule enforces it; see `docs/decisions/0016-public-api-boundary.md`.

### Backend Contract

The internal seam between the SDK's runtime classes and the Extension Backend: `Restate\Internal\Backend` (creates an invocation, reports the protocol range, creates the endpoint's request identity verifier, `Backend\IdentityVerifier`), `Restate\Internal\Backend\Invocation` (one request: input, output, response head) and `Restate\Internal\InvocationOperations` (what a handler's context does: calls, state, runs, awaits). Its arguments and results are typed values in `Restate\Internal\Backend` — `AwaitResult`, `NotificationResult`, `RunCompletionStatus`, `CallTarget`, `AwaitTree`, `InvocationInput`, `ResponseHead`, `AwakeableHandle` — plus the public `AttachTarget` and `RunRetryPolicy`; payloads are bytes as the journal holds them. The extension backend builds these values from the Extension Surface's arrays in one class (`Extension\SurfaceValues`); `Restate\Internal\ResultDecoder` is the one place that turns await states and results into what a context operation returns or throws. The unit tests stand in for it with one scripted fake (`tests/Support/ScriptedInvocation.php`) that returns typed values; `tests/Extension/BackendContractTest.php` checks that the extension produces every typed variant.

### Rust Core

The Rust crate `extension/core`: the single implementation of every operation the Extension Backend performs on a `restate-sdk-shared-core` VM (VM construction, every `sys_*` call, awaiting, notification decoding, run completion, input validation, error classification). It has no PHP dependency; the extension crate is glue that converts arguments and results around it. See `docs/BACKEND_ARCHITECTURE.md`.

### Extension Surface

Everything the PHP extension defines, all in the internal `Restate\Extension` namespace, which no package may declare anything in (`composer check:packages`): the `Vm` class (one invocation's VM, owned by the PHP object; awaits take a handle or an Await Tree), the `IdentityVerifier` class (sdk-shared-core's request identity verifier for an endpoint's keys), the `ExtensionException` class every failure is thrown as (with a `category` such as `protocol` or `journal_mismatch`), the two handshake functions `version()` and `contract_version()`, `protocol_versions()` (the service protocol range the core speaks), and, only in builds with the `diagnostics` cargo feature, `diagnostics()` (live VMs and Rust heap, for the soak suite). Values cross as PHP values (binary strings and arrays), never JSON or base64. `extension/restate.stub.php` describes it, and a test keeps the two identical. Only `Restate\Internal\Backend\Extension` uses it.

### Contract Version

The integer version of the Extension Surface, returned by `Restate\Extension\contract_version()`. The extension and the Composer package are installed separately, so constructing the extension backend checks that the loaded extension's contract version equals the one the package was written for (`Backend::CONTRACT_VERSION`) and otherwise fails with both versions and how to fix it. It is 1 until the first release; after that, an incompatible change to the Extension Surface bumps it in the Rust crate and the PHP backend together, at most once per release and only in a new minor version (`docs/EXTENSION_VERSIONS.md`). It is independent of the Extension Version.

### Extension Version

The version of the PHP extension itself: its crate version (`extension/extension/Cargo.toml`), returned by `Restate\Extension\version()` and `phpversion('restate')`, and the version Composer sees for `ext-restate`. It is also the version of every Package: the extension is released with them (see Release), and `restate/sdk` requires the extension of its own minor version (`~X.Y.0`). `docs/EXTENSION_VERSIONS.md` lists each one with the shared-core version, Contract Version and PHP versions it carries. Users and Composer see the Extension Version; the SDK decides compatibility by the Contract Version.

### Release

One version of the SDK, published by one manual run of `.github/workflows/release.yaml` on `main`: every Package and the extension at the version in the extension crate, after CI, the upstream suite and the extension builds pass on that commit. Each package directory and `extension/` is pushed to a read-only repository of its own (a Split Repository, which Packagist and PIE read; `.github/workflows/split.yaml` also pushes its `main` on every push to this repository's `main`, so `dev-main` follows it), tagged `vX.Y.Z` (the extension's bare `X.Y.Z`); this repository gets the release `vX.Y.Z`. See `docs/decisions/0031-release.md`.

### Thread-Safe Extension Build

The PHP extension compiled against a thread-safe (ZTS) PHP, as used by FrankenPHP and other threaded SAPIs. A module built for one thread-safety mode does not load into the other, so NTS and ZTS builds are separate artifacts of the same source.

### Prebuilt Extension Binary

A release build of the extension for one PHP minor, thread-safety mode, CPU and C library, packaged as `php_restate-<version>_php<minor>-<arch>-linux-<glibc|musl>-<nts|zts>.tgz` (`restate.so` inside) and attached to the GitHub release of the extension repository, where PIE (`pie install restate/ext-restate`) finds the one for the PHP it installs into. glibc builds are linked against glibc 2.28, so they load on newer ones too. `docs/decisions/0030-prebuilt-extension-binaries.md` has the targets and the release procedure.

### Production-like Serving Evidence

Runtime evidence that exercises the transport and process behavior Restate needs, such as an h2c front door, request/response invocation protocol handling, backend load checks, fail-fast startup, and documented limitations.

Production-like serving evidence does not mean the SDK or runtime path is recommended for production deployment.

### Service Definition

The canonical server-side contract for a Restate service, virtual object, or workflow and its handlers. PHP may provide service definitions through builder APIs or attribute-reflected classes; both are sources for discovery metadata.

Service definitions are distinct from typed clients. They describe the server-side handler surface; clients consume or mirror that surface but are not part of the service definition itself.

Service, virtual object, and workflow names are stable Restate identities and should be explicit in PHP. Handler names may default from PHP method names when no override is provided.

### Service Configuration Metadata

Descriptor information attached to a service definition rather than to an individual handler. PHP's definition ergonomics surface treats service name, service kind, definition documentation, user metadata, and invocation retry policy metadata as discovery-facing service configuration metadata.

Richer invocation configuration, such as timeouts, retention, ingress visibility, and state preloading, is Invocation Configuration Metadata.

### Definition Documentation

Human-readable Markdown-oriented text attached to a service definition or handler definition for discovery, Admin API, and generated OpenAPI consumers. PHP uses the manifest term `documentation` for this field.

Absent documentation is represented as `null`; provided documentation strings are emitted as supplied.

PHP documentation metadata is explicit. PHPDoc/docblock inference is not part of the service definition meaning.

### User Metadata

Custom string key/value metadata attached to a service definition or handler definition for discovery and Admin API consumers. User metadata is a strict string-to-string map; empty metadata is omitted from discovery output.

Service-level and handler-level user metadata are separate fields. Handler metadata does not inherit or merge service metadata.

### Typed Client

A PHP SDK client facade whose methods mirror a service, virtual object, or workflow interface. Typed clients improve authoring, IDE support, and static-analysis ergonomics while delegating serialization semantics to handler payload metadata and configured serializers.

The SDK ships no typed clients: requests name the handler through the generic service, virtual object, or workflow clients. A thin wrapper written by hand over them is possible (`Reflection\HandlerSerdes` gives it a handler's payload configuration), but it mirrors the handler surface without reading the service definition and can go stale silently.

### Ingress Client

`Restate\Ingress\IngressClient`: invokes services from code that is not a handler, through the Restate ingress over HTTP with a PSR-18 client the user supplies — call, send (delayed, idempotent), attach to an invocation (`attach()`, waits) or read its output (`output()`, an `Output` that is not ready while it runs; unlike the Invocation Handle's `output()`, which waits), submit and read workflows, resolve and reject awakeables. It needs neither the extension nor an endpoint, and is the `restate/ingress` package, which depends on `restate/common` only. The invocation's failure arrives as its `TerminalError`, anything else as an `IngressException`. Not a runtime client: those (`$ctx->serviceClient()` …) work inside a handler, through the context (see `docs/decisions/0022-ingress-client.md`).

### Service-Level Retry Policy

Retry behavior attached to a service definition or handler definition rather than to an individual durable `run` operation. PHP models this as `InvocationRetryPolicy` metadata and emits the discovery v4 retry policy fields when configured.

`Context::run` retry policy controls retries for a durable operation inside a handler; it does not define service-level retry behavior.

### Invocation Configuration Metadata

Registration metadata that changes how Restate invokes or retains service and handler executions, such as invocation timeouts, retention durations, ingress visibility, and state preloading.

Invocation configuration metadata is separate from discovery-only documentation and user metadata. It affects registration/runtime semantics and is emitted for discovery manifest v3+ through `InvocationConfiguration`.

### State Preloading

Which of a Virtual Object's or Workflow's state Restate sends with each invocation ("eager state"), set with `InvocationConfiguration::$statePreload` on the service or a handler: `true` all of it (Restate's default), `false` none ("lazy state": every read is a round trip, which in request/response mode suspends the attempt), or a list of keys (selective preloading: those keys eagerly, best-effort, the rest on read; discovery manifest v5, Restate 1.8). In the manifest it is `enableLazyState` plus `eagerStateKeysWhitelist`; Restate's admin API shows it as `state_preload_policy`. Plain services have no state and reject it (see `docs/decisions/0020-selective-state-preloading.md`).

### Advanced Handler Payload Metadata

Payload-level handler metadata beyond basic input/output content type, requiredness, schema, emptiness, and serializer selection. Examples include separate accepted input content types and whether to set the output content type when the serialized output is empty.

Advanced handler payload metadata is separate from definition documentation and user metadata. It is set with the `Restate\Attributes\Input` and `Output` attributes, or on a `Payload` (`withAcceptContentType()`, `withSetContentTypeIfEmpty()`).

### Runtime Execution Hooks

User code that runs around handler execution without being part of a handler: interceptors around every handler attempt (`HandlerInterceptor`) and around every executed `ctx.run` callback (`RunInterceptor`), configured on the endpoint and per service. Endpoint interceptors wrap service interceptors; the first registered is the outermost. A handler interceptor runs on every attempt, replaying ones included (`HandlerExecution::isReplaying()`); a run interceptor runs only when the callback executes, never on replay. Interceptors see the Invocation Request (including the attempt headers that carry the W3C trace context) but not the context, cannot change results, cannot swallow failures (they may replace them), and cannot stop a Suspension. Runtime Execution Hooks are separate from service definition metadata: they do not appear in the Discovery Manifest. See `docs/decisions/0017-runtime-execution-hooks.md`.

### Error Mapper

The Runtime Execution Hook that classifies an exception that is not a `TerminalError`, `RetryableError` or `PauseError`, thrown by a handler (after the handler interceptors) or by a `ctx.run` callback (after the run interceptors): it returns a `TerminalError` (no retries, with its status code and metadata), a `RetryableError` (retry, with its retry-after delay, code and metadata), a `PauseError` (Pause), or `null` (unchanged: retried). A service's error mapper is asked before the endpoint's. It never sees a Suspension.

### Suspension

The end of an invocation attempt because the handler awaits something that is not in the journal yet (a sleep, a call, an awakeable, a promise) and the request/response input is closed. The core writes a suspension message and closes the attempt; Restate re-invokes the handler, which replays the journal, once the awaited result exists. A scheduled `Context::run` retry, and a Pause from a `ctx.run` callback, end the attempt the same way.

In PHP the SDK unwinds the handler with the internal `SuspensionException`, an `\Error` that does not implement the `Restate\Exception` marker interface, so neither `catch (\Exception)` nor `catch (\Restate\Exception)` blocks see it. Once an attempt is suspended, every further context operation throws again and the handler's outcome is ignored (see `docs/decisions/0013-suspension-is-not-an-exception.md`). A Suspension is not a failure: it is neither retried nor reported as an error.

### Pause

An invocation that Restate keeps in status `paused` instead of retrying it, until someone resumes it (CLI, UI, or the admin API's `PATCH /invocations/{id}/resume`, optionally on a newer deployment); it then runs again, replaying its journal. The SDK asks for one with a `PauseError` thrown by a handler, by a `ctx.run` callback (the run gets no result and executes again on resume) or returned by an Error Mapper, and with a `ctx.run` retry policy that pauses once exhausted (`OnMaxAttempts::Pause`). Restate itself pauses when an invocation retry policy with `onMaxAttempts: PAUSE` gives up. Needs service protocol v7 (Restate 1.8); on 1.7 the attempt fails with 573 instead and is retried. Not a Suspension: a suspended invocation resumes by itself once what it waits for exists (see `docs/decisions/0021-pause.md`).

### Scope

A namespace for invocations, virtual object instances and workflow runs (for example one per tenant) that is part of the target's identity: an object or workflow key, and an idempotency key, name a different instance in each scope and outside any scope, and Restate co-locates everything in one scope. It is also what the cluster's concurrency rules (the rule book) apply to. The SDK sets it on the target (`Target::scoped()`, `$ctx->scope()`, `$ingress->scope()`) of calls, sends and attach targets; the callee reads it from `$ctx->request()->scope`. 1 to 36 characters from `[a-zA-Z0-9_.-]`. Needs service protocol v7 (Restate 1.8); on 1.7 a scoped call or send fails the attempt with 573 and is retried (see `docs/decisions/0024-scope-and-limit-key.md`).

### Limit Key

The concurrency limit key of one call or send within its Scope: one or two levels (`user-42`, `eu/user-42`) that Restate's concurrency rules match below the scope. Not part of the target's identity: the same scope and key with different limit keys address the same instance. Set per request (`limitKey:`, `ClientRequest::limitKey()`, `CallOptions`/`SendOptions`), needs a scoped target and protocol v7; the callee reads it from `$ctx->request()->limitKey`.

### Await Tree

What one await waits for: a handle, or a combinator over child trees — first completed, all completed, first succeeded or all failed, all succeeded or first failed (shared-core's `UnresolvedFuture`). The core's `AwaitTree` and the extension's `Vm::doAwaitTree(['all_completed', [$handle, …]])` take one; the await completes once the tree, or a combinator nested in it, resolves, and reports which of the tree's handles then completed (what `isCompleted()` tells per handle). The tree is also the await point of a Suspension: on protocol v7 Restate resumes the invocation only when the tree can make progress. `Context::all()`, `allSettled()`, `race()` and `select()` await one tree for the whole set; PHP still evaluates every result itself.

### Signal

A named, durable message to one invocation, identified by the target's invocation id and a name; the target needs no registration. A handler waits for the next delivery with `Context::signal($name)`, a `DurableFuture` like any other (combinable, journaled as nothing until a delivery arrives); another handler sends one with `$ctx->invocation($id)->signal($name)->resolve($value)` or `->reject(TerminalError)` through a `SignalHandle`. Each delivery is received once, in delivery order, whether it arrived before or after the wait. Unlike an awakeable it has no id of its own to hand out, and unlike a durable promise it belongs to an invocation rather than a workflow and can be delivered more than once. Not to be confused with the built-in cancel signal, which the core handles internally (see `docs/decisions/0019-named-signals.md`).

### Invocation Handle

A handle on another invocation: the one a `send()` / `sendAfter()` started, or an existing one by invocation id (`Context::invocation()`) or attach target (`Context::attach()`: id, idempotency key, workflow). `output()` attaches to it and waits for it to finish (the protocol's `AttachInvocation`, `attach()` in the TypeScript and Java SDKs); `peekOutput()` asks once and returns null while it is still running (`GetInvocationOutput`, Java's `getOutput()`). `cancel()` and `signal()` need its id. Not the integer handles the Backend Contract returns for pending results.

### Handler Input

The single optional payload argument accepted by a Restate handler after its context argument. Restate handler definitions do not model multiple independent input payload parameters.

If a handler has no payload argument, its input is empty: callers are not expected to send a request body or content type.

A Restate handler is expected to declare a Restate context as its first argument. Definitions with multiple payload arguments are invalid rather than multiple-input handlers.

### Handler Context

The first argument of every handler: what the handler can do through Restate. The contexts are public interfaces with the names and hierarchy of the TypeScript and Python SDKs — `Context` (service handlers), `ObjectSharedContext extends Context` (shared virtual object handlers: key and state reads), `ObjectContext extends ObjectSharedContext` (exclusive virtual object handlers: state writes), `WorkflowSharedContext extends ObjectSharedContext` (shared workflow handlers: durable promises) and `WorkflowContext extends WorkflowSharedContext, ObjectContext` (the workflow's `run` handler). Their implementations are internal (`Restate\Internal\Context`); the service registry creates the one matching the handler kind for each invocation attempt, so a handler cannot be given a context with more capabilities than its kind allows; building a service definition rejects a handler whose context parameter asks for more.

### Invocation Request

What a handler's context tells it about the request that started the invocation: `Context::request()` returns a read-only `Restate\Request` with the invocation id, the target (service, handler, key), the invocation's headers (as the caller sent them to Restate), the attempt headers (the HTTP headers of the current attempt's request from Restate, which change between attempts), the raw input bytes (before the input serializer and the Journal Value Codec), and the idempotency key, scope and limit key the invocation was submitted with. It mirrors `ctx.request()` of the TypeScript SDK. The last three come from the core's `sys_input`, which has them from service protocol v7 (Restate 1.8) on.

### Handler Metadata

The stable core descriptor fields for a handler: its Restate handler name, handler kind, basic input payload metadata, basic output payload metadata, handler documentation, and user metadata. Handler metadata is used for discovery.

The PHP callable used to dispatch the handler is runtime binding information, not handler metadata.

### Handler Factory

What creates the instance of an attributed service class for each invocation attempt: a callable that receives the Invocation Request, or a PSR-11 container asked for the class (`EndpointBuilder::bind($class, $factory)`, `EndpointBuilder::container()`, `ServiceReflector::fromClass()`). It runs right before the handler, inside the handler interceptors; discovery and endpoint construction read the Handler Metadata from the class by reflection and never call it. It is runtime binding information, like the handler callable, not part of the Service Definition's discovery-facing metadata. See `docs/decisions/0018-handler-instantiation.md`.

### Handler Output

The single optional payload returned by a Restate handler.

If a handler explicitly returns `void` or `never`, its output is empty: Restate should not expect a response body. If a handler explicitly returns `null`, its output is JSON `null`. If a handler has no declared return type, the output shape is unknown rather than empty.

### Effective Handler Payload

The resolved form of a Handler Input or Handler Output after endpoint and service payload policies have been applied. Effective Handler Payload preserves the distinction between bytes-level serialization, JSON Value Mapping, and JSON Schema Metadata. The service registry resolves it once per handler, when it is built; invocation and the Discovery Manifest use the same resolved payloads.

### SDK Logging

What the SDK logs about its own work to the endpoint's PSR-3 logger (`EndpointBuilder::logger()`; none: nothing): each attempt's start, success and Suspension at debug, a terminal failure at info, a failed attempt, a failed `ctx.run` callback and a Pause at warning, a request that fails with 500 or is rejected with 401 at error, malformed discovery and invocation requests (415) at warning. Records about an invocation carry `restate.invocation.id` and `restate.invocation.target`; they are per attempt or per executed run, so replay does not repeat them. Handler code logs through `Context::logger()`, the endpoint's logger in a `ReplayAwareLogger`, which drops records while the handler replays. shared-core's own `tracing` events (mostly debug: a journal mismatch, suspensions, executed commands) go to the same logger from a configurable level (default info); the extension captures them per VM and the SDK forwards them after every VM call. See `docs/decisions/0027-sdk-logging.md`.
