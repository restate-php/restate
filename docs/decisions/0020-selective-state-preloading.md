# Selective State Preloading

## Status

Accepted. Respects the [Public API Boundary](0016-public-api-boundary.md); relies on the manifest facts in [Shared Core](0014-shared-core.md#protocol-versions).

## Context

Restate sends a virtual object's or workflow's state with each invocation ("eager state"), up to the invoker's eager state size limit. Lazy state sends none, so every read is a round trip to Restate; in request/response mode that round trip suspends the attempt and the read completes on the next one. Restate's older switch is all-or-nothing: the manifest v3 field `enableLazyState`.

Restate 1.8 adds **selective preloading**: discovery manifest v5 has one new optional field, `eagerStateKeysWhitelist` (a list of state keys, on services and handlers). With `enableLazyState: true` and a whitelist, Restate sends only those keys (best-effort, still within the eager state size limit) and serves the rest on demand. The admin API (Restate 1.8.1, `restatedev/e2e` `infra/src/main/openapi/admin.json`) stores it as `state_preload_policy`: `"All"`, or `{"Partial": ["key", …]}` (an empty list meaning fully lazy). Restate 1.7 reads manifests up to v4.

What the official SDKs do:

- **TypeScript** (`492920d`): object, workflow and handler options take `state: { preload: boolean | string[] }` (`StatePreload`). `enableLazyState` stays, `@deprecated` ("`enableLazyState: true` is equivalent to `state: { preload: false }`"); setting both throws ("Cannot set both the 'state' option and the deprecated 'enableLazyState' option"). `true` → `enableLazyState: false`; `false` → `enableLazyState: true`; a list → `enableLazyState: true` plus the whitelist, and an empty list → `enableLazyState: true` with no whitelist. Discovery on a manifest below v5 with a whitelist answers 500: "The code uses the new discovery feature 'eagerStateKeysWhitelist' but the runtime doesn't support it yet (discovery protocol negotiated version N)…". State options are dropped from stateless services.
- **Python** (`377d634`): `state_preload: bool | list[str]` next to the deprecated `enable_lazy_state`; both set raises. Same mapping (`resolve_state_preload`), same failure on manifest < v5 ("eagerStateKeysWhitelist is only supported in discovery protocol version 5").
- **Java** (`6b0a96c`): only `enableLazyState`; no selective preloading yet.
- **`restatedev/e2e` v2.3**: no preloading cases. The only lazy-state coverage is the `lazyState` and `lazyStateAlwaysSuspending` suites, which turn eager state off server-side (`RESTATE_WORKER__INVOKER__DISABLE_EAGER_STATE`) and run `State`; they pass.

shared-core needs no change: the start message's `partial_state` flag already tells the VM whether the state it got is complete (`EagerState { is_partial, … }` in `src/vm/context.rs`). With partial state, a key that is present is answered at once and any other key, including one that does not exist, becomes a `GetLazyState` command, which is what a whitelist needs.

## Decision

### Public API

`InvocationConfiguration::$statePreload` is the one option; there is no `enableLazyState` (the TypeScript and Python SDKs keep their old flag only for compatibility):

```php
new InvocationConfiguration(statePreload: true);              // preload all state (Restate's default)
new InvocationConfiguration(statePreload: false);             // lazy state: preload nothing
new InvocationConfiguration(statePreload: ['profile', 'cart']); // preload only these keys (Restate 1.8)
```

- Type `bool|list<string>|null`; `null` (the default) emits nothing and keeps the server default. The name follows Python's `state_preload` and the server's `state_preload_policy`; `preload` alone would be ambiguous among timeouts and retention fields. The value is TypeScript's `preload` (`true | false | string[]`).
- Valid on virtual objects and workflows, at service and handler level (a handler's setting overrides the service's, as for every invocation configuration field). A plain service, or a handler of one, throws `\InvalidArgumentException` when the definition is built: it has no state. (TypeScript silently drops it there; this SDK rejects invalid configuration instead, as it does for workflow-only retention outside a workflow `run` handler.)
- A list must be a list of strings, or the constructor throws `\InvalidArgumentException`. An empty list is lazy state, as upstream.

### Manifest

`InvocationConfiguration::manifestFields()`: `true` → `enableLazyState: false`; `false` and `[]` → `enableLazyState: true`; a non-empty list → `enableLazyState: true` and `eagerStateKeysWhitelist: [...]`. `Discovery::MAX_MANIFEST_VERSION` is 5.

### Servers without manifest v5

**Discovery fails; it does not degrade.** On a manifest version below 5, `Discovery::manifestJson()` throws for a service or handler with a non-empty key list, and the endpoint answers the discovery request with 500 and the message, so registration fails:

> Service PreloadedProfile preloads a list of state keys, which needs discovery manifest version 5 (Restate 1.8 or newer); this server negotiated version 4. Upgrade Restate, or preload all state (true) or none (false).

Restate 1.7.14 relays it in the admin error (`META0003 … got status code '500 Internal Server Error' … Body: {"error":"Service PreloadedProfile preloads a list …"}`). Both upstream SDKs that have the feature fail the same way. Degrading to fully lazy state would keep reads correct, but it would silently turn every read of the hot keys into a suspension: a performance change the user did not ask for and would only notice in production. `true`, `false` and `[]` need only manifest v3, so they work on Restate 1.7.

**Server minimum:** a key list needs Restate 1.8 (already the minimum supported server); booleans work on 1.7.

## Evidence

- Unit `StatePreloadTest` (14): manifest fields for each value, unset leaves nothing, invalid lists, v5 output on objects and workflows with handler overrides, refusal on v4 for service- and handler-level lists, booleans and `[]` on v4, plain service and service handler rejected.
- Integration `StatePreloadTest` (service `PreloadedProfile` in `tests/Integration/Services/state_preload.php`, `statePreload: ['hot']`, with a `readEager` handler overriding it with `true`; served by a second endpoint under `/state-preload` that the test registers, so Restate 1.7 can still register the main one):
  - Restate 1.8.1: the admin API stores `state_preload_policy` `{"Partial": ["hot"]}` for the service and `"All"` for `readEager`. Reading `hot` returns in the first attempt; reading `cold` or a missing key returns in the second (the lazy fetch suspended the first); `readEager` reads `cold` in the first attempt.
  - Restate 1.7.14: registration fails with the message above, and the service stays unknown to the ingress (404).
- `LazyCounter` (test services and `VirtualObjectTest`) uses `statePreload: false`.

## Consequences

- Users on Restate 1.7 who configure a key list find out at registration, with the fix in the message.
- The whitelist is best-effort: a whitelisted key that does not fit in the eager state size limit, or does not exist, is fetched on read. Handlers must not assume a listed key is free to read.
- If Java or the e2e suite add preloading cases later, the test services need nothing: the e2e services do not configure state preloading.
