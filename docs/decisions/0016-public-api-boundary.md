# Public API Boundary

## Status

Accepted. Enforced by `tests/PHPStan/PublicApiBoundaryRule.php` as part of `composer analyse`.

## Context

PHP has no package visibility, so "public" has to be a rule about names, and a rule nothing checks erodes: handler-facing classes end up taking internal types in public constructors and factories, and runtime machinery no user touches (a service registry, the discovery renderer, header maps) ends up in the public namespace with signatures naming internal types. A boundary that is only a naming convention cannot be promised to anyone. The SDK is also several packages released together ([Package Split](0029-package-split.md)), which may share internals with each other but not with users.

## Decision

### What is public

Every class, interface, enum and trait under `Restate\` is public API **unless** it is internal by the rule in the next section. The public namespaces today:

| Namespace | What it holds |
| --- | --- |
| `Restate\` | Entry points (`Endpoint`, `EndpointBuilder`, `EndpointResponse`), service definitions and builders (`Service`, `VirtualObject`, `Workflow`, `ServiceDefinition`, `HandlerDefinition`, `ServiceType`, `HandlerType`, `InvocationConfiguration`, `InvocationRetryPolicy`), handler contexts (`Context`, `ObjectSharedContext`, `ObjectContext`, `WorkflowSharedContext`, `WorkflowContext`) and what they hand out (`DurableFuture`, `Awakeable`, `DurablePromise`, `InvocationHandle`, `SignalHandle`, `Request`, the clients `ServiceClient` / `ObjectClient` / `WorkflowClient` / `ClientRequest`, `Scope`, `DurableFuturesUnordered`), value objects (`Target`, `AttachTarget`, `CallOptions`, `SendOptions`, `RunOptions`, `RunRetryPolicy`, `OnMaxAttempts`, `Payload`, `JsonPayloadOptions`, `JsonSchema`, `JsonSchemaMetadata`), extension points (`Serde`, `JsonSerde`, `JournalValueCodec`, `NoopJournalValueCodec`, `JsonSchemaProvider`, `BuiltinJsonSchemaProvider`, `FallbackJsonSchemaProvider`, `JsonValueMapper`, `JsonSchemaContext`, `JsonValueMappingContext`, `PayloadRole`), Runtime Execution Hooks (`HandlerInterceptor`, `RunInterceptor`, `HandlerExecution`, `RunExecution`, `ErrorMapper`, `Suspension`) and exceptions (`Exception`, `TerminalError`, `RetryableError`, `PauseError`, `BackendUnavailableException`, `JsonMappingException`, `UnsupportedJsonSchemaException`, `DurableFutureAggregateException`) |
| `Restate\Attributes\` | `#[Service]`, `#[VirtualObject]`, `#[Workflow]`, `#[Handler]`, `#[Shared]`, `#[Input]`, `#[Output]` |
| `Restate\Bridge\Symfony\`, `Restate\Bridge\Valinor\` | The optional JSON payload adapters |
| `Restate\Ingress\` | The [Ingress Client](0022-ingress-client.md) for code that is not a handler (`IngressClient`, `ServiceClient`, `ObjectClient`, `WorkflowClient`, `ClientRequest`, `SendResponse`, `SendStatus`, `Invocation`, `Output`, `IngressException`). Package `restate/ingress`; it depends only on `restate/common` |
| `Restate\JsonSchema\` (with `Attributes\`, `Generation\`) | The Draft 2020-12 JSON Schema document model (`Schema`, `Document`, the final schema classes built by `Restate\JsonSchema`) and DTO schema generation ([JSON Schema Module](0025-json-schema-module.md)) |
| `Restate\Logging\` | `ReplayAwareLogger` |
| `Restate\Psr\` | `PsrEndpointHandler` |
| `Restate\Reflection\` | Service reflection (`ServiceReflector`; `HandlerSerdes`, the payload serdes a caller of a handler uses, built from its declared payload types and serdes) and `BuiltinJsonValueMapper`, the built-in DTO Mapping |
| `Restate\Testing\`, `Restate\Testing\PHPUnit\` | The [Test Harness](0023-test-harness.md) (`RestateTestEnvironment`, `PhpCliServer`, `EndpointContainer`, `RestateContainer`, `TestEnvironmentException`, `DeploymentRegistrationException`; the PHPUnit trait `InteractsWithRestate`). Only `Restate\Testing\PHPUnit\` references PHPUnit. Package `restate/testing` |

Handler-facing types are **interfaces** with the implementation under `Restate\Internal\Context\`: the five contexts, `DurableFuture`, `Awakeable`, `DurablePromise`, `InvocationHandle` and `SignalHandle`. Users never construct them; a test double of a context can implement them. Because PHP interfaces cannot carry static methods with bodies, the future combinators are context methods: `$ctx->all()`, `race()`, `any()`, `allSettled()`, `waitCompleted()` next to `select()`. A future passed to a combinator must come from the context it is called on.

The public API of a public type is its public members, plus its protected members unless the type is final (or an enum): method names, parameter names (PHP 8 named arguments make them part of a call), parameter, return, property and constant types (native and PHPDoc), and the behaviour the PHPDoc or the docs promise. Exception **messages** are not API (codes are: a `TerminalError`'s status code and metadata are).

### What is internal

A type is internal when a namespace segment below `Restate\` is `Internal`, or it is in `Restate\Extension\`:

| Namespace | What it holds |
| --- | --- |
| `Restate\Internal\` | The Backend Contract (`Backend`, `Backend\Invocation`, `InvocationOperations` and the typed values in `Backend\`), the extension backend (`Backend\Extension\`), the context implementations (`Context\`), `ServiceRegistry`, `Discovery`, `Route`, `HeaderMap`, `ResultDecoder`, `SuspensionException`, `BackendException`, `DeterministicRandom`, request identity and payload resolution, the serdes `Reflection\HandlerSerdes` hands out (`Client\`) |
| `Restate\JsonSchema\Internal\` | Keyword ordering and serialization helpers of the schema model |
| `Restate\Ingress\Internal\` | The ingress client's PSR-18 transport and response handling |
| `Restate\Reflection\Internal\` | The DTO shape and schema inference helpers behind `BuiltinJsonValueMapper` and `BuiltinJsonSchemaProvider` (`DtoShapeFactory`, `DtoShape`, `DtoField`, `JsonSchemaInferer`) and the handler payload inference of `HandlerDefinition` (`HandlerPayloadInferer`); the first three are in `restate/common`, the others in `restate/sdk` |
| `Restate\Testing\Internal\` | The test harness's command lines, child processes, HTTP requests, extension lookup and argument checks |
| `Restate\Extension\` | The Extension Surface: the classes and functions the PHP extension defines (`Vm`, `IdentityVerifier`, `ExtensionException`, `version()`, `contract_version()`, `protocol_versions()`), described by `extension/restate.stub.php`, every one tagged `@internal`. Only `Restate\Internal\Backend\Extension\` calls it (the internal `toArray()` of two public value objects borrows its array shapes, see below). It is versioned by the Contract Version, which the package checks against the loaded extension when an endpoint is built (`BackendUnavailableException` on a mismatch); it is not a user API, and the extension is not meant to be called directly |

Every internal type carries `@internal` in its PHPDoc. Internal types may change or disappear in any release, including a patch release, without notice. Tests in this repository use them freely.

A public type may not name an internal type anywhere in its public API, and may not extend or implement one. Where PHP's lack of package visibility forces something public that users must not call, it is tagged `@internal`, and only these are allowed:

| Symbol | Why it is public |
| --- | --- |
| `Restate\Endpoint::create()` | The endpoint is built from the internal `Backend`. `EndpointBuilder::build()` calls it with the extension backend and the unit tests with a fake one; the constructor is private. Use `Endpoint::builder()`, `fromServices()`, `serve()` or `psr()`. |
| `Restate\Endpoint::handleHttpRequest()` | `handleRequest()` with the request URI and the header list instead of a `$_SERVER` array. `Psr\PsrEndpointHandler` calls it, so a PSR-7 request's header names reach the endpoint as sent; through `HTTP_*` keys, `_` would turn into `-`. Use `handle()`, `handleRequest()` or `psr()`. |
| `Restate\AttachTarget::toArray()`, `Restate\RunRetryPolicy::toArray()` | The value as the Extension Surface takes it, typed with the stub's shapes (`@phpstan-import-type … from Restate\Extension\Vm`). Only `Internal\Backend\Extension\SurfaceValues` calls them; the value objects are built with their named constructors. |
| `Restate\Ingress\Invocation::__construct()` | Takes the ingress client's internal transport. `IngressClient::invocation()`, `idempotentInvocation()`, `workflowInvocation()` and a `SendResponse` hand out invocations. |
| `Restate\HandlerDefinition::signature()`, `isInputPayloadExplicit()`, `isOutputPayloadExplicit()` | What the endpoint reads from a handler definition beyond its public properties: the PHP signature the context check reads the declared context type from, and whether each payload was given (the endpoint applies the service's or endpoint's default serde only to payloads inferred from the signature). The signature stays a constructor parameter. |
| The constructors of `Restate\ClientRequest`, `ServiceClient`, `ObjectClient`, `WorkflowClient`, `Scope` and `Request`, and of `Restate\Ingress\ClientRequest`, `ServiceClient`, `ObjectClient`, `WorkflowClient` and `Scope` | They take the context or the `IngressClient` that sends their requests, and are built only by it: a handler gets clients from `$ctx->serviceClient()`, `objectClient()`, `workflowClient()` and `scope()`, and its `Request` from `$ctx->request()` (a service factory is handed one); code outside a handler gets them from `IngressClient`. |

The JSON Schema classes share their code through traits tagged `@internal` (`KeywordBackedSchema`, `KeywordedSchema`, `AnnotationKeywords`, …), not abstract base classes, so no `@internal` type sits in their hierarchy. A trait is not a type users can name in a signature; its methods are checked as part of each class that uses it.

### Compatibility promise

The promise covers every `restate/*` package at their shared version ([Package Split](0029-package-split.md)). Each package's `Internal` namespace is internal to the set of `restate/*` packages, which may use each other's internal types; users use public API only. Nothing is released yet. Concretely:

1. **Until the first tagged release:** the public API may break in any commit; breaking changes need no deprecation period, shim or alias. README, examples, docs and test services change in the same commit.
2. **From the first tagged release until 1.0 (`0.x`):** a `CHANGELOG.md` is started with that release. A public API break needs a minor version bump (`0.x` → `0.(x+1)`) and a changelog entry under "Breaking" that says what to change. Patch releases (`0.x.y`) do not break the public API. Deprecation shims stay optional: prefer the right shape, as now.
3. **From 1.0:** semantic versioning for the public API: breaks only in a major version, after a deprecation in a minor version where feasible.
4. **Interfaces the SDK implements** (the contexts, `DurableFuture`, `Awakeable`, `DurablePromise`, `InvocationHandle`, `SignalHandle`) may gain methods in a minor release, also after 1.0: implementing them for test doubles is supported, but such doubles may need new methods when the SDK grows. **Interfaces users implement** (`Serde`, `JournalValueCodec`, `JsonSchemaProvider`, `JsonValueMapper`, `HandlerInterceptor`, `RunInterceptor`, `ErrorMapper`, `JsonSchema\Generation\ProvidesSchema`, `ProvidesExamples`) only change in a release that may break the public API.
5. **The extension** is released with the packages at the same version ([Release](0031-release.md)); the Contract Version is the handshake between them. A new Contract Version needs a new minor version and a changelog entry, and the handshake error names both versions and what to upgrade.

### Enforcement

`tests/PHPStan/PublicApiBoundaryRule.php` is a PHPStan rule registered in `phpstan.neon`, so `composer analyse` (and therefore `composer check`) fails when:

- a type under an `Internal` namespace lacks `@internal`;
- a public type extends or implements an internal type;
- a public type's public API (as defined above) names an internal type in a native or PHPDoc type, including generics, callable and closure signatures, properties and constants;
- a public type or member is tagged `@internal` without being listed under `allowedInternal` in `phpstan.neon` (the list above; change both together).

It works on PHPStan's resolved types rather than source text, so `@param list<Internal\X>` and `@return \Closure(Internal\X): void` count as much as native types. `tests/Unit/PHPStan/PublicApiBoundaryRuleTest.php` runs it over `tests/PHPStan/Fixtures/public-api-boundary.php`, which contains one of each violation and the allowed cases, so the rule cannot silently stop reporting.

Users who run PHPStan on their own code also get PHPStan's built-in reports when they call an `@internal` symbol of this package from outside the `Restate` namespace.

## Consequences

- Users never construct handler-facing types: they are interfaces the SDK implements, and an endpoint is built with `Endpoint::builder()` and its shortcuts. Runtime machinery (`ServiceRegistry`, `Discovery`, `HeaderMap`, `DeterministicRandom`) lives under `Restate\Internal`.
- Whether a type is public is decided by its namespace alone; moving a type in or out of `Internal` is the one way to change it, and the rule checks the result.
- Narrowing the public surface further (as when the `Restate\Reflection\` inference helpers moved to `Restate\Reflection\Internal\`; see [JSON Schema Module](0025-json-schema-module.md) for another such narrowing) is a public API break and follows the promise above.
