# Call And Send Option Headers

## Status

Accepted.

## Context

A call or send can carry request headers for the callee, which reads them as invocation headers (`Request::$headers`). They belong with the other per-operation options (idempotency key, delay, serializers, limit key), on every surface that starts a call or send.

## Decision

Request headers are exposed on the same operation surfaces that carry the other call and send options:

- `Context::call()`, `Context::callFuture()`, `Context::send()` and `Context::sendAfter()` accept a named `headers:` argument.
- `CallOptions` and `SendOptions` expose `header()`, `headers()`, `withHeader()` and `withHeaders()`.
- `ClientRequest` exposes immutable `header()` and `headers()`. Request-level headers come before headers supplied through a per-call `CallOptions` or `SendOptions`.
- `ClientRequest::sendAfter()` accepts optional `SendOptions`; the explicit delay argument stays authoritative, while the options can carry headers, idempotency keys and serializers.

Header input is either a string map such as `['x-trace-id' => 'trace-1']` or an ordered list of pairs such as `[['x-trace-id', 'trace-1']]`. The list form keeps duplicate headers and their order. Header names must be non-empty HTTP token names, and values must not contain CR or LF bytes.

The headers travel through the typed Backend Contract: `Internal\Backend\CallTarget` carries the normalized list of pairs (`RequestHeaders::normalize()`) next to the target and limit key, the extension backend passes them in the Extension Surface's call target array (`headers`, only when non-empty, `SurfaceValues::callTarget()`), and the core puts them into shared-core's `Target::headers`, which journals them with the call or send command.

New call and send option categories go into `CallOptions` and `SendOptions` first, and onto the direct `Context` methods only when they are useful without an options object. Default serializers and the journal value codec are a separate layer ([Operation-Level Custom Serialization](0008-operation-level-custom-serialization.md)).

## Consequences

Headers are optional: without them the call target carries no `headers` entry. They are runtime-only operation options; they do not affect the discovery manifest.
