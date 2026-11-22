# JSON Payload Mapping Extension Contract

## Status

Accepted

## Context

DTO/class handler payload support needs both discovery metadata and runtime JSON value mapping. The SDK should remain dependency-free for the built-in safe subset, but users should be able to plug in Symfony Serializer, Valinor, or another solution.

## Decision

Expose JSON payload behavior as endpoint-level options with separate mapper and schema-provider interfaces. Schema providers return JSON Schema Metadata and use an unsupported exception as the only fallback signal; explicit fallback composition is modeled as another provider. Keep the built-in DTO shape model internal, and use it only to align dependency-free schema inference with dependency-free runtime mapping.

## Consequences

External adapters can provide runtime mapping, schema metadata, or both without adopting an SDK-owned DTO shape model. This preserves flexibility for Symfony/Valinor-style integrations, but it means a user can configure mapper and schema provider implementations that drift unless their adapter keeps them aligned.

Explicit payload schema metadata remains authoritative for discovery. Custom serializers remain authoritative for bytes-level payload conversion and bypass JSON value mapping.

`JsonPayloadOptions::defaults()` represents zero-config SDK behavior and includes the built-in schema provider. `JsonPayloadOptions::new()` starts blank so advanced users can opt out of built-in schema inference or compose their own provider fallback chain explicitly.
