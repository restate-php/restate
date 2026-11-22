# Operation-Level Custom Serialization

## Status

Accepted

## Context

`Payload::custom()` lets handlers use a bytes-level `Serde` for their input and output payloads. Every other value a handler sends or journals (state, calls, sends, `Context::run` results, awakeables, durable promises) needs the same choice, or custom serialization stops at the handler boundary.

Endpoint and service default serializers and the endpoint-scoped journal-value codec are a separate layer, modelled on the TypeScript SDK's default `serde` option and `JournalValueCodec`. A default serializer chooses the logical wire format when nothing more specific is given; a journal-value codec is an opaque byte transform (compression, encryption) applied after serialization that never affects discovery metadata. That defaulting layer remains distinct from explicit operation overrides.

## Decision

Expose operation-level custom serializers through the existing `Serde` contract and the same class-string resolution rules as `Payload::custom()`.

Public operation overrides are explicit and local to the operation:

- `ObjectContext::set($key, $value, serde: ...)` and `ObjectSharedContext::get($key, serde: ...)`
- `Context::call()` and `Context::callFuture()` with `inputSerde:` and `outputSerde:`
- `Context::send()` and `Context::sendAfter()` with `inputSerde:` and `outputSerde:` for the sent payload and later invocation output handle
- `CallOptions`, `SendOptions`, and `ClientRequest` helpers for the same call/send serializers
- `Context::run(..., serde: ...)` and `RunOptions::withSerde(...)`
- `Context::awakeable(...)`, `Context::awakeableFuture(...)`, `Awakeable::value(...)`, and `Context::resolveAwakeable(..., serde: ...)`
- `WorkflowContext::promise($name, serde: ...)`, `WorkflowSharedContext::promise($name, serde: ...)`, and `DurablePromise` value, peek, future, and resolve methods
- `InvocationHandle::output(serde: ...)`, `Context::attach(..., serde: ...)`, and `Context::invocation(..., serde: ...)`

When no operation serde is supplied, the SDK uses `JsonSerde::default()` and preserves existing JSON bytes and JSON exception behavior. Custom operation serializers are bytes-level authorities and bypass JSON Value Mapping, just like `Payload::custom()`.

Default serializers remain the broader layer. The precedence is explicit operation serde, then service default serde, then endpoint default serde, then `JsonSerde::default()`.

## Consequences

State reads and writes, call and send payloads and results, durable `run` completions and replays, awakeable values, and durable promises can use custom serializers without bypassing the backend operation and replay paths.

The creator/resolver or writer/reader sides of a durable value must use compatible serializers. Changing a serializer after values have been journaled or persisted is an application compatibility break unless the new serializer can read old bytes.

JSON Payload Adapters remain separate. Symfony and Valinor adapters map decoded JSON-compatible PHP values and discovery schema metadata; they do not replace bytes-level `Serde` custom serializers.
