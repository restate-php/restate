# JSON Schema Inference Dependency

## Status

Accepted

## Context

The PHP SDK needs to emit JSON Schema metadata in the Restate discovery manifest so Restate can produce OpenAPI after service registration. The SDK does not generate OpenAPI locally.

Several Composer packages can help with schema-related work:

- Third-party class schema generators can generate JSON Schema from PHP classes, but bring their own type models and ecosystem assumptions.
- `zircote/swagger-php` is popular and attribute-based, but is OpenAPI-first rather than discovery-metadata-first.
- `api-platform/json-schema` can generate class schemas, but brings a heavier API Platform/Symfony ecosystem.
- Symfony `property-info` and `serializer` help extract metadata, but do not emit JSON Schema by themselves.

Inference first needs safe metadata for handler signatures: primitives, nullables, unions of supported scalar/null types, `stdClass`, empty input/output, and unknown metadata for ambiguous PHP types such as native arrays. DTO and class shapes need rules of their own.

## Decision

Do not add a required JSON Schema generation dependency.

Implement a small internal reflection-type mapper for the simple PHP type surface, and design DTO/class inference separately, against concrete mapping semantics.

## Consequences

The SDK stays lightweight and is not coupled to an external schema ecosystem, at the cost of maintaining its own mapper.

DTO inference was built the same way, without a dependency: the SDK's own DTO schema generation behind a mapping-safety gate ([Mapping-Safe DTO Schema Inference](0011-mapping-safe-dto-schema-inference.md), [JSON Schema Module](0025-json-schema-module.md)). Libraries with their own DTO models plug in as JSON Payload Adapters ([Symfony](0004-symfony-json-payload-adapter.md), [Valinor](0005-valinor-json-payload-adapter.md)).
