# Logging and hooks

This page shows how to see what the SDK does through a PSR-3 logger, how to log from handlers without duplicate lines on replay, and how to run your own code around every handler attempt and `$ctx->run()` callback with interceptors.

## Logging

Give the endpoint a PSR-3 logger (Monolog or any other) to see what the SDK does. Without one, it logs nothing.

```php
<?php

use Restate\Endpoint;

// $logger: any Psr\Log\LoggerInterface
$endpoint = Endpoint::builder()
    ->services([new Greeter()])
    ->logger($logger)
    ->build();

Endpoint::serve([new Greeter()], logger: $logger); // the shortcuts take one too
```

`Endpoint::psr()` takes a `logger:` argument as well. The SDK hands every record to the logger, so configure the levels you want on the logger itself:

| Level | What the SDK logs |
| --- | --- |
| error | A request failed with 500, or was rejected because its request identity did not verify (401). PHP printed output before the endpoint could respond, so the request was not run. |
| warning | A handler attempt failed and Restate retries it; a `$ctx->run()` callback failed; the invocation paused. A request without a content type, or a discovery request without a supported version (415). Output printed while handling a request was discarded. |
| info | The invocation failed with a `TerminalError`. |
| debug | Each attempt's start (`Starting invocation` or `Replaying invocation`), success and suspension. |

Records about an invocation carry `restate.invocation.id` and `restate.invocation.target` (`Service/handler`, or `Service/key/handler`) in their context; a record about a failure has the exception under `exception`. Records about a request that is not an invocation carry `restate.request.path` instead.

The lifecycle records are at debug because, in request/response mode, every suspension is a new HTTP request: at info they would write several lines per invocation for things the Restate UI already shows.

A logger that throws loses the record, but never fails the request.

## Logging from handlers

In a handler, log through `$ctx->logger()`:

```php
<?php

// in a handler; $order: the order being charged, $payments: your payment client
$ctx->logger()->info('Charging', ['order' => $order->id]);
$ctx->run('charge', fn () => $payments->charge($order));
```

It is the endpoint's logger, with two differences:

- It adds `restate.invocation.id` and `restate.invocation.target` to each record (keys you pass yourself win).
- It drops records while the handler replays journal entries of an earlier attempt. Each line is logged once per invocation rather than once per attempt.

Without an endpoint logger, `$ctx->logger()` discards everything.

To log replay-aware to another logger, wrap it:

```php
<?php

use Restate\Logging\ReplayAwareLogger;

// in a handler; $auditLogger: another PSR-3 logger, $order: the order being approved
$audit = new ReplayAwareLogger($ctx, $auditLogger);
$audit->notice('Order approved', ['order' => $order->id]);
```

## Logs from the extension

The Restate PHP extension runs Restate's shared state machine library (`restate-sdk-shared-core`), the Rust code that tracks the journal, and it logs too. Mostly at debug, it logs:

- the full description of a journal mismatch (a handler that no longer takes the path its journal recorded),
- each suspension,
- each command it executes.

By default its records at info or above go to the same logger. Their context has the invocation keys, `restate.core.target` (the Rust module) and any other field as `restate.core.<name>`. To see its debug records while you investigate a problem, lower the level; pass `null` to forward none:

```php
<?php

use Psr\Log\LogLevel;
use Restate\Endpoint;

// $logger: any PSR-3 logger
$endpoint = Endpoint::builder()
    ->services([new Greeter()])
    ->logger($logger, coreLogLevel: LogLevel::DEBUG)
    ->build();
```

`coreLogLevel` takes a `Psr\Log\LogLevel` constant; anything else throws `\InvalidArgumentException`. Capturing these records costs a little on every call into the extension, so only lower the level while you investigate.

## Interceptors

Interceptors run your code around every handler attempt and every executed `$ctx->run()` callback, for tracing, metrics or logging. Implement `Restate\HandlerInterceptor`, `Restate\RunInterceptor`, or both:

