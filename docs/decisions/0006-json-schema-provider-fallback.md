# JSON Schema Provider Fallback

## Status

Accepted

## Context

Schema providers could be composed in two ways: endpoint options accepting several providers, and JSON Schema documents themselves composing schemas with keywords such as `anyOf`. A provider return type that uses `null` for "no opinion" and `true` for intentionally unknown JSON Schema Metadata overloads both.

## Decision

Endpoint JSON payload options configure a single schema provider. Providers return `JsonSchemaMetadata` for known or intentionally unknown metadata and throw `UnsupportedJsonSchemaException` when they do not apply. Ordered fallback is explicit through `FallbackJsonSchemaProvider`, and zero-config SDK behavior comes from `JsonPayloadOptions::defaults()` including `BuiltinJsonSchemaProvider`; `JsonPayloadOptions::new()` starts blank.

## Consequences

Discovery does not own built-in schema inference as a hidden fallback. Explicit payload schemas remain authoritative, unknown metadata stops fallback, unsupported providers fall through only inside an explicit fallback provider, and users who replace JSON payload options own the schema-provider chain they configure.
