# Valinor JSON Payload Adapter

## Status

Accepted

## Context

Valinor support could be modeled as a Custom Serializer, as examples only, or as a JSON Payload Adapter in the SDK. The SDK glossary distinguishes bytes-level Custom Serializers from JSON Value Mapping, and the existing JSON payload mapping decision keeps runtime mapping and JSON Schema Metadata provider responsibilities separate. The Symfony bridge already uses this adapter shape for external DTO mapping.

## Decision

Ship a first-party optional Valinor bridge in this repository under `Restate\Bridge\Valinor`. The bridge is a JSON Payload Adapter, not a Custom Serializer: it maps decoded JSON-compatible PHP values to DTOs through a user-configured Valinor `TreeMapper`, normalizes DTOs through a user-configured Valinor array `Normalizer`, and leaves bytes-level JSON serialization to the SDK JSON payload path.

The first adapter is a single `ValinorJsonPayloadAdapter` implementing both `JsonValueMapper` and `JsonSchemaProvider`. It supports named DTO class handler payload types, wraps Valinor mapping and normalization failures as `JsonMappingException`, checks output DTO instances before normalization, and provides conservative unknown JSON Schema Metadata for Valinor-handled DTOs unless explicit handler schema metadata is supplied.

## Consequences

Valinor remains an optional runtime dependency: `restate/sdk` suggests it, and the repository uses it in `require-dev` for the bridge tests. Accurate schema metadata for Valinor-configured DTO wire shapes must come from explicit Restate payload schemas or a future richer schema provider, because the Valinor mapper/normalizer configuration used for runtime DTO mapping is not itself Restate JSON Schema Metadata.
