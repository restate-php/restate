# Bidirectional Streaming

## Status

Accepted. The SDK serves request/response only. This record says why, what was proven about the PHP runtimes that could stream, and what a `BIDI_STREAM` entrypoint would take.

## Context

Restate talks to a deployment in one of two protocol modes, which the discovery manifest declares (`protocolMode`):

- **`REQUEST_RESPONSE`:** each attempt is one HTTP request. When the handler awaits something Restate has not sent yet (a sleep, a call result, an awakeable, a run acknowledgement), the attempt suspends and ends; Restate resumes the invocation with a new request that replays the whole journal. A handler with N sequential steps takes N + 1 attempts and O(N²) replay work ([Long Journals](../SERVING.md#long-journals-cost-quadratic-replay)).
- **`BIDI_STREAM`:** one HTTP/2 stream per attempt, open in both directions. The endpoint sends commands as response DATA while the request is still open, and Restate sends completions as request DATA. A wait does not end the attempt, so one attempt can run the whole invocation.

Streaming needs a server that hands PHP request DATA before the request's `END_STREAM`, and sends response DATA before the request ends. PHP's classic serving model (one request in, one response out, the body read in full) does neither.

## Findings

### FrankenPHP cannot stream to PHP

FrankenPHP (`php_server`, cleartext HTTP/2 with `protocols h1 h2c`, Caddy's `enable_full_duplex` on) was probed with an HTTP/2 client that sent one request body in two DATA frames, waiting between them for a sign of life. The PHP script read `php://input` in two chunks and echoed and flushed after the first. PHP saw no input before the client ended the request stream, and the client got no response DATA before it: FrankenPHP delivered the body to PHP only once the request was complete. php-fpm and `php -S` buffer the same way.

### amphp/http-server can

`amphp/http-server` 3.4 over h2c hands each request DATA frame to PHP as it arrives and sends response DATA while the request is open: a client that sent the next chunk only after the acknowledgement of the previous one got each acknowledgement within a fraction of a millisecond. It needs three settings: h2c with prior knowledge (`DefaultHttpDriverFactory(..., allowHttp2Upgrade: true)`, which Restate uses for an `http://` deployment URI), no compression middleware (it buffers the response; `SocketHttpServer::createForDirectAccess()` adds it by default), and a handler that returns its `Response` at once with a `Pipe` body that another Fiber writes to.

A throwaway endpoint on that server, using the unchanged extension, served real invocations from Restate 1.8.1 registered as a `BidiStream` deployment over HTTP/2 (protocol v7): an invocation that slept, and one that called another handler and then slept, each finished in one attempt; in request/response mode the same invocations took two and three. Further observations:

- **Restate ending the input still suspends cleanly.** When Restate closes the request body after its inactivity timeout (1 minute by default), the next await returns a suspension, the attempt ends as in request/response mode, and the replay completes the invocation later. Every long wait takes that path.
- **The server's stream idle timeout must exceed Restate's.** amphp's HTTP/2 per-stream timeout defaults to 15 s, shorter than Restate's inactivity timeout, so a 20 s sleep was reset by the server, and Restate retried and finished it through replay. With the timeout raised, it finished in one attempt.
- **Memory and concurrency.** 200 concurrent sleeping invocations on one PHP process cost about 150 KB of RSS each (debug build: VM, Fiber, HTTP/2 stream state), all waiting at once.
- **Blocking code stalls every invocation in the process.** A run callback that called `usleep()` delayed an unrelated request by the same time: the event loop is single-threaded.
- **Wall time only improves with latency.** Locally both modes took the same time, because Restate resumes a suspended invocation at once and replaying a short journal is cheap. What streaming saves is the extra attempts and their replay, which grow with journal length and network latency.

### The extension needs no change

A call into the extension's `Vm` never blocks, never calls back into PHP, and returns before PHP code runs again, so a Fiber switch only ever happens in PHP frames between native calls, with no Rust stack, borrow or lock held across it. Each attempt's VM is its own PHP object, so interleaved Fibers cannot reach another attempt's VM. The event loop is one thread, so an NTS build is enough. The core already supports streaming: `doAwait()` with open input returns `read_from_input` (shared-core's `WaitingExternalProgress { waiting_input: true }`), on v7 it also writes the `AwaitingOn` message, and `takeOutput()` returns `''` while output is open and `null` once it is closed.

## Decision

1. **The SDK serves `REQUEST_RESPONSE` only.** `Endpoint::serve()`, `Endpoint::psr()` and the discovery manifest declare it; nothing advertises `BIDI_STREAM`. None of the serving targets the SDK supports (php-fpm, `php -S`, FrankenPHP classic and worker mode, PSR-15) can stream request bodies to PHP.
2. **A streaming entrypoint would be its own serving target on amphp/http-server**, in its own package so that the SDK does not depend on Amp, built as follows:
   - **One routing implementation.** The endpoint's routing (health, 404, identity check, discovery, 415) and attempt execution (run the attempt, map errors, detect suspension) move behind an internal attempt runner that takes an input source (`read(): ?string`) and an output sink (`write(string)`). Request/response yields the whole body once and collects the output; the Amp server reads `RequestBody` and writes the response `Pipe`. `handleRequest()` keeps working on top of it.
   - **An input pump around the invocation.** Only `doAwait()` and `doAwaitTree()` change: while the result is `read_from_input`, flush the output, read from the input source and notify the VM (`notifyInput()`, or `notifyInputClosed()` at the end of input), then await again. Every awaiting operation already goes through those two methods. Before the handler starts, feed input until the VM is ready to execute. With request/response input the loop never runs.
   - **Output** is flushed whenever the attempt waits for input (Restate cannot complete what it has not received) and when the attempt ends; writes to the pipe suspend the Fiber under HTTP/2 flow control.
   - **Discovery** declares the mode of the transport it arrived on: `BIDI_STREAM` over HTTP/2 with streaming enabled, `REQUEST_RESPONSE` otherwise, as the TypeScript SDK's HTTP/2 and HTTP/1 handlers do. Restate stores the mode per deployment, so both kinds of deployment can coexist; a streaming deployment must be registered for HTTP/2.
   - **Limits it must set:** the stream idle timeout above Restate's inactivity plus abort timeouts, the request body size limit raised per invocation stream (amphp's 128 KiB default caps the input of the whole invocation), an explicit HTTP/2 concurrent stream limit, a per-process concurrency cap and several worker processes.
   - **Long-lived process rules:** handler code must not block (blocking I/O, `sleep()`, PDO and curl stall every open invocation), services are bound per invocation as in FrankenPHP worker mode ([Handler Instantiation](0018-handler-instantiation.md)), and on shutdown every open attempt gets `notifyInputClosed()` so it suspends at its next await and Restate resumes it elsewhere.
   - **Error paths:** a request stream reset by Restate is end of input; a response stream that is gone while flushing ends the attempt and frees the VM.

## Consequences

- Every suspension costs a new request and a replay, and an invocation whose replay alone exceeds its abort timeout cannot finish. The guidance is to keep journals short ([SERVING.md](../SERVING.md#long-journals-cost-quadratic-replay)).
- [Status](../STATUS.md#known-gaps-and-limitations) lists bidirectional streaming as a known gap.
- The main cost of a streaming entrypoint is outside the VM: the non-blocking-handler rule users must follow, the worker and concurrency settings, and splitting the endpoint so the routing and error paths stay shared. Its tests would need a `read_from_input` step in the unit tests' scripted invocation, and an integration run with an Amp endpoint asserting one attempt per sleep or call, suspension when the input closes, behaviour after a stream reset, and the advertised mode per HTTP version.
- Output capture for stray output works per process, not per Fiber ([Stray Output](0032-stray-output.md)); a streaming entrypoint needs its own answer.
