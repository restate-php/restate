# Restate PHP SDK

Build [Restate](https://restate.dev) services in PHP: handlers whose calls, side effects, state, timers and promises Restate journals, so they run to completion across failures and restarts. Services, Virtual Objects and Workflows are PHP classes with attributes, or builders; an endpoint serves them under `php -S`, PHP-FPM, FrankenPHP or any PSR-15 framework. All Restate traffic goes through [`restate-sdk-shared-core`](https://crates.io/crates/restate-sdk-shared-core), the core the official TypeScript, Python and Rust SDKs are built on, compiled into the Restate PHP extension.

## Features

- Basic Services, Virtual Objects with per-key state, and Workflows with durable promises, defined with [PHP attributes](#attribute-api) or builders, with documentation, metadata, retry policies and invocation options published through discovery
- Durable `ctx.run` side effects with retry policies, deterministic time and random values, durable timers, awakeables and [named signals](#signals)
- Calls, sends and delayed sends with idempotency keys and headers, [invocation handles](#invocation-handles) (attach, output, cancel), and [scopes and limit keys](#scopes-and-limit-keys) for per-tenant identity and concurrency limits
- [Durable futures](docs/DURABLE_FUTURES.md) and their combinators (`all`, `race`, `any`, `allSettled`, `select`)
- Structured terminal errors, [pausing invocations](#pausing-invocations) instead of retrying them, [selective state preloading](#state-preloading)
- JSON payloads with [DTO mapping](#dto-mapping) and JSON Schema inference for discovery, [Symfony Serializer](#symfony-json-payload-adapter) and [Valinor](#valinor-json-payload-adapter) adapters, [custom serializers](#custom-serialization) and journal value codecs
- [Interceptors and an error mapper](#runtime-execution-hooks), [per-invocation service instances](#service-instances-per-invocation) from a factory or PSR-11 container and [PSR-3 logging](#logging)
- [Request identity validation](#request-identity-validation)
- An [ingress client](#ingress-client) for code that is not a handler, over any PSR-18 HTTP client, without the extension
- A [test harness](#testing-your-services) that runs your endpoint against a real Restate server, in a container or as a local `restate-server` process, with a PHPUnit trait

Endpoints serve Restate's request/response protocol mode only, not bidirectional streaming ([Serving](docs/SERVING.md#requestresponse-protocol-mode)). [Status](docs/STATUS.md) has every feature with its tests, the parity with the official SDKs, the known gaps and the supported platforms.

## Requirements

- PHP 8.4, 8.5 or 8.6, NTS or ZTS.
- The Restate PHP extension, to serve services ([Installing the extension](#installing-the-extension)). The ingress client and the test harness do not need it in their own process.
- Restate Server 1.8 or newer. 1.7 works best-effort: the features that need service protocol v7 (pausing, scopes and limit keys, a list of preloaded state keys) fail there with a message saying so ([Shared Core](docs/decisions/0014-shared-core.md#server-compatibility)).

## Installation

The SDK is split into Composer packages, released together at one version:

| Package | Install | What it is |
| --- | --- | --- |
| `restate/sdk` | `composer require restate/sdk` | Services, handlers and the endpoint (`Restate\`). Requires the extension (`ext-restate`), so Composer refuses to install it without |
| `restate/ingress` | `composer require restate/ingress` | The [ingress client](#ingress-client) (`Restate\Ingress\`), for code that calls services without serving any; no extension |
| `restate/testing` | `composer require --dev restate/testing` | The [test harness](#testing-your-services) (`Restate\Testing\`); no extension in the test process |
| `restate/common` | (installed with the others) | The types they share (`Serde`, `Target`, `TerminalError`, the JSON value mapping, …) |

Class names do not depend on the package: `Restate\Target` is `Restate\Target` whichever package brought it. See [Package Split](docs/decisions/0029-package-split.md).

### Installing the extension

Every endpoint runs on the Restate PHP extension: module `restate`, file `restate.so`, `ext-restate` to Composer. There is no pure-PHP fallback ([Single Native Backend](docs/decisions/0012-single-native-backend.md)). Install it with [PIE](https://github.com/php/pie), the PHP Foundation's extension installer:

```bash
pie install restate/ext-restate
```

PIE downloads the prebuilt `restate.so` for your PHP: PHP 8.4, 8.5 or 8.6, NTS or ZTS (FrankenPHP), x86_64 or arm64, Linux with glibc 2.28 or newer or with musl (Alpine). It copies it into PHP's extension directory, enables it (`extension=restate`) and checks that PHP loads it; `php -m` then lists `restate`. Nothing is compiled, so no Rust toolchain or build tools are needed. In an image based on the official PHP images:

```dockerfile
FROM php:8.4-cli
RUN --mount=type=bind,from=ghcr.io/php/pie:bin,source=/pie,target=/usr/local/bin/pie \
    pie install restate/ext-restate
```

Each release lists SHA-256 checksums of its archives and has build provenance attestations, recorded by the release run in this repository: `gh attestation verify <archive> --repo restate-php/restate`.

`restate/sdk` requires the extension of its own minor version; [Extension Versions](docs/EXTENSION_VERSIONS.md) lists each extension version with the shared-core version it carries. Building an endpoint (`Endpoint::serve()`, `Endpoint::psr()`, `Endpoint::builder()->build()`) without the extension loaded, or with one that does not match the package, throws `BackendUnavailableException` saying what to install ([Troubleshooting](docs/TROUBLESHOOTING.md)).

**From source.** There is no prebuilt binary for macOS (untested), Windows (not supported), debug builds of PHP, or glibc older than 2.28. To build the extension yourself you need the Rust toolchain pinned in `extension/rust-toolchain.toml`, libclang and the headers of the PHP that will load it. In a checkout of this repository:

```bash
bash bin/build-extension.sh --release   # prints the module's path: extension/target/release/restate.so
```

That builds for the PHP whose `php-config` is on `PATH`; `--php-config <path>` builds for another one (into `extension/target/php<minor>-<nts|zts>/release/`). Copy `restate.so` into PHP's extension directory (`php -r 'echo ini_get("extension_dir");'`) and add `extension=restate` to php.ini or to a file in its scan directory (`php --ini` shows both); `php -m` then lists `restate`.

With Nix, the extension's flake (`extension/flake.nix`, published as `github:restate-php/ext-restate`) adds `restate` to every PHP of nixpkgs, macOS included: see the [extension's README](extension/README.md#with-nix).

A module loads only into the PHP minor version and thread safety (NTS or ZTS) it was built for. The [serving guide](docs/SERVING.md#php-extension) has more on loading it, [CONTRIBUTING.md](CONTRIBUTING.md) on building it for development.

## Quickstart

Install the extension and the SDK:

```bash
pie install restate/ext-restate
composer require restate/sdk
```

Define a service and serve it, in `index.php`:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Restate\Attributes as Restate;
use Restate\Context;
use Restate\Endpoint;

#[Restate\Service('Greeter')]
final class Greeter
{
    #[Restate\Handler]
    public function greet(Context $ctx, string $name): string
    {
        return "Hello {$name}";
    }
}

Endpoint::serve([new Greeter()]);
```

Start it with PHP's built-in server, on an address Restate can reach. `PHP_CLI_SERVER_WORKERS` lets it serve several requests at once, which a handler that calls another handler of the same endpoint needs:

```bash
PHP_CLI_SERVER_WORKERS=4 php -d display_errors=0 -d enable_post_data_reading=0 -S 0.0.0.0:9080 index.php
```

Start a Restate server (1.8 or newer), register the endpoint as a deployment through Restate's admin API, and call the handler through its ingress. PHP's built-in server speaks HTTP/1.1 only, hence `use_http_11`:

```bash
docker run --rm -p 8080:8080 -p 9070:9070 --add-host=host.docker.internal:host-gateway \
    docker.restate.dev/restatedev/restate:1.8.1

curl localhost:9070/deployments -H 'content-type: application/json' \
    -d '{"uri": "http://host.docker.internal:9080", "use_http_11": true}'

curl localhost:8080/Greeter/greet -H 'content-type: application/json' -d '"Mary"'
# "Hello Mary"
```

`php -S` is for development: the [serving guide](docs/SERVING.md) covers PHP-FPM, FrankenPHP, PSR-15 and the PHP settings an endpoint needs. [`examples/`](examples/) has more: a builder-defined service (`greeter.php`), the attribute API (`attribute-greeter.php`), PSR-15 endpoints for Slim and Mezzio, the Symfony and Valinor payload adapters, and a test with the [test harness](#testing-your-services).

## Documentation

- [Serving Runtime Guide](docs/SERVING.md): entrypoints, the extension, request identity, PHP settings, FrankenPHP worker mode, PHP-FPM front doors, the costs of request/response mode
- [Durable Futures](docs/DURABLE_FUTURES.md): starting futures, combinators, incremental completion, timeouts
- [Troubleshooting](docs/TROUBLESHOOTING.md)
- [Extension Versions](docs/EXTENSION_VERSIONS.md)
- [Status](docs/STATUS.md): what works and the tests behind it, parity with the official SDKs, known gaps, supported platforms
- [Conformance](docs/CONFORMANCE.md) and [Backend Architecture](docs/BACKEND_ARCHITECTURE.md)
- [Decision records](docs/decisions/): why the SDK works the way it does
- [CONTRIBUTING.md](CONTRIBUTING.md): working on the SDK itself

## Handler Contexts

Every handler takes a context as its first parameter. The contexts are interfaces, named as in the TypeScript and Python SDKs; the SDK creates the context for each invocation attempt and there is no implementation to construct:

| Handler | Context | Adds |
| --- | --- | --- |
| Service handler | `Context` | calls, sends, `run`, sleeps, awakeables, signals, deterministic random values |
| Virtual Object shared handler | `ObjectSharedContext` (extends `Context`) | `key()`, `get()`, `stateKeys()` |
| Virtual Object exclusive handler | `ObjectContext` (extends `ObjectSharedContext`) | `set()`, `clear()`, `clearAll()` |
| Workflow shared handler | `WorkflowSharedContext` (extends `ObjectSharedContext`) | `promise()`, `promiseFuture()` |
| Workflow `run` handler | `WorkflowContext` (extends `WorkflowSharedContext` and `ObjectContext`) | |

A handler may declare any context its own context extends, for example `Context` for code that only calls other services. Building the service definition rejects a handler that declares a context with more capabilities than its kind receives, such as `ObjectContext` on a shared handler, instead of failing every invocation with a `TypeError`.

What a context hands out is an interface as well: `DurableFuture`, `Awakeable`, `DurablePromise`, `InvocationHandle` and `SignalHandle`. The future combinators are context methods (`$ctx->all()`, `race()`, `any()`, `allSettled()`, `waitCompleted()`, `select()`; see [Durable Futures](docs/DURABLE_FUTURES.md)), so a test double of a context controls them too. Everything under `Restate\Internal` is internal; [Public API Boundary](docs/decisions/0016-public-api-boundary.md) says what is public and what compatibility to expect.

`$ctx->request()` returns the request that started the invocation, like `ctx.request()` in the TypeScript SDK. `Restate\Request` is a read-only value:

```php
$request = $ctx->request();

$request->id;              // invocation id ("inv_…"), the same across retries and suspensions
$request->target;          // Target: serviceName(), handlerName(), key()
$request->headers;         // the caller's headers, lowercase names; $request->header('X-Tenant') ignores case
$request->attemptHeaders;  // HTTP headers of this attempt's request from Restate; they change between attempts
$request->body;            // raw input bytes, before the input serializer and any Journal Value Codec
$request->idempotencyKey;  // the idempotency key the invocation was submitted with, or null
$request->scope;           // the scope it was submitted with, or null
$request->limitKey;        // the limit key it was submitted with, or null
```

Restate sends the idempotency key, scope and limit key from service protocol v7 (Restate 1.8) on; with Restate 1.7 they are always `null`.

## Error Handling in Handlers

A `TerminalError` thrown by a handler fails the invocation without retries; any other exception is retried. Catch `TerminalError` to react to a failed call, awakeable, promise, or `ctx.run`:

```php
use Restate\Target;
use Restate\TerminalError;

try {
    $ctx->call(Target::service('Payments', 'charge'), $order);
} catch (TerminalError $e) {
    return ['declined' => $e->getMessage()];
}
```

Handler input that cannot be decoded (malformed JSON, a `\JsonException` from the input serde, or a value that does not fit the [declared type](#dto-mapping)) fails the invocation with a terminal `400` before the handler runs. Invalid JSON anywhere else (a `\JsonException` in handler code or while encoding the output, a called handler's result that does not decode) is retried like any other exception.

Throw `RetryableError` to fail the attempt explicitly and ask for a retry, optionally after a given delay (`retryAfterMillis`). Throw `PauseError` to pause the invocation until someone resumes it (see [Pausing Invocations](#pausing-invocations)). Every exception the SDK throws for you to handle implements the marker interface `Restate\Exception` (`TerminalError`, `RetryableError`, `PauseError`, `BackendUnavailableException`, `JsonMappingException`, `UnsupportedJsonSchemaException`, `DurableFutureAggregateException`).

Context calls that wait (sleeps, calls, awakeables, promises, `ctx.run`) may suspend the invocation. The SDK unwinds the handler with an internal `\Error` so that Restate can resume it later; neither `catch (\Exception)` nor `catch (\Restate\Exception)` sees it. Do not catch `\Throwable` or `\Error` around context calls. If you do, the code in that catch block runs on suspension, every later context call in that attempt throws again, and the endpoint ignores whatever the handler returns or throws. See [Suspension Is Not an Exception](docs/decisions/0013-suspension-is-not-an-exception.md).

## Logging

Give the endpoint a PSR-3 logger (Monolog or any other) to see what the SDK does; without one it logs nothing:

```php
$endpoint = Endpoint::builder()
    ->services([new Greeter()])
    ->logger($logger)
    ->build();

Endpoint::serve([new Greeter()], logger: $logger); // the shortcuts take one too
```

| Level | What |
| --- | --- |
| error | A request failed with 500, or was rejected because its request identity did not verify (401) |
| warning | A handler attempt failed and Restate retries it, a `ctx.run` callback failed, the invocation paused; a request without a content type or a supported discovery version (415) |
| info | The invocation failed with a `TerminalError` |
| debug | Each attempt's start (`Starting invocation` / `Replaying invocation`), success and suspension |

Records about an invocation carry `restate.invocation.id` and `restate.invocation.target` (`Service/handler`, or `Service/key/handler`) in their context, a failure its exception under `exception`. Configure the levels you want on the logger itself. See [SDK Logging](docs/decisions/0027-sdk-logging.md) for every message.

The extension's state machine (`restate-sdk-shared-core`) logs too, mostly at debug: the full description of a journal mismatch (a handler that no longer takes the path its journal recorded), each suspension, each command it executes. Its records at info or above go to the same logger by default (with `restate.core.target`, the Rust module, in the context); to see the debug ones while you investigate, lower the level, or pass `null` to forward none:

```php
Endpoint::builder()->logger($logger, coreLogLevel: Psr\Log\LogLevel::DEBUG);
```

In a handler, log through `$ctx->logger()`: the endpoint's logger, with the same two keys added, which drops records while the handler replays journal entries of an earlier attempt, so each line is logged once per invocation rather than once per attempt:

```php
$ctx->logger()->info('Charging', ['order' => $order->id]);
$ctx->run('charge', fn () => $payments->charge($order));
```

To log replay-aware to another logger, wrap it: `new Restate\Logging\ReplayAwareLogger($ctx, $otherLogger)`.

## Runtime Execution Hooks

Interceptors run code around every handler attempt and every executed `ctx.run` callback, for tracing, metrics or logging. Implement `HandlerInterceptor`, `RunInterceptor`, or both, and register them on the endpoint or on a service:

```php
use Restate\HandlerExecution;
use Restate\HandlerInterceptor;
use Restate\RunExecution;
use Restate\RunInterceptor;
use Restate\Suspension;

final class TimingInterceptor implements HandlerInterceptor, RunInterceptor
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function interceptHandler(HandlerExecution $execution, \Closure $next): void
    {
        $request = $execution->request;
        $traceparent = $request->attemptHeaders['traceparent'] ?? null; // W3C trace context of this attempt
        $start = hrtime(true);
        try {
            $next();
            $outcome = 'ok';
        } catch (\Throwable $e) {
            $outcome = Suspension::is($e) ? 'suspended' : 'failed';

            throw $e; // always rethrow
        } finally {
            $this->logger->info('attempt', ['invocation' => $request->id, 'traceparent' => $traceparent, 'outcome' => $outcome, 'ns' => hrtime(true) - $start]);
        }
    }

    public function interceptRun(RunExecution $execution, \Closure $next): void
    {
        $this->logger->info("run {$execution->name}", ['invocation' => $execution->request->id]);
        $next();
    }
}

$endpoint = Endpoint::builder()
    ->interceptor(new TimingInterceptor($logger))            // every service
    ->bind(Service::new('Greeter')
        ->interceptor(new TenantScope())                     // this service, inside the endpoint's
        ->handler('greet', $greet))
    ->build();
```

- **Order.** Endpoint interceptors wrap service interceptors; at each level the first one registered is the outermost. For services reflected from attributes, use `ServiceReflector::fromObject($greeter)->withInterceptors(...)`.
- **Replay.** A handler interceptor runs on every attempt, also on attempts that replay the journal; `$execution->isReplaying()` says whether the handler is still replaying. A run interceptor runs only when the callback executes, never when its result is replayed from the journal (`Context::now()` is the run `date.now`).
- **`$next`.** It runs the rest of the chain and the handler (instantiating a [factory-built service](#service-instances-per-invocation), input decoding and output encoding included) or the run callback, and returns nothing: interceptors cannot change results. Call it exactly once; returning without calling it, or calling it twice, fails the attempt with a `\LogicException`.
- **Failures.** An interceptor cannot swallow a failure: if `$next` threw and the interceptor returns, the SDK rethrows it. It may throw another exception instead, such as a `TerminalError`.
- **Suspension.** When the invocation suspends, `$next` throws the SDK's internal suspension. It always passes through: whatever the interceptor does with it, the attempt ends as a suspension. Use `Suspension::is($e)` to tell it from a failure.
- Interceptors do not get the handler's context; do not issue journal operations from them.

### Mapping Exceptions to Terminal or Retryable Errors

An error mapper classifies exceptions that are not a `TerminalError`, `RetryableError` or `PauseError`, from handlers and from `ctx.run` callbacks, so domain code does not need to know about Restate:

```php
use Restate\RetryableError;
use Restate\TerminalError;

$endpoint = Endpoint::builder()
    ->errorMapper(static fn (\Throwable $e): TerminalError|RetryableError|null => match (true) {
        $e instanceof InsufficientFunds => new TerminalError($e->getMessage(), 422, ['account' => $e->account], $e),
        $e instanceof RateLimited => new RetryableError('rate limited', retryAfterMillis: $e->resetMillis, previous: $e),
        default => null, // unchanged: retried
    })
    ->services([new Payments()])
    ->build();
```

A returned `TerminalError` fails the invocation (or the run, without retrying it) with its status code and metadata; a `RetryableError` is retried with its delay, code and metadata; a `PauseError` pauses the invocation; `null` keeps the default (retried). Services can have their own mapper (`Service::errorMapper()`, `VirtualObject::errorMapper()`, `Workflow::errorMapper()`, `ServiceDefinition::withErrorMapper()`), asked before the endpoint's. A handler's exception reaches the mapper after the handler interceptors, which see it unmapped. The mapper never sees a suspension. See [Runtime Execution Hooks](docs/decisions/0017-runtime-execution-hooks.md) for the design and how it compares with the official SDKs.

## Request Identity Validation

Give the endpoint the public keys of your Restate server (`publickeyv1_…`), and it rejects with `401` every invocation and discovery request that Restate did not sign with a matching private key; only `/health` stays open. This is the `identityKeys` option of the official SDKs, checked by the same verifier. Without keys the endpoint accepts unsigned requests, for local development. Configured keys fail closed: an empty list, an empty key or `false` (what `getenv()` returns for a variable that is not set) makes building the endpoint throw `InvalidArgumentException`.

```php
$identityKeys = [getenv('RESTATE_IDENTITY_KEY')]; // throws when it is not set, rather than accepting unsigned requests

Endpoint::serve([new Greeter()], identityKeys: $identityKeys);
Endpoint::builder()->identityKeys($identityKeys)->services([new Greeter()])->build();
Endpoint::psr([new Greeter()], $responseFactory, identityKeys: $identityKeys);
```

How to give Restate its signing key, and what exactly is verified: [Serving](docs/SERVING.md#request-identity-validation).

## Durable `ctx.run` Retry-After

Throw `RetryableError` from inside a `Context::run()` callback when a durable side effect should be retried. Pass `retryAfterMillis` to request a concrete delay for the next retry attempt, such as a rate-limit reset window:

```php
use Restate\RetryableError;
use Restate\RunRetryPolicy;

$ctx->run(
    'charge-card',
    static function (): never {
        throw new RetryableError('rate limited', retryAfterMillis: 250);
    },
    RunRetryPolicy::fixedDelay(100, maxAttempts: 3, maxDurationMillis: 1000),
);
```

Restate waits `retryAfterMillis` before the next attempt instead of the policy's interval. The retry policy still decides whether another attempt is allowed: its attempt and duration limits apply as usual, and `RunRetryPolicy::none()` never retries.

Once the policy gives up, the run fails terminally with the last `RetryableError`'s message, status code and metadata, and `Context::run()` throws them as a `TerminalError`. The status code is the exception code when it is a failure status (400–599, except 570), 500 otherwise (also for any other exception):

```php
throw new RetryableError('payment provider unavailable', code: 503, metadata: ['provider' => 'acme']);
```

A `RetryableError` thrown by the handler itself (outside `Context::run()`) fails the attempt the same way as any other exception, and its `retryAfterMillis` replaces the delay of the invocation retry policy for the next attempt.

## Pausing Invocations

Some failures do not go away by retrying but can be fixed by a person: a missing approval, a misconfiguration, a bug. Throw `PauseError` to end the attempt and have Restate keep the invocation paused instead of retrying it:

```php
use Restate\PauseError;

$ctx->run('charge-card', static function () use ($gateway, $order): string {
    if (!$gateway->isConfigured()) {
        throw new PauseError('payment gateway is not configured');
    }

    return $gateway->charge($order);
});
```

From a `Context::run()` callback the run records no result, whatever its retry policy, and executes again once the invocation is resumed. From the handler itself the attempt ends; on resume the handler runs again from the start, replaying its journal (code outside `ctx.run` is deterministic, so a handler-level pause usually needs a new deployment to get past it). An error mapper may return a `PauseError` too, for example for `\LogicException`.

A `ctx.run` retry policy can pause instead of failing terminally once it is exhausted:

```php
use Restate\OnMaxAttempts;
use Restate\RunRetryPolicy;

$ctx->run('charge-card', $charge, RunRetryPolicy::exponential(maxAttempts: 5, onMaxAttempts: OnMaxAttempts::Pause));
```

Resume a paused invocation with `restate invocations resume <id>`, the UI, or the admin API (`PATCH /invocations/{id}/resume`; `?deployment=latest` moves an invocation pinned to an older deployment to the latest one). Restate itself pauses an invocation whose invocation retry policy gives up with `onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_PAUSE`.

Pausing needs Restate 1.8 or newer (service protocol v7). On Restate 1.7 the attempt fails instead with error 573 ("Pausing the invocation needs service protocol v7 …", or "Feature 'pause' is not supported …" for a retry policy, at its first retryable failure) and Restate retries it like any other failure. See [Pause](docs/decisions/0021-pause.md).

## Workflow Durable Promises

Workflow handlers can wait on durable promises and shared workflow handlers can complete them. `resolve()` completes a promise successfully with a JSON-serializable value by default; pass a `Serde` when promise values need a custom byte format. `reject()` completes it with a terminal failure. A waiting `value()` or `future()->awaitResult()` observes rejected promises as `TerminalError` with the original status code and metadata.

```php
use Restate\TerminalError;

$ctx->promise('approval')->resolve(['status' => 'approved']);
$ctx->promise('approval')->reject(new TerminalError('Approval denied', 409, ['reason' => 'fraud']));
```

## Invocation Handles

`$ctx->send()` / `sendAfter()` return an `InvocationHandle` on the invocation they started; `$ctx->invocation($id)` and `$ctx->attach(AttachTarget::…)` return one on an existing invocation, by id, idempotency key or workflow:

```php
use Restate\AttachTarget;

$handle = $ctx->send(Target::service('Reports', 'build'), $request);

$handle->peekOutput();  // the output if the invocation finished, null while it runs: does not wait
$handle->output();      // waits for the invocation to finish and returns its output
$handle->cancel();

$ctx->attach(AttachTarget::workflow('Checkout', 'order-1'))->output();
```

`output()` attaches to the invocation (`attach()` in the TypeScript and Java SDKs); `peekOutput()` asks for its output once (Java's `getOutput()`), like `DurablePromise::peek()`. Both throw a failed invocation's `TerminalError`. `id()`, `cancel()` and `signal()` need the invocation id: a handle from `send()` waits for it, and one attached by idempotency key or workflow throws a `\RuntimeException`.

## Signals

A signal is a named message to one invocation, sent by its invocation id. Any handler can wait for one: `$ctx->signal($name)` is a `DurableFuture` of the next delivery, so it combines with timers, calls and awakeables. Another handler resolves or rejects it through the target's `InvocationHandle`:

```php
use Restate\TerminalError;

// The waiting handler: tell the approver $ctx->request()->id, then wait up to an hour.
$approval = $ctx->signal('approval');
if ($ctx->select(['approval' => $approval, 'timeout' => $ctx->sleepFuture(3_600_000)]) === 'timeout') {
    throw new TerminalError('No approval within an hour', 408);
}
$decision = $approval->awaitResult(); // a rejected signal throws its TerminalError

// The approver, in another handler:
$ctx->invocation($invocationId)->signal('approval')->resolve(['approvedBy' => 'alice']);
$ctx->invocation($invocationId)->signal('approval')->reject(new TerminalError('Denied', 403));
```

Signals need no registration: a delivery that arrives before the handler waits is kept, and each delivery is received once, in order. Values use the context's default serde unless `signal()` / `resolve()` get one. A handle from `send()` can be signalled too (it waits for the invocation id first). Named signals work on every protocol version the SDK speaks, so on Restate 1.7 as on 1.8. See [Named Signals](docs/decisions/0019-named-signals.md).

## State Preloading

Restate sends a Virtual Object's or Workflow's state along with each invocation, so reads are free. For large state, choose what is sent with `InvocationConfiguration(statePreload: …)`, on the service or on one handler (the handler's setting wins):

```php
use Restate\InvocationConfiguration;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\VirtualObject;

// Only `plan` comes with each invocation; `history` is fetched when read.
$profile = VirtualObject::new('Profile', invocationConfiguration: new InvocationConfiguration(statePreload: ['plan']))
    ->handler('upgrade', static function (ObjectContext $ctx, string $plan): string {
        $previous = $ctx->get('plan');
        $ctx->set('plan', $plan);

        return "{$previous} -> {$plan}";
    })
    // This handler reads everything, so it gets all state preloaded.
    ->sharedHandler('export', static fn (ObjectSharedContext $ctx): array => [
        'plan' => $ctx->get('plan'),
        'history' => $ctx->get('history'),
    ], invocationConfiguration: new InvocationConfiguration(statePreload: true));
```

- `true` preloads all state (Restate's default, also when the option is unset).
- `false` preloads nothing (lazy state): every read asks Restate, which in request/response mode suspends the attempt and resumes it with the value.
- A list preloads only those keys, best-effort within the server's eager state size limit; any other key, and a listed key that did not fit or does not exist, is fetched on read. **A list needs Restate 1.8** (discovery manifest v5): on Restate 1.7, registration fails with a message saying so. `true`, `false` and `[]` (same as `false`) work on 1.7 too.

Plain services have no state, so they reject the option. See [Selective State Preloading](docs/decisions/0020-selective-state-preloading.md).

## Custom Serialization

`Serde` is the SDK's bytes-level Custom Serializer contract. Use `Payload::custom()` for handler input/output metadata, or pass a serializer explicitly to context operations when state, calls, sends, `ctx.run`, awakeables, or durable promises need non-JSON bytes:

```php
use Restate\RunOptions;
use Restate\Target;

$ctx->set('profile', $profile, serde: ProfileSerde::class);
$profile = $ctx->get('profile', serde: ProfileSerde::class);

$result = $ctx->run(
    'load-profile',
    static fn (): Profile => $repository->load(),
    RunOptions::none()->withSerde(ProfileSerde::class),
);

$reply = $ctx->call(
    Target::service('Profiles', 'load'),
    $request,
    inputSerde: ProfileRequestSerde::class,
    outputSerde: ProfileSerde::class,
);

$ctx->promise('approval', serde: ApprovalSerde::class)->resolve($approval);
```

Endpoint and service default serializers apply when a handler payload or context operation does not provide an explicit serializer. Service defaults override endpoint defaults. Explicit `Payload::json()`, `Payload::custom()`, `Payload::binary()`, `Payload::empty()`, and explicit context operation serdes remain authoritative:

```php
$endpoint = Endpoint::builder()
    ->defaultSerde(MessagePackSerde::class)
    ->journalValueCodec(CompressingJournalValueCodec::class)
    ->bind(
        Service::new('Profiles')
            ->defaultSerde(ProfileSerde::class)
            ->handler('load', $loadProfile),
    )
    ->build();
```

Journal value codecs are endpoint-scoped reversible byte transforms. They wrap successful serialized value bytes for handler payloads, state, `ctx.run`, calls/sends, awakeables, invocation output handles, and durable promises. They do not define content types, JSON Schema Metadata, field names, headers, keys, terminal failures, or discovery output. Changing a codec after data has been journaled or stored is a persisted-data compatibility decision unless the codec can still decode old bytes.

Custom serializers bypass JSON Value Mapping because they own the bytes. JSON Payload Adapters such as Symfony and Valinor are different: they map decoded JSON-compatible PHP values and leave JSON bytes to the SDK. Zero-config operations continue to use `JsonSerde::default()` and `NoopJournalValueCodec`.

## Deterministic Time

Use `Context::now()` inside Restate handlers when the current time participates in durable control flow, state, output payloads, IDs, or scheduling calculations. It returns Unix epoch milliseconds and records the value through Restate's durable `ctx.run` journal path, so replay observes the same timestamp instead of calling PHP wall-clock APIs again.

```php
$createdAtMillis = $ctx->now();
$delayMillis = max(0, $deadlineMillis - $ctx->now());

$ctx->sendAfter(Target::service('Reminder', 'send'), ['createdAt' => $createdAtMillis], $delayMillis);
```

Use PHP wall-clock APIs such as `time()`, `microtime(true)`, or `new DateTimeImmutable()` only outside replay-sensitive handler logic, or inside an explicit `Context::run()` side effect when you need to capture another non-deterministic value yourself. `Context::now()` is implemented as a named `Context::run('date.now', ...)`, so it shares the extension support of `Context::run`.

## Runtime Client API

Handlers can call, send, and schedule requests through runtime clients instead of repeating `Target::*()` construction at every call site:

```php
$message = $ctx->serviceClient('Greeter')
    ->request('greet', 'Mary')
    ->call();

$ctx->objectClient('Counter', 'alice')
    ->request('add', 3)
    ->send();

$ctx->workflowClient('Checkout', 'order-1')
    ->request('run', ['sku' => 'book'])
    ->sendAfter(500);
```

`ClientRequest` delegates to the same `Context::call()`, `callFuture()`, `send()` and `sendAfter()` methods as the target API. Set per-request options with `idempotencyKey()`, `limitKey()`, `header()`, `headers()`, `serde()`, `inputSerde()` and `outputSerde()`:

```php
$message = $ctx->serviceClient('Greeter')
    ->request('greet', 'Mary')
    ->header('x-trace-id', 'trace-1')
    ->call();
```

`call()` and `callFuture()` also take `CallOptions`, `send()` and `sendAfter()` `SendOptions` (idempotency key, limit key, headers, serializers, and for `SendOptions` a delay); `Context::genericCall()` and `genericSend()` take them with a `Target`.

## Scopes and Limit Keys

A **scope** groups invocations, virtual object instances and workflow runs, for example per tenant, and is what Restate's concurrency rules apply to. It is part of the target's identity: an object or workflow key, and an idempotency key, name a different instance in each scope (and outside any scope), and Restate co-locates everything in one scope. A **limit key** narrows the concurrency limit within the scope (one or two levels, such as `user-42` or `eu/user-42`); it is not part of the identity. Rules (`restate rules set "tenant-1/*" --concurrency 10`) are configured on the server; see Restate's [flow control](https://docs.restate.dev/services/flow-control) docs.

```php
// Within a scope: the clients of $ctx->scope() build scoped targets.
$ctx->scope('tenant-1')->serviceClient('Billing')
    ->request('charge', $order)
    ->limitKey('user-42')
    ->call();

// The same with a target: scope on the target, limit key on the call or send.
$ctx->send(Target::object('Cart', $cartId, 'checkout')->scoped('tenant-1'), $cart, limitKey: 'eu/user-42');
$ctx->genericCall(Target::service('Billing', 'charge')->scoped('tenant-1'), $order, CallOptions::limitKey('user-42'));

// Attach to a scoped workflow run or idempotent request.
$ctx->attach(AttachTarget::workflow('Checkout', 'order-1', 'tenant-1'))->output();
$ctx->attach(AttachTarget::idempotency(Target::service('Billing', 'charge')->scoped('tenant-1'), 'charge-1'))->output();
```

The callee reads them from `$ctx->request()->scope` and `->limitKey`. A scope and each limit key level are 1 to 36 characters from `[a-zA-Z0-9_.-]`, and a limit key needs a scoped target; anything else throws `\InvalidArgumentException` before the call is journaled. Scope and limit key need service protocol v7 (Restate 1.8 or newer). Restate 1.7 cannot take them: the attempt fails with code 573 ("A call to Billing/charge in scope 'tenant-1' needs service protocol v7 (Restate 1.8 or newer), but this invocation negotiated v6") and Restate retries it. See [Scope and Limit Key](docs/decisions/0024-scope-and-limit-key.md).

## Ingress Client

Code that is not a handler (a web controller, a CLI command, a queue worker, a test) invokes services through the Restate ingress with `Restate\Ingress\IngressClient`, from the `restate/ingress` package. It needs neither `restate/sdk`, the extension nor an endpoint, only a [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client and [PSR-17](https://www.php-fig.org/psr/psr-17/) factories of your choice, for example Guzzle:

```bash
composer require restate/ingress guzzlehttp/guzzle
```

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Restate\Ingress\IngressClient;
use Restate\Ingress\IngressException;
use Restate\Target;
use Restate\TerminalError;

$restate = IngressClient::connect(
    'http://localhost:8080',
    new Client(['timeout' => 30]),
    new HttpFactory(), // request and stream factory
    headers: ['Authorization' => 'Bearer ' . $token], // sent with every request
);
```

Requests take the same shape as the runtime clients in a handler:

```php
// Call and wait for the result.
$greeting = $restate->serviceClient('Greeter')->request('greet', 'Mary')->call();

// An idempotency key: Restate runs the request once per key, and returns the first result to repeated calls.
$restate->objectClient('Counter', 'alice')->request('add', 1)->idempotencyKey('add-1')->call();

// Send without waiting, now or after a delay; attach later to wait for the result.
$sent = $restate->objectClient('Counter', 'alice')->request('add', 1)->header('x-trace-id', 't-1')->send();
$sent->invocationId();          // "inv_1…"
$sent->status();                // SendStatus::Accepted, or PreviouslyAccepted for a repeated idempotency key
$later = $restate->serviceClient('Mailer')->request('remind', $user)->sendAfter(60_000);
$result = $sent->attach();      // waits

// An invocation by id, or by the idempotency key it was sent with.
$output = $restate->invocation($invocationId)->output(); // does not wait
if ($output->isReady()) {
    $result = $output->value();
}
$result = $restate->idempotentInvocation(Target::object('Counter', 'alice', 'add'), 'add-1')->attach();

// Workflows: submit `run` once per key, talk to its shared handlers, wait for or peek at its result.
$checkout = $restate->workflowClient('Checkout', 'order-1');
$checkout->submit(['item' => 'book']);
$checkout->request('approve', ['status' => 'approved'])->call();
$status = $checkout->attach();
$checkout->output()->isReady();

// Awakeables a handler is waiting for.
$restate->resolveAwakeable($awakeableId, ['approved' => true]);
$restate->rejectAwakeable($awakeableId, 'denied by policy');
```

Payloads and results are JSON unless a `Serde` says otherwise: per request with `serde()`, `inputSerde()` and `outputSerde()`, per handle with `attach($serde)` / `output($serde)`, or for the whole client with `connect(..., serde: $serde)`. A request without a payload (or a null payload) sends an empty body, which handlers without input require; an empty result is `null`. The generic `$restate->call()`, `send()` and `sendAfter()` take a `Target`, like `Context::call()`.

[Scopes and limit keys](#scopes-and-limit-keys) work as in a handler: `$restate->scope('tenant-1')->serviceClient('Billing')->request('charge', $order)->limitKey('user-42')->call()`, or a `Target::scoped()` target with `limitKey:` on the generic methods. A scoped workflow's `attach()` / `output()` and `idempotentInvocation()` of a scoped target find the invocation by the id Restate computes for it (one more request). Restate 1.7 rejects scoped requests (400, "scoped invocations require vqueues to be enabled").

When the invocation itself fails, the client throws its `TerminalError` with the status code, message and metadata the handler threw, as `$ctx->call()` does in a handler. Every other failure is a `Restate\Ingress\IngressException` (a `Restate\Exception`): no response at all (`statusCode()` is null), an error of the ingress (an unknown service: 404; a malformed invocation id: 400), or a response that cannot be decoded. It carries `statusCode()`, `errorMessage()`, `errorSource()`, `responseBody()`, `requestMethod()` and `requestUri()`:

```php
try {
    $restate->serviceClient('Payments')->request('charge', $order)->call();
} catch (TerminalError $e) {
    // The handler failed: $e->code(), $e->getMessage(), $e->metadata().
} catch (IngressException $e) {
    // Restate was not reachable, or refused the request.
}
```

Timeouts, TLS, proxies and retries are configured on the HTTP client. **Symfony HttpClient cannot call handlers without input:** its PSR-18 client sends `Content-Type: application/x-www-form-urlencoded` with every bodiless POST, which Restate rejects for such handlers; Guzzle does not. Named signals have no ingress endpoint; send them from a handler. See [Ingress Client](docs/decisions/0022-ingress-client.md) for the design.

## Testing Your Services

`Restate\Testing\RestateTestEnvironment` starts a real Restate server in a container, serves your endpoint entrypoint with `php -S` and the extension loaded, registers it, and hands out an [ingress client](#ingress-client) connected to it. It needs Docker or podman (rootless works: the harness runs the `docker` or `podman` CLI, so no API socket or Testcontainers setup), and `guzzlehttp/guzzle` for the default ingress client. With PHPUnit, use the `InteractsWithRestate` trait in any test case:

```php
use PHPUnit\Framework\TestCase;
use Restate\Testing\PHPUnit\InteractsWithRestate;
use Restate\Testing\PhpCliServer;

final class GreeterTest extends TestCase
{
    use InteractsWithRestate;

    protected static function restateEndpoint(): PhpCliServer
    {
        return PhpCliServer::forScript(__DIR__ . '/../public/restate.php'); // the script that calls Endpoint::serve()
    }

    public function testGreets(): void
    {
        $greeter = self::restate()->ingressClient()->serviceClient('Greeter');

        self::assertSame('Hello Mary', $greeter->request('greet', 'Mary')->call());
    }
}
```

Every test class with the same configuration shares one environment per PHP process; it starts (in about a second once the image is present) before the first test that uses it and is removed when PHPUnit exits, also after a fatal error or Ctrl-C. A failing test prints the Restate log and what the endpoint logged during the test. Restate keeps state between tests, so give each test its own object keys, workflow ids and idempotency keys. `examples/testing/` is a complete example.

Without PHPUnit, the same environment is a plain object:

```php
use Restate\Testing\PhpCliServer;
use Restate\Testing\RestateContainer;
use Restate\Testing\RestateTestEnvironment;

$restate = RestateTestEnvironment::start(
    PhpCliServer::forScript(__DIR__ . '/endpoint.php')
        ->withExtension('/usr/lib/php/restate.so') // optional, see below
        ->withEnvironment(['APP_ENV' => 'test']),
    RestateContainer::default()->withEnvironment(['RESTATE_LOG_FILTER' => 'restate=debug']), // optional
);

$restate->ingressClient();   // or ingressClient($psr18Client, $psr17Factory)
$restate->ingressUrl();      // http://127.0.0.1:<port>, also adminUrl() and endpointUrl()
$restate->registerEndpoint('/v2'); // register a second endpoint the same script serves under a path
$restate->stop();            // or let the process exit
```

- **The extension.** `php -S` runs the PHP that runs the tests (`withPhpBinary()` to change it) and loads the extension given by `withExtension()`, else `RESTATE_PHP_EXTENSION_PATH`, else a module built from source in the project (`extension/target/{debug,release}/restate.so` under the directory holding `vendor/`, where a build from a checkout of this repository leaves it), else whatever its php.ini loads. Before serving, the harness checks in a separate process that the extension loads and matches the package, and fails with PHP's error if not. `withIniSetting()`, `withEnvironment()`, `withWorkers()` (default 8: a handler calling another handler of the same endpoint needs a second worker) and `withPath()` (an endpoint routed below a path) configure the server. It starts with `display_errors=0`, `log_errors=1` and `enable_post_data_reading=0` (`PhpCliServer::DEFAULT_INI_SETTINGS`), as a production server should run: a warning or deprecation goes to the endpoint log instead of in front of the protocol frames, where it would corrupt the response and make Restate retry it for ever, and a journal over `post_max_size` raises no warning. (`display_errors=stderr` does not help under `php -S`: it still writes into the response.)
- **Your image instead of `php -S`.** `EndpointContainer::forImage('my-app:test', 8080)` runs the endpoint in a container (volumes, environment, command, extra `withRunArguments()` such as `--cpus 1 --memory 256m`), for example your production FrankenPHP image. `withHttp2()` registers it for HTTP/2 (h2c) instead of HTTP/1.1.
- **The Restate server.** `RestateContainer::default()` is the pinned `docker.restate.dev/restatedev/restate:1.8.1`, or the image in `RESTATE_PHP_RESTATE_IMAGE`; `forImage()` pins another one. `withEnvironment()` passes Restate server configuration (`RESTATE_…` variables), `withVolume()` mounts a file or directory that configuration points to (for example a request identity key: `withVolume($pem, '/key.pem', readOnly: true)` with `RESTATE_WORKER__INVOKER__REQUEST_IDENTITY_PRIVATE_KEY_PEM_FILE=/key.pem`), `withContainerRuntime()` picks `podman` or a path instead of `docker`, `withRunArguments()` adds `run` options such as resource limits.
- **Restate without a container.** `RestateProcess::default()` runs `restate-server` from `PATH` as a process of the harness instead (`withBinary()` names another; [nix-restate](https://github.com/sagikazarmark/nix-restate)'s `restate-bin` in a Nix or devenv shell, or a [release binary](https://github.com/restatedev/restate/releases), ideally the version of `RestateContainer::DEFAULT_IMAGE`). With a `PhpCliServer` nothing needs Docker, so it runs where containers don't, such as macOS CI runners. It gets a fresh data directory and its own loopback ports, `withEnvironment()` configures it as it does a container (a file a setting points to is a path on your machine, no volume needed), and it sees none of your shell's `RESTATE_…` variables. Return it from `restateContainer()` in the PHPUnit trait, or pass it to `start()`. Every control below works with it: a restart is SIGTERM (or SIGKILL) and a start on the same data directory and ports, a pause is SIGSTOP.
- **Network exposure.** Restate in a container reaches the endpoint through `host.docker.internal`, so the endpoint listens on `0.0.0.0` by default, which **exposes it on your network for the duration of the tests**. Set `withBindAddress()` or `RESTATE_PHP_TEST_BIND` to a narrower address that the container can still reach through `host.docker.internal` where that matters. With a `RestateProcess`, Restate reaches it on loopback, and the endpoint listens on `127.0.0.1` by default. Restate's own ports are on `127.0.0.1` only.
- **Breaking things on purpose.** For tests of what happens when either side fails: `restartRestate(graceful: true|false)`, `pauseRestate()`/`unpauseRestate()` (the container frozen, or the process stopped, connections kept), `killEndpoint()`, `startEndpoint()`, `restartEndpoint()` and `signalEndpoint($signal)`; each waits until things are ready again (`waitUntilReady()`). Restate's ports are chosen by the harness and stay the same across restarts, and its storage (deployments, journals, state) survives them. `registerDeployment($uri, http2: …, force: …)` registers any URI, such as a proxy in front of the endpoint; `testHostForRestate()` is the test host as Restate reaches it (`host.docker.internal`, or `127.0.0.1` for a process). `query($sql)` runs Restate's admin SQL, `invocationRow($id, 'status', 'retry_count')` reads an invocation's `sys_invocation` row and `waitForInvocationStatus($id, 'paused')` polls it. `endpointPort()`, `containerRuntime()` and `restateContainerName()` help with commands of your own (the last two throw when nothing runs in a container).
- **Failures.** Anything that does not start throws `Restate\Testing\TestEnvironmentException` with the tail of both logs; a refused registration throws its subclass `DeploymentRegistrationException` with `statusCode()` and `responseBody()`. Containers carry the label `restate-php-test-environment`; after a `kill -9`, `docker rm -f $(docker ps -aq --filter label=restate-php-test-environment)` removes them (a `RestateProcess` is left running as `restate-server`, to stop by hand).

The harness is the `restate/testing` package (`composer require --dev restate/testing`); it requires `restate/ingress`, not `restate/sdk` or the extension, and does not depend on PHPUnit (only the trait does). See [Test Harness](docs/decisions/0023-test-harness.md).

## Serving

`Endpoint::serve()` answers the current request of a plain PHP entrypoint: `php -S`, PHP-FPM behind nginx, Caddy or Apache, or FrankenPHP. Two other entrypoints cover the rest:

- **PSR-15.** `Endpoint::psr($services, $responseFactory)` returns a PSR-15 request handler (`$handler->handle($serverRequest)`) for frameworks and runtimes that speak PSR-7. The SDK depends only on the PSR interfaces: bring your own PSR-7 implementation and PSR-17 response factory. `examples/slim-psr-endpoint.php` and `examples/mezzio-psr-endpoint.php` route to it from Slim and Mezzio.
- **Long-lived workers** such as FrankenPHP worker mode. `Endpoint::serve()` would build the endpoint again for every request; build it once instead and hand it each request:

  ```php
  $endpoint = Endpoint::builder()->services([...])->build();

  $handler = static function () use ($endpoint): void {
      $endpoint->handle($_SERVER, (string) file_get_contents('php://input'));
  };

  while (frankenphp_handle_request($handler)) {
      gc_collect_cycles();
  }
  ```

  Services registered as objects are then shared by every invocation the worker serves: bind classes with a factory or a container for an instance per attempt ([Service Instances per Invocation](#service-instances-per-invocation)).

The [serving guide](docs/SERVING.md) has the rest: the PHP settings every SAPI needs, the front-door settings PHP-FPM needs (Restate sends request bodies without a length), the FrankenPHP worker script and Caddyfile, and what request/response mode costs for long journals and wide fan-out.

## Attribute API

PHP attributes can define service classes and handler methods. `ServiceReflector::fromObject()` compiles attributes to the same service definitions produced by the builder APIs, so endpoints consume either style identically. Service and Virtual Object handlers can declare explicit payload metadata for discovery with `#[Input]` and `#[Output]`; when explicit schemas are absent, the SDK infers simple JSON Schema metadata from supported handler input and output types. The PHP SDK publishes discovery metadata only. Restate produces OpenAPI after service registration through the Admin API.

```php
<?php

use Restate\Attributes as Restate;
use Restate\Context;
use Restate\Reflection\ServiceReflector;

#[Restate\Service('Greeter', documentation: 'Greeter service.', metadata: ['domain' => 'examples'])]
final class AttributeGreeter
{
    #[Restate\Handler('greet', documentation: 'Greets a person.', metadata: ['owner' => 'sdk'])]
    public function greet(Context $ctx, string $name): string
    {
        return "Hello {$name}";
    }
}

$greeter = ServiceReflector::fromObject(new AttributeGreeter());
```

Service and handler documentation is emitted as manifest documentation; metadata is a strict string-to-string map emitted for discovery/Admin API consumers. `InvocationRetryPolicy` adds service-level or handler-level retry policy metadata for failed handler invocations in discovery v4. This is separate from `RunRetryPolicy`, which applies only to durable `Context::run()` operations.

Advanced payload metadata can keep the serializer content type separate from accepted input content-type patterns and can force an output content type even for empty responses:

```php
use Restate\Attributes as Restate;
use Restate\Payload;

#[Restate\Input(accept: 'application/*+json, application/json')]
#[Restate\Output(setContentTypeIfEmpty: true)]
public function greet(Context $ctx, string $name): string
{
    return "Hello {$name}";
}

$greeter = Service::new('Greeter')->handler(
    'greet',
    static fn (Context $ctx, string $name): string => "Hello {$name}",
    Payload::json()->withAcceptContentType('application/*+json, application/json'),
    Payload::json()->withSetContentTypeIfEmpty(),
);
```

Builders support the same definition documentation and user metadata fields:

```php
$greeter = Service::new('Greeter', documentation: 'Greeter service.', metadata: ['domain' => 'examples'])
    ->handler(
        'greet',
        static fn (Context $ctx, string $name): string => "Hello {$name}",
        documentation: 'Greets a person.',
        metadata: ['owner' => 'sdk'],
    );
```

```php
use Restate\InvocationRetryPolicy;
use Restate\InvocationConfiguration;

$greeter = Service::new(
    'Greeter',
    invocationRetryPolicy: new InvocationRetryPolicy(initialIntervalMillis: 100, maxAttempts: 5),
    invocationConfiguration: new InvocationConfiguration(
        inactivityTimeoutMillis: 30_000,
        abortTimeoutMillis: 60_000,
        journalRetentionMillis: 86_400_000,
        idempotencyRetentionMillis: 86_400_000,
        ingressPrivate: true,
    ),
)->handler(
    'greet',
    static fn (Context $ctx, string $name): string => "Hello {$name}",
    invocationRetryPolicy: new InvocationRetryPolicy(maxAttempts: 3, onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_KILL),
    invocationConfiguration: new InvocationConfiguration(inactivityTimeoutMillis: 10_000),
);
```

`InvocationConfiguration` emits registration metadata for inactivity timeout, abort timeout, journal retention, idempotency retention, workflow completion retention, [state preloading](#state-preloading), and ingress privacy. These fields require discovery manifest v3 or newer (a state preload key list: v5). `workflowCompletionRetentionMillis` is valid only on Workflow `run` handlers; `idempotencyRetentionMillis` is not valid on Workflow `run` handlers.

See `examples/attribute-greeter.php` for a complete endpoint example.

## Service Instances per Invocation

`Endpoint::serve([new Greeter($repository)])` binds the handlers to one object for the endpoint's lifetime. That is one request under `php -S` or PHP-FPM, but many under a long-lived worker such as FrankenPHP worker mode, where dependencies like database connections or tenant-scoped clients would leak between invocations. Bind the class instead, with a factory or a PSR-11 container, and the endpoint creates an instance for every invocation attempt:

```php
use Restate\Endpoint;
use Restate\Request;

$endpoint = Endpoint::builder()
    // A factory gets the invocation's request (target, headers, id).
    ->bind(Greeter::class, static fn (Request $request): Greeter => new Greeter($clients->forTenant($request->header('x-tenant'))))
    // Classes bound without a factory come from the container: get(Checkout::class) per attempt.
    ->container($container)
    ->bind(Checkout::class)
    ->services([Payments::class, new Counter()])
    ->build();
```

- The instance is created once per attempt (each attempt is one HTTP request from Restate), right before the handler runs and inside the [interceptors](#runtime-execution-hooks); a failure to create it fails the attempt like a handler exception and goes through the error mapper. Whether the container returns a new object per `get()` is its configuration (a non-shared service, or a container reset between requests).
- Building the endpoint and answering discovery never instantiate the class: names, handler kinds, payloads and schemas come from its attributes and method signatures. A class without a factory when no container is configured, or one the container does not have, is rejected by `build()`.
- `ServiceReflector::fromClass(Greeter::class, $factoryOrContainer)` returns the `ServiceDefinition` directly, like `fromObject()` does for an instance.

See [Handler Instantiation](docs/decisions/0018-handler-instantiation.md).

## DTO Mapping

Handler inputs and outputs typed as DTOs or backed enums are mapped from and to JSON at run time, and their JSON Schema is inferred for discovery, without any dependency. The built-in mapping covers a safe subset:

- Scalars: `string`, `int`, `float`, `bool`, `null`, the literal types `true` and `false`, their nullable forms, and scalar/null unions. Nullable literal `true`/`false` is not inferred.
- Backed enums, also nullable (the schema then has `null` in both `type` and `enum`).
- Constructor DTOs: a public constructor whose parameters are all promoted public properties of supported types (other DTOs included), and no other public typed properties.
- Property DTOs: no constructor, or a public one whose parameters all have defaults, and public, non-static, typed properties of supported types.
- `#[JsonName('wire_name')]` on a DTO field when the JSON name differs from the PHP name; mapping and schema both use it.
- `stdClass` publishes a generic object schema, but decodes to an associative array; a typed `stdClass` input needs a custom mapper or `Serde`.

Not mapped automatically: arrays or collections with item types, maps, generics, intersections, recursive DTOs, unbacked enums, classes with a required constructor argument that is not a promoted property, unions of several classes, and class unions mixed with non-null scalars. Use explicit payload metadata (`Payload::json($schema)`, `#[Input(schema: …)]`, `#[Output(schema: …)]`), a `JsonSchemaProvider`, a custom `JsonValueMapper`, or a `Serde` for those.

Input that does not fit the declared type is a terminal `400`; output that cannot be normalized is a terminal `500`. A handler whose type needs mapping the built-in mapper cannot do fails with "DTO mapping is not available for Service/handler input." (or output).

Precedence: a `Serde` payload or a serde override on a context operation bypasses JSON value mapping; an endpoint `JsonValueMapper` runs before the built-in one; an explicit schema on `Payload::json()` wins over schema providers; endpoint and service default serdes apply only to inferred non-empty payloads and to context operations without their own serde; journal-value codecs only wrap successful value bytes. Default JSON payload options use the built-in schema provider; options you construct use only the providers you configure, so compose them with `JsonPayloadOptions::withSchemaProviders()` or `FallbackJsonSchemaProvider`.

The [Symfony](#symfony-json-payload-adapter) and [Valinor](#valinor-json-payload-adapter) JSON Payload Adapters replace the built-in mapping with those libraries. Their groups, serialized names, key converters and normalizers can change the wire shape independently of the PHP types, so they publish an unknown schema unless you give one explicitly or compose another provider.

## JsonSchema Builder

`Restate\JsonSchema` is the canonical schema-building and serialization API for JSON Schema Documents and JSON Schema Subschemas.

The builders produce Draft 2020-12 only: the dialect of the OpenAPI 3.1 document Restate generates from the discovery schemas, and the one the official SDKs emit (see [JSON Schema Module](docs/decisions/0025-json-schema-module.md)). The static methods of `JsonSchema` build them:

```php
use Restate\JsonSchema;

$person = JsonSchema::object(
    JsonSchema::string()->title('Display name')->property('displayName')->required(),
    JsonSchema::integer()->title('Age')->property('age'),
)->denyAdditionalProperties();

$document = JsonSchema::document($person);

$person->toArray();
// [
//     'type' => 'object',
//     'properties' => [
//         'displayName' => ['title' => 'Display name', 'type' => 'string'],
//         'age' => ['title' => 'Age', 'type' => 'integer'],
//     ],
//     'required' => ['displayName'],
//     'additionalProperties' => false,
// ]

$document->toArray();
// [
//     '$schema' => 'https://json-schema.org/draft/2020-12/schema',
//     'type' => 'object',
//     'properties' => [
//         'displayName' => ['title' => 'Display name', 'type' => 'string'],
//         'age' => ['title' => 'Age', 'type' => 'integer'],
//     ],
//     'required' => ['displayName'],
//     'additionalProperties' => false,
// ]
```

`property('displayName')` names the object property in the JSON Wire Shape. `title('Display name')` is only a JSON Schema annotation inside that property's schema. `required()` marks the named property as required only when the property schema is collected by an object schema; the property marker is not emitted by itself outside an object context.

Builder values such as `$person` are JSON Schema Subschemas until wrapped as a JSON Schema Document with `JsonSchema::document()`. Normal schema values do not emit `$schema`, because the same schema value can be nested under `properties`, `items`, `$defs`, composition keywords, or other subschema positions. Document wrapping emits `$schema` at an object root and strips any preexisting root `$schema` before inserting the Draft 2020-12 dialect URI. A JSON Schema Boolean Schema document remains the JSON literal `true` or `false`, because a boolean root has no object field where `$schema` can be placed.

JSON Schema Boolean Schemas are different from schemas for JSON boolean values:

```php
JsonSchema::boolean()->toArray();
// ['type' => 'boolean']

JsonSchema::alwaysValid()->toArray();
// true

JsonSchema::neverValid()->toArray();
// false
```

Use `raw()` as an escape hatch when a schema is externally generated, extension-heavy, or needs non-standard/vendor JSON Schema keywords:

```php
$schema = JsonSchema::raw([
    'type' => 'object',
    'x-vendor-keyword' => ['mode' => 'strict'],
]);

$document = JsonSchema::document($schema);
```

`raw()` rejects a root `$schema` other than Draft 2020-12. A complete document in another dialect, for example from a generator that emits Draft-07, can still be published as handler metadata with `Payload::json([...])` or `JsonSchemaMetadata::fromArray()`: both pass the array through unchanged, and Restate stores it without interpreting it.

Use `#[RawSchema]` when a DTO class or field needs an authoritative JSON Schema fragment that native PHP reflection cannot express, such as array item types or map value types:

```php
use Restate\JsonSchema\Attributes\RawSchema;

final readonly class Order
{
    public function __construct(
        #[RawSchema(['type' => 'array', 'items' => ['type' => 'string']])]
        public array $lineItemIds,

        #[RawSchema(['type' => 'object', 'additionalProperties' => ['type' => 'integer']])]
        public array $quantitiesBySku,
    ) {
    }
}
```

Use `#[JsonName]` when a supported DTO field's JSON wire name differs from the PHP property or constructor parameter name. Built-in DTO mapping and built-in DTO schema generation both use the annotated wire name:

```php
use Restate\JsonSchema\Attributes\JsonName;

final readonly class CreateUser
{
    public function __construct(
        #[JsonName('user_name')]
        public string $name,
    ) {
    }
}
```

`JsonSchema` constructs and serializes Draft 2020-12 JSON Schema Documents and JSON Schema Subschemas. It also provides DTO schema generation through `schemaFor()`, raw DTO schema overrides through `#[RawSchema]`, and safe DTO field renames through `#[JsonName]`. Validation, loading schemas from files or URLs, and runtime JSON Value Mapping are separate concerns. Use JSON Schema Metadata APIs, explicit handler payload metadata, and schema providers when discovery metadata must be attached to Restate handlers.

## Symfony JSON Payload Adapter

Applications that already use Symfony Serializer can plug it into the SDK JSON payload path with `Restate\Bridge\Symfony\SymfonyJsonPayloadAdapter`. This is a JSON Payload Adapter, not a `Serde`: Restate still decodes and encodes JSON bytes, while Symfony normalizes and denormalizes decoded JSON-compatible PHP values to and from DTOs.

Install the Symfony components in the consuming project:

```bash
composer require symfony/serializer symfony/property-access symfony/property-info
```

The adapter denormalizes handler input, which is whatever the caller sent, so use a Symfony Serializer with the November 2026 security fixes (6.4.50+, 7.4.23+ or 8.1.11+): earlier versions let a request read server files through `DataUriNormalizer` or uploaded-file denormalization, and reach `#[Ignore]`d properties through dotted keys.

Register one adapter as both the value mapper and schema provider:

```php
$adapter = new SymfonyJsonPayloadAdapter($symfonySerializer);

$endpoint = Endpoint::builder()
    ->jsonPayloads(
        JsonPayloadOptions::new()->withPayloadAdapter($adapter),
    )
    ->bind(new UserService())
    ->build();
```

When a custom schema provider is also installed, keep Symfony as the DTO mapper and compose the custom provider before the conservative Symfony schema provider:

```php
$schemaProvider = new MyJsonSchemaProvider();
$adapter = new SymfonyJsonPayloadAdapter($symfonySerializer);

$jsonPayloads = JsonPayloadOptions::new()
    ->withValueMapper($adapter)
    ->withSchemaProviders($schemaProvider, $adapter);
```

Symfony attributes such as `#[SerializedName]`, `#[Groups]`, `#[Ignore]`, and `#[Context]` configure runtime DTO mapping through the configured Symfony normalizer. Symfony Serializer does not generate Restate JSON Schema Metadata; use explicit `#[Input(schema: ...)]`, `#[Output(schema: ...)]`, or builder `Payload::json($schema)` when discovery needs an accurate wire schema. Do not use `#[Input(serde: ...)]` or `#[Output(serde: ...)]` for normal Symfony DTO mapping, because `serde` means Custom Serializer and bypasses JSON Value Mapping.

See `examples/symfony-json-payloads.php` for reflected-attribute and builder service definition examples using the same Symfony DTOs.

## Valinor JSON Payload Adapter

Applications that use Valinor can plug it into the SDK JSON payload path with `Restate\Bridge\Valinor\ValinorJsonPayloadAdapter`. This is a JSON Payload Adapter, not a `Serde`: Restate still decodes and encodes JSON bytes, while Valinor maps decoded JSON-compatible PHP values to DTOs and normalizes DTOs back to JSON-compatible PHP values.

Install Valinor in the consuming project:

```bash
composer require cuyz/valinor
```

Register one adapter as both the value mapper and schema provider:

```php
$adapter = new ValinorJsonPayloadAdapter($valinorMapper, $valinorNormalizer);

$endpoint = Endpoint::builder()
    ->jsonPayloads(
        JsonPayloadOptions::new()->withPayloadAdapter($adapter),
    )
    ->bind(new UserService())
    ->build();
```

When a custom schema provider is also installed, keep Valinor as the DTO mapper and compose the custom provider before the conservative Valinor schema provider:

```php
$schemaProvider = new MyJsonSchemaProvider();
$adapter = new ValinorJsonPayloadAdapter($valinorMapper, $valinorNormalizer);

$jsonPayloads = JsonPayloadOptions::new()
    ->withValueMapper($adapter)
    ->withSchemaProviders($schemaProvider, $adapter);
```

Configure Valinor's `MapperBuilder` and `NormalizerBuilder` for the wire shape you need, such as key converters, value converters, custom constructors, and normalizer transformers. Valinor does not generate Restate JSON Schema Metadata; use explicit `#[Input(schema: ...)]`, `#[Output(schema: ...)]`, or builder `Payload::json($schema)` when discovery needs an accurate wire schema. Do not use `#[Input(serde: ...)]` or `#[Output(serde: ...)]` for normal Valinor DTO mapping, because `serde` means Custom Serializer and bypasses JSON Value Mapping.

See `examples/valinor-json-payloads.php` for reflected-attribute and builder service definition examples using the same Valinor DTOs.

## License

MIT; see [LICENSE](LICENSE). The extension and every package carry the same license.
