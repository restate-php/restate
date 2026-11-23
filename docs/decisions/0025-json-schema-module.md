# JSON Schema Module

## Status

Accepted. Narrows the `Restate\JsonSchema\` row of [Public API Boundary](0016-public-api-boundary.md).

## Context

The JSON Schema module started as 7,644 lines in 82 files: an immutable builder for three JSON Schema dialects (`Draft07\`, `Draft201909\`, `Draft202012\`, 22 files and about 2,000–2,200 lines each), plus the shared document model, keyword ordering, and DTO schema generation (`Generation\SchemaGenerator`). Its tests were 2,338 lines.

**What used which draft** (across the code, tests, examples, test services and docs):

- Nothing outside the module used Draft-07 or 2019-09 except the facade methods that handed out their factories (`JsonSchema::draft07()`, `draft201909()`, `for()`), and `JsonSchemaMetadata::fromArray()`, which picked the raw-schema class of the draft a user-supplied document named in `$schema` (and rejected any other `$schema`).
- DTO schema generation (`SchemaGenerator`, `JsonSchema::schemaFor()`), discovery inference (`Reflection\Internal\JsonSchemaInferer`, typed on `Draft202012\Schema`) and every `JsonSchema::…` shortcut build Draft 2020-12 only.
- The discovery manifest carries each payload's schema as a subschema, without `$schema` (`JsonSchemaMetadata::toManifestValue()`).
- The only tests of the two older drafts were their own compatibility tests and the 2020-12 tests that checked a Draft-07 schema is rejected inside a 2020-12 one.

**What Restate expects.** The server stores a handler's `input.jsonSchema` / `output.jsonSchema` as an opaque `serde_json::Value` (`restatedev/restate` `crates/types/src/schema/service.rs`) and places it under `components/schemas` of the per-service OpenAPI document it generates (`crates/types/src/schema/metadata/openapi.rs`), rewriting only `$ref`s that start with `#` to point into that component. That document is OpenAPI 3.1 (`crates/utoipa`: `OpenApiVersion::Version31`), whose Schema Objects use JSON Schema Draft 2020-12 unless a schema declares another `$schema`. The server does not validate the dialect.

**What the official SDKs emit:**

- TypeScript: the Standard Schema serde asks for `jsonSchema.output({ target: "draft-2020-12" })` (`restate-sdk-core/src/serde_api.ts`); the Zod serde uses `z.toJSONSchema()`, whose default target is Draft 2020-12.
- Java: the Jackson serde configures victools' generator with `SchemaVersion.DRAFT_2020_12` (`sdk-serde-jackson/…/JacksonSerdes.java`); the Kotlin serialization serde's tests expect `"$schema": "https://json-schema.org/draft/2020-12/schema"`.
- Python: Pydantic's `model_json_schema()` and msgspec's `json_schema()` (`python/restate/handler.py`), both of which produce Draft 2020-12.

No SDK offers a choice of dialect.

**Extract instead?** Extracting the builder into its own package would keep three drafts' worth of code alive for no user of this SDK, and add a package to version and release. Nothing outside the SDK depends on it.

## Decision

1. **Draft 2020-12 only.** The SDK models no other dialect.
2. **A user-supplied document is published as given, whatever `$schema` it declares.** `JsonSchemaMetadata::fromArray()` (and so `Payload::json([...])`, `#[Input(schema: [...])]`, `#[Output(schema: [...])]`) builds no draft-specific schema object: it checks the array is a non-empty, JSON-encodable object and passes it through, so a Draft-07, Draft-04 or custom meta-schema document is published too. The server stores it as is, and OpenAPI 3.1 honours a schema's own `$schema`. `JsonSchema::raw()` rejects a root `$schema` other than Draft 2020-12, because a raw schema is a 2020-12 subschema that composes with the builders.
3. **Nothing exists only to hold several drafts.** The schema classes are directly in `Restate\JsonSchema\` (`StringSchema`, `ObjectSchema`, `ArraySchema`, `IntegerSchema`, `NumberSchema`, `BooleanValueSchema`, `NullSchema`, `UnionSchema`, `BooleanSchema`, `PropertySchema`, `ReferenceSchema`, `RawSchema`, `StringFormat`, and the `Schema` interface), built by the static methods of `Restate\JsonSchema` (`string()`, `object()`, `document()`, …; `JsonSchema::schemaFor()` returns `Schema`). There is no dialect parameter, factory or version enum; `Document::DIALECT` is the URI a document declares. The classes share their code through `@internal` traits (`KeywordBackedSchema`, `KeywordedSchema` and the keyword traits) rather than abstract base classes, so no internal type is part of a public type's hierarchy and the JSON Schema module needs no `allowedInternal` entry ([Public API Boundary](0016-public-api-boundary.md)).
4. **Dropping the two older dialects changed no discovery output:** the manifest of every test service was compared byte for byte.

## Consequences

- One schema class per JSON type; code builds schemas with the `JsonSchema::…` shortcuts and names the classes in `Restate\JsonSchema\`.
- Size: the module is 3,022 lines in 34 files instead of 7,644 in 82, its tests 1,611 lines instead of 2,338.
- Applications that need another dialect generate it themselves and pass the array; the SDK does not model it.
