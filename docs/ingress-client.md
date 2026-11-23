# Ingress client

Code that is not a handler, such as a web controller, a CLI command, a queue worker or a test, invokes Restate services through the Restate ingress. `Restate\Ingress\IngressClient` does this over HTTP: it calls and sends to handlers, waits for or reads the result of existing invocations, drives workflows and completes awakeables. Inside a handler, use the context instead ([Service communication](develop/communication.md)).

## Installation

The client is in the `restate/ingress` package. It needs neither `restate/sdk`, the PHP extension nor an endpoint, only a [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client and [PSR-17](https://www.php-fig.org/psr/psr-17/) factories of your choice, for example Guzzle:

```bash
composer require restate/ingress guzzlehttp/guzzle
```

## Connecting

`IngressClient::connect()` takes the ingress URL, the HTTP client and the factories:

```php
<?php

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Restate\Ingress\IngressClient;

// $token is an API token, if your ingress requires one.
$restate = IngressClient::connect(
    'http://localhost:8080',
    new Client(['timeout' => 30]),
    new HttpFactory(), // request and stream factory
    headers: ['Authorization' => 'Bearer ' . $token], // sent with every request
);
```

| Parameter | Meaning |
| --- | --- |
| `$url` | The ingress URL, absolute `http` or `https`; a path prefix is kept. Anything else throws an `\InvalidArgumentException`. |
| `$httpClient` | Any PSR-18 `ClientInterface` |
| `$requestFactory` | A PSR-17 `RequestFactoryInterface` |
| `$streamFactory` | A PSR-17 `StreamFactoryInterface`; omit it when the request factory implements it too, as Guzzle's `HttpFactory` does |
| `headers:` | Headers sent with every request, as a map of names to string values |
| `serde:` | The default serializer for payloads, results and awakeable values; JSON when omitted ([Serialization](#serialization)) |

The client is immutable: create it once and reuse it.

## Calling and sending

Requests take the same shape as the runtime clients in a handler: pick a client for the target, then `request($handler, $payload)`:

```php
// Call and wait for the result.
$greeting = $restate->serviceClient('Greeter')->request('greet', 'Mary')->call();

// An idempotency key: Restate runs the request once per key, and returns the first result to repeated calls.
$restate->objectClient('Counter', 'alice')->request('add', 1)->idempotencyKey('add-1')->call();

// Send without waiting, now or after a delay; attach later to wait for the result.
$sent = $restate->objectClient('Counter', 'alice')->request('add', 1)->header('x-trace-id', 't-1')->send();
$sent->invocationId();     // "inv_1…"
$sent->status();           // SendStatus::Accepted, or PreviouslyAccepted for a repeated idempotency key
$later = $restate->serviceClient('Mailer')->request('remind', 'mary@example.com')->sendAfter(60_000);
$result = $sent->attach(); // waits
```

| `ClientRequest` method | Effect |
| --- | --- |
| `idempotencyKey($key)` | Restate runs the request once per key: a repeated call or send (within the retention) gets the first one's result. An empty key throws an `\InvalidArgumentException`. |
| `limitKey($limitKey)` | The concurrency limit key within the target's scope ([Scopes and limit keys](#scopes-and-limit-keys)) |
| `header($name, $value)` | Adds a header, replacing one of the same name, also one the client sends with every request |
| `headers($headers)` | Replaces the headers set on this request; the client's headers are still sent |
| `serde($serde)`, `inputSerde($serde)`, `outputSerde($serde)` | Serializers ([Serialization](#serialization)) |
| `call()` | Calls the handler and waits for its result |
| `send()` | Sends the request without waiting, and returns a `SendResponse` |
| `sendAfter($delayMillis)` | Sends the request; Restate starts it after the delay. `0` sends at once; a negative delay throws an `\InvalidArgumentException`. |

Each configuration method returns a new request.

A `SendResponse` is Restate's answer to a send:

| Method | Returns |
| --- | --- |
| `invocationId()` | The id of the invocation Restate accepted |
| `status()` | `SendStatus::Accepted` for a new invocation, `SendStatus::PreviouslyAccepted` when Restate had already accepted it (the same idempotency key, or a workflow key) |
| `attach($serde = null)` | Waits for the invocation to finish and returns its result |
| `output($serde = null)` | Reads its output without waiting ([Existing invocations](#existing-invocations)) |
| `invocation()` | The `Invocation` handle behind `attach()` and `output()` |

The generic `$restate->call()`, `send()` and `sendAfter()` take a `Restate\Target`, like `$ctx->call()` in a handler, and the options as named arguments:

```php
<?php

use Restate\Target;

$greeting = $restate->call(Target::service('Greeter', 'greet'), 'Mary');
$sent = $restate->send(Target::object('Counter', 'alice', 'add'), 1, idempotencyKey: 'add-1');
$later = $restate->sendAfter(Target::service('Mailer', 'remind'), 'mary@example.com', 60_000, headers: ['x-trace-id' => 't-1']);
```

## Existing invocations

An `Invocation` is a handle on an invocation that already exists. Get one by invocation id, for example one that a send returned or a handler reported, or by the target and idempotency key it was sent with:

```php
<?php

use Restate\Target;

// $invocationId is the id of an invocation, for example from $sent->invocationId().
$output = $restate->invocation($invocationId)->output(); // does not wait
if ($output->isReady()) {
    $result = $output->value();
}

$result = $restate->idempotentInvocation(Target::object('Counter', 'alice', 'add'), 'add-1')->attach();
```

| Method | What it does |
| --- | --- |
| `attach($serde = null)` | Waits for the invocation to finish and returns its result |
| `output($serde = null)` | Reads the output without waiting, as an `Output` |

`Output::isReady()` is false while the invocation still runs; `Output::value()` returns the result once it is ready, and throws a `\LogicException` before. Both methods throw the invocation's `TerminalError` if it failed.

Restate keeps a completed invocation's result for a retention period, configurable per service and handler. After that, Restate no longer finds the invocation, and both methods fail with an `IngressException` with status 404.

`$restate->invocation()`, `idempotentInvocation()` and `workflowInvocation()` take an optional serializer that decodes the result ([Serialization](#serialization)).

## Workflows

`workflowClient($workflow, $key)` addresses one workflow run: submit its `run` handler once per key, talk to its shared handlers, and wait for or peek at its result:

```php
$checkout = $restate->workflowClient('Checkout', 'order-1');
$checkout->submit(['item' => 'book']);
$checkout->request('approve', ['status' => 'approved'])->call();
$status = $checkout->attach();
$checkout->output()->isReady();
```

| `WorkflowClient` method | What it does |
| --- | --- |
| `submit($input = null)` | Sends `run` without waiting, and returns a `SendResponse`. Submitting a key again returns the existing run with `SendStatus::PreviouslyAccepted`. |
| `request($handler, $payload = null)` | A request to `run` or to a shared handler, to call or send. Use `request('run', $input)` for headers, a delay or another serializer on `run`. |
| `attach($serde = null)` | Waits for `run` to finish and returns its result |
| `output($serde = null)` | Reads the result of `run` without waiting, as an `Output` |

`$restate->workflowInvocation($workflow, $key)` returns the run as an `Invocation`.

To complete a workflow's [durable promise](develop/external-events.md#workflow-durable-promises) from outside, call a shared handler of the workflow that resolves it, as `approve` does above.

## Awakeables

Complete an [awakeable](develop/external-events.md#awakeables) that a handler is waiting for:

```php
// $awakeableId is the id the handler handed out.
$restate->resolveAwakeable($awakeableId, ['approved' => true]);
$restate->rejectAwakeable($awakeableId, 'denied by policy');
```

`resolveAwakeable($id, $value = null, $serde = null)` sends the value. Unlike a call payload, `null` is a value here: it is sent as JSON `null`, as `$ctx->resolveAwakeable()` does in a handler. The serializer must match the one the waiting handler decodes with. `rejectAwakeable($id, $reason)` fails the awakeable: the waiting handler throws a `TerminalError` with status code 500 and the reason as its message.

## Serialization

Payloads and results are JSON unless a `Restate\Serde` says otherwise. Set one at three levels; the narrower one wins:

| Level | How |
| --- | --- |
| Per request | `serde()` for both, `inputSerde()` for the payload, `outputSerde()` for the result |
| Per handle | `attach($serde)` / `output($serde)`, or the `$serde` argument of `invocation()`, `idempotentInvocation()` and `workflowInvocation()` |
| Whole client | `IngressClient::connect(..., serde: $serde)` |

The ingress client's serializer methods take a `Serde` instance, not a class name.

A request without a payload, or with a `null` payload, sends an empty body. Handlers that take no input require that. An empty result decodes to `null`. Both apply to the JSON serializer; a custom serializer encodes and decodes `null` itself. See [Serialization](develop/serialization.md) for writing a serializer.

## Scopes and limit keys

[Scopes and limit keys](develop/communication.md#scopes-and-limit-keys) work as in a handler:

```php
<?php

use Restate\Target;

// $order is the order to charge.
$restate->scope('tenant-1')->serviceClient('Billing')->request('charge', $order)->limitKey('user-42')->call();

// The same with a target: the scope on the target, the limit key as a named argument.
$restate->call(Target::service('Billing', 'charge')->scoped('tenant-1'), $order, limitKey: 'user-42');
```

`$restate->scope($scope)` returns clients whose targets are all in the scope, including a workflow client's `attach()` and `output()`. For an `Invocation` of a scoped run, pass the scope to `workflowInvocation($workflow, $key, scope: 'tenant-1')`.

The client checks scopes and limit keys when you build the request, with the same rules as in a handler: a scope is 1 to 36 characters from `[a-zA-Z0-9_.-]`, a limit key is one or two such levels separated by `/`, and a limit key needs a scoped target. Anything else throws an `\InvalidArgumentException`.

A scoped workflow's `attach()` and `output()`, and `idempotentInvocation()` of a scoped target, first look up the invocation id that Restate computes for it: one more request.

> [!NOTE]
> Needs Restate 1.8 or newer. On 1.7 the ingress rejects scoped requests with status 400 ("scoped invocations require vqueues to be enabled") ([Compatibility](compatibility.md#restate-17)).

## Errors

When the invocation itself fails, the client throws its `Restate\TerminalError`, with the status code, message and metadata that the handler threw, as `$ctx->call()` does in a handler.

Every other failure is a `Restate\Ingress\IngressException` (a `Restate\Exception`):

- no response at all, for example a connection failure (`statusCode()` is null);
- an error of the ingress, for example an unknown service (404), a malformed invocation id (400) or an invocation past its retention (404);
- an error from something between the client and Restate, such as a proxy;
- a response that cannot be decoded.

```php
<?php

use Restate\Ingress\IngressException;
use Restate\TerminalError;

try {
    $restate->serviceClient('Payments')->request('charge', $order)->call();
} catch (TerminalError $e) {
    // The handler failed: $e->code(), $e->getMessage(), $e->metadata().
} catch (IngressException $e) {
    // Restate was not reachable, or refused the request.
}
```

| `IngressException` method | Returns |
| --- | --- |
| `statusCode()` | The HTTP status, or null when no response was received |
| `errorMessage()` | The `message` of Restate's JSON error body, if any |
| `errorSource()` | Whose error Restate says it is, for example `ingress`; null when the response did not say |
| `responseBody()` | The response body as received, or null without a response |
| `requestMethod()`, `requestUri()` | The request that failed |

## HTTP client notes

Configure timeouts, TLS, proxies and retries on your HTTP client. The ingress client sends each request once and does not retry on its own. `call()` and `attach()` wait as long as the handler runs, so give the HTTP client a timeout that fits your slowest handler. For long-running work, `send()` and read the result later with `output()`.

> [!WARNING]
> Symfony HttpClient cannot call handlers without input. Its PSR-18 client sends `Content-Type: application/x-www-form-urlencoded` with every bodiless POST, which Restate rejects for such handlers. Guzzle does not.

Named signals have no ingress route. To signal an invocation from outside, call a handler that sends the signal ([Signals](develop/external-events.md#signals)).

## Design notes

Background for contributors:

- [Ingress Client](decisions/0022-ingress-client.md)
- [Scope and Limit Key](decisions/0024-scope-and-limit-key.md)
