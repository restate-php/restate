# JSON Schema Test Suite Subset

These fixtures are selected test cases copied from the official JSON Schema Test Suite:

https://github.com/json-schema-org/JSON-Schema-Test-Suite

The copied fixtures retain the upstream MIT license; see `LICENSE` in this directory.

They keep the official `description`, `schema`, and `tests` shape, but this directory is intentionally a small subset. `JsonSchema` is a schema builder/serializer, not a JSON Schema validator, so unit tests use these cases only where the fluent API can build the same schema document exactly.
