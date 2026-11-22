# Endpoint JSON Payload Configuration Ownership

## Status

Accepted

## Context

Endpoint JSON Payload Configuration selects the JSON Value Mapping and JSON Schema Metadata sources for handler payloads. Runtime invocation and Discovery Manifest rendering must not accidentally read different endpoint configurations, while the direct `Discovery::manifestJson(..., jsonPayloads:)` helper still exists as an explicit low-level manifest-generation override.

## Decision

`ServiceRegistry` owns Endpoint JSON Payload Configuration after endpoint construction. `EndpointBuilder::jsonPayloads()` is the configuration entrypoint, and it passes the selected `JsonPayloadOptions` into the registry; public endpoint discovery and runtime invocation must both resolve JSON payload behavior from that registry-owned configuration. `JsonPayloadOptions` remains the immutable configuration value, `EffectiveHandlerPayload` remains the resolver/consumer, and no new public API or interface is introduced for this ownership clarification.

## Consequences

Public endpoint drift means discovery and runtime using different configuration sources; it does not mean the SDK proves a custom schema provider semantically matches an external mapper. Direct `Discovery::manifestJson(..., jsonPayloads:)` calls remain outside the public endpoint invariant and may intentionally override the registry-owned configuration.

Existing JSON payload mapping and schema-provider fallback constraints remain in force: explicit handler JSON Schema Metadata is authoritative, `unknown` metadata stops fallback, unsupported providers fall through only inside an explicit `FallbackJsonSchemaProvider`, `JsonPayloadOptions::defaults()` keeps zero-config built-in schema inference, and `JsonPayloadOptions::new()` starts blank so users own the fallback chain they configure.
