# Symfony JSON Payload Adapter

## Status

Accepted

## Context

Symfony Serializer support could be modeled as a Custom Serializer, as a separate Composer package, as examples only, or as a JSON Payload Adapter in the SDK. The SDK glossary distinguishes bytes-level Custom Serializers from JSON Value Mapping, and the existing JSON payload mapping decision keeps runtime mapping and JSON Schema Metadata provider responsibilities separate.

## Decision

Ship a first-party optional Symfony bridge in this repository under `Restate\Bridge\Symfony`. The bridge is a JSON Payload Adapter, not a Custom Serializer: it maps decoded JSON-compatible PHP values to and from DTOs through a user-configured Symfony `NormalizerInterface&DenormalizerInterface`, and it leaves bytes-level JSON serialization to the SDK JSON payload path.

The first adapter is a single `SymfonyJsonPayloadAdapter` implementing both `JsonValueMapper` and `JsonSchemaProvider`. It supports named DTO class handler payload types, passes Symfony format `json`, supports separate normalization and denormalization context arrays plus an optional context resolver, wraps Symfony mapping failures as `JsonMappingException`, and provides conservative unknown JSON Schema Metadata for Symfony-handled DTOs unless explicit handler schema metadata is supplied.

## Consequences

Symfony packages remain optional runtime dependencies: `restate/sdk` suggests them, and the repository uses them in `require-dev` for the bridge tests. Accurate schema metadata for Symfony-configured DTO wire shapes must come from explicit Restate payload schemas or a future richer schema provider, because Symfony Serializer does not itself provide Restate JSON Schema Metadata generation.
