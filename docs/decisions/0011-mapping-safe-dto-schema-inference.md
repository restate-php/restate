# Mapping-Safe DTO Schema Inference

## Status

Accepted

## Context

Built-in DTO Mapping and built-in DTO schema inference both reason about a DTO's JSON Wire Shape, and nothing makes their rules agree on their own: some DTOs can be described by DTO JSON Schema Generation while the built-in mapper cannot safely hydrate or normalize them at runtime.

The JSON payload mapping extension contract keeps JSON Value Mapping, JSON Schema Metadata, and JSON Payload Adapter behavior separate, keeps the built-in DTO shape model internal, and allows external adapters to provide mapping, metadata, or both. The schema-provider fallback decision also makes unknown metadata terminal for fallback; only unsupported providers fall through.

## Decision

Built-in endpoint DTO schema inference must first pass an internal Mapping-Safe DTO Shape gate before publishing a generated DTO schema as inferred JSON Schema Metadata. The gate uses the built-in DTO Mapping support boundary; mapping-unsafe DTO payloads publish unknown metadata in zero-config endpoint discovery and fail at runtime without a custom mapper.

DTO JSON Schema Generation remains broader than the mapping-safe subset and remains the schema document renderer after the gate passes. DTO-level generation hooks such as raw schemas or provider-style DTO schema methods do not bypass the endpoint inference gate by themselves; explicit handler JSON Schema Metadata and configured custom schema providers may still publish known schemas for mapping-unsafe DTOs.

JSON Payload Adapters remain on the public mapper and schema-provider contracts and are not required to adopt the internal mapping-safe classifier.

## Consequences

Built-in inference publishes unknown metadata for a DTO that is not safe for built-in DTO Mapping. Users who need known discovery metadata for those DTOs can supply explicit handler schemas or configure a custom schema provider, and should order custom providers before the built-in provider when they need to override the built-in unknown result.