```php
<?php

use Psr\Log\LoggerInterface;
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
        $start = hrtime(true);
        try {
            $next();
            $outcome = 'ok';
        } catch (\Throwable $e) {
            $outcome = Suspension::is($e) ? 'suspended' : 'failed';

            throw $e; // always rethrow
        } finally {
            $this->logger->info('attempt', ['invocation' => $request->id, 'outcome' => $outcome, 'ns' => hrtime(true) - $start]);
        }
    }

    public function interceptRun(RunExecution $execution, \Closure $next): void
    {
        $this->logger->info("run {$execution->name}", ['invocation' => $execution->request->id]);
        $next();
    }
}
```

| Interface | Method | Gets |
| --- | --- | --- |
| `HandlerInterceptor` | `interceptHandler(HandlerExecution $execution, \Closure $next): void` | `$execution->request` (the [invocation request](services.md#the-invocation-request), with this attempt's headers) and `$execution->isReplaying()` |
| `RunInterceptor` | `interceptRun(RunExecution $execution, \Closure $next): void` | `$execution->request` and `$execution->name` (the name passed to `$ctx->run()`) |

Register interceptors on the endpoint (for every service) or on a service builder (for that service only):

```php
<?php

use Restate\Context;
use Restate\Endpoint;
use Restate\Service;

// $logger: any PSR-3 logger; TenantScope: another HandlerInterceptor of yours
$endpoint = Endpoint::builder()
    ->interceptor(new TimingInterceptor($logger))            // every service
    ->bind(Service::new('Greeter')
        ->interceptor(new TenantScope())                     // this service, inside the endpoint's
        ->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}"))
    ->build();
```

For a service defined with attributes, reflect it and add them to the definition: `ServiceReflector::fromObject($greeter)->withInterceptors(new TenantScope())`.

### Tracing

When tracing is enabled on the Restate server (its `tracing-endpoint` option), Restate puts the W3C trace context of each attempt in the `traceparent` and `tracestate` headers of its request to the endpoint. The SDK ships no tracer, but interceptors are all one needs: read the parent context from the attempt headers, open a span around `$next()` in `interceptHandler()`, and one around each executed run in `interceptRun()`:

```php
// inside interceptHandler(), as in TimingInterceptor above
$traceparent = $execution->request->attemptHeaders['traceparent'] ?? null;
```

Use `$execution->isReplaying()` to suppress span events during replay, and `Suspension::is($e)` to end the span without an error status when the invocation suspends. Read the attempt headers, not `$request->headers`: those are the headers the caller sent when it started the invocation, and are the same on every attempt.

## Interceptor rules

The SDK enforces these rules:

| Topic | Rule |
| --- | --- |
| Order | Endpoint interceptors wrap service interceptors. At each level, the first one registered is the outermost. An object implementing both interfaces joins both chains. |
| Replay | A handler interceptor runs on every attempt, also on attempts that replay the journal; `$execution->isReplaying()` says whether the handler is still replaying (it turns false once the handler passes the end of the journal). A run interceptor runs only when the callback executes, never when its result is replayed from the journal. `$ctx->now()` counts as a run named `date.now`. |
| `$next` | Runs the rest of the chain and then the handler (including creating a [factory-built service instance](services.md#service-instances-and-dependencies), decoding the input and encoding the output) or the run callback. It returns nothing: interceptors cannot change results. Call it exactly once; returning without calling it, or calling it twice, fails the attempt with a `\LogicException`. |
| Failures | An interceptor cannot swallow a failure: if `$next` threw and the interceptor returns, the SDK rethrows what `$next` threw. It may throw another exception instead, such as a `TerminalError`, which then goes to the [error mapper](errors.md#mapping-exceptions-with-an-error-mapper). |
| Suspension | When the invocation suspends, `$next` throws the SDK's internal suspension. It always passes through: whatever the interceptor does with it, the attempt ends as a suspension. Use `finally`, or catch `\Throwable` only to observe and rethrow, and tell a suspension from a failure with `Suspension::is($e)`. |
| Context | Interceptors do not get the handler's context. Do not issue journal operations (calls, state, runs) from them. |

Handler interceptors see a handler's exception before the error mapper does, so they see it unmapped.

## Design notes

- [Runtime Execution Hooks](../decisions/0017-runtime-execution-hooks.md)
- [SDK Logging](../decisions/0027-sdk-logging.md)
- [Stray Output](../decisions/0032-stray-output.md)
