# Error handling

This page explains how failures in handlers turn into retries, terminal failures or paused invocations, how to classify your own exceptions, and which exceptions the SDK throws.

## Terminal and retryable errors

Restate retries a failed handler attempt until it succeeds. That makes transient failures, such as a network error or a crashed process, harmless. Some failures will never go away by retrying, though: a declined payment, a validation error. For those, throw a `Restate\TerminalError`, which fails the invocation without retries ([Retries and terminal errors](../concepts.md#retries-and-terminal-errors)).

| Thrown by the handler | Result |
| --- | --- |
| `TerminalError` | The invocation fails with this error. No retries. |
| `RetryableError` | The attempt fails and Restate retries the invocation, optionally after a given delay ([Retrying with a delay](#retrying-with-a-delay)). |
| `PauseError` | The invocation pauses until someone resumes it ([Pausing invocations](#pausing-invocations)). |
| Any other exception | The attempt fails and Restate retries the invocation. |

A `TerminalError` carries a message, an HTTP-style status code (400 to 599, default 500) and optional metadata (a string-to-string map). The caller receives all three:

```php
<?php

use Restate\TerminalError;

throw new TerminalError('Card declined', 422, ['reason' => 'insufficient_funds']);
```

The SDK throws `TerminalError` as well, when something your handler waits for failed terminally: a call to another handler, an awakeable, a signal, a workflow promise or a `$ctx->run()` step. It also throws `TerminalError('Invocation cancelled', 409)` when the invocation is cancelled while it waits. Catch it to react, for example to compensate or return a fallback:

```php
<?php

use Restate\Target;
use Restate\TerminalError;

// in a handler; $order: the order to charge
try {
    $ctx->call(Target::service('Payments', 'charge'), $order);
} catch (TerminalError $e) {
    return ['declined' => $e->getMessage(), 'code' => $e->statusCode(), 'metadata' => $e->metadata()];
}
```

Throwing `RetryableError` instead of a plain exception is useful when you want to set a retry delay, or when an [error mapper](#mapping-exceptions-with-an-error-mapper) would otherwise classify the exception differently.

## Invalid input

Handler input that cannot be decoded fails the invocation with a terminal `400` before the handler runs. That covers malformed JSON, a `\JsonException` from the input serializer, and a value that does not fit the handler's declared input type ([DTOs and enums](serialization.md#dtos-and-enums)). Retrying cannot fix the input, so the SDK does not retry.

Invalid JSON anywhere else is retried like any other exception: a `\JsonException` in your handler code or while encoding the output, or a called handler's result that does not decode.

A return value that does not fit the handler's declared output type fails the invocation with a terminal `500`.

## Never catch Throwable around context calls

Context calls that wait (sleeps, calls, awakeables, signals, promises, `$ctx->run()`) may suspend the invocation: the attempt ends, and Restate resumes the invocation later, when the result is there ([Request/response mode and suspension](../concepts.md#requestresponse-mode-and-suspension)). The SDK ends the attempt by unwinding the handler with an internal `\Error`. Neither `catch (\Exception)` nor `catch (\Restate\Exception)` sees it.

> [!WARNING]
> **Do not catch `\Throwable` or `\Error` around context calls.** If you do, your catch block runs on every suspension, as if the call had failed. Every later context call in that attempt throws again, and the endpoint ignores whatever the handler returns or throws: the attempt still ends as a suspension. Catch `\Exception`, `TerminalError` or your own exception types instead.

If you must catch `\Throwable` (for example in a generic wrapper), rethrow the suspension untouched. `Restate\Suspension::is($e)` recognises it:

```php
<?php

use Restate\Suspension;

// in a handler; $target and $payload: what to call
try {
    $result = $ctx->call($target, $payload);
} catch (\Throwable $e) {
    if (Suspension::is($e)) {
        throw $e;
    }

    $result = null; // your own error handling
}
```

## Retrying with a delay

Throw `RetryableError` with `retryAfterMillis` to fail the attempt and tell Restate how long to wait before the next one, for example while a resource you need is busy:

```php
<?php

use Restate\RetryableError;

// $lock: a lock your handler needs, held by another process for a while
if (!$lock->tryAcquire()) {
    throw new RetryableError('account is locked', retryAfterMillis: 5_000);
}
```

From a handler, the delay replaces the delay of the [invocation retry policy](#retry-policies) for the next attempt. The attempt fails with the error's status code (500 for any other exception); its `metadata` is not reported to Restate, since a failed attempt carries none.

Inside a `$ctx->run()` callback, `RetryableError` fails that run attempt instead. Restate waits `retryAfterMillis` instead of the run retry policy's interval, while the policy's attempt and duration limits still apply. Once the policy gives up, the run fails terminally with the last `RetryableError`'s message, status code and metadata. See [Retry-after delays](durable-steps.md#retry-after-delays) for the details.

## Retry policies

Two separate retry policies apply, at different levels:

| Policy | Applies to | Configured | Gives up by |
| --- | --- | --- | --- |
| `InvocationRetryPolicy` | Failed handler attempts. Restate retries the whole invocation, replaying its journal. | Per service or handler, at discovery ([Service configuration](services.md#invocationretrypolicy)) | Killing or pausing the invocation (`onMaxAttempts`) |
| `RunRetryPolicy` | A failing `$ctx->run()` callback. Only that step runs again. | Per `$ctx->run()` call ([Retry policies](durable-steps.md#retry-policies)) | Failing the run with a `TerminalError`, or pausing the invocation (`OnMaxAttempts::Pause`) |

Without an `InvocationRetryPolicy`, Restate's server defaults apply. Without a `RunRetryPolicy` (or with `RunRetryPolicy::infinite()`), a failing run fails the attempt like any other exception: the invocation retry policy decides the delay and whether there is another attempt.

## Pausing invocations

Some failures do not go away by retrying but can be fixed by a person: a missing approval, a misconfiguration, a bug. Throw `Restate\PauseError` to end the attempt and have Restate keep the invocation paused instead of retrying it:

```php
<?php

use Restate\PauseError;

// $gateway and $order: your payment gateway client and the order being processed
$ctx->run('charge-card', static function () use ($gateway, $order): string {
    if (!$gateway->isConfigured()) {
        throw new PauseError('payment gateway is not configured');
    }

    return $gateway->charge($order);
});
```

What happens on resume depends on where you threw it:

- **From a `$ctx->run()` callback**, the run records no result, whatever its retry policy, and runs again once the invocation is resumed.
- **From the handler itself**, the attempt ends. On resume, the handler runs again from the start, replaying its journal. Code outside `$ctx->run()` is deterministic, so a handler-level pause usually needs a new deployment to get past it.

An [error mapper](#mapping-exceptions-with-an-error-mapper) may return a `PauseError` too, for example for `\LogicException`.

Retry policies can pause instead of giving up. A run retry policy pauses with `OnMaxAttempts::Pause`:

```php
<?php

use Restate\OnMaxAttempts;
use Restate\RunRetryPolicy;

// $charge: the callable that charges the card
$ctx->run('charge-card', $charge, RunRetryPolicy::exponential(maxAttempts: 5, onMaxAttempts: OnMaxAttempts::Pause));
```

An invocation retry policy pauses with `onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_PAUSE`; then Restate itself pauses the invocation when the policy gives up.

Resume a paused invocation with `restate invocations resume <id>`, in the Restate UI, or through the admin API (`PATCH /invocations/{id}/resume`). Add `?deployment=latest` to move an invocation pinned to an older deployment to the latest one, for example after you deployed a fix.

> [!NOTE]
> Needs Restate 1.8 or newer. On 1.7 the attempt fails instead with error 573 and a message saying that pausing needs a newer Restate (for a pausing run retry policy: "Feature 'pause' is not supported …", at the run's first retryable failure), and Restate retries it like any other failure ([Compatibility](../compatibility.md#restate-17)).

## Mapping exceptions with an error mapper

An error mapper classifies exceptions, so your domain code does not need to know about Restate. It receives every exception that is not already a `TerminalError`, `RetryableError` or `PauseError`, from handlers and from `$ctx->run()` callbacks, and from the interceptors around them:

```php
<?php

use Restate\Endpoint;
use Restate\RetryableError;
use Restate\TerminalError;

// InsufficientFunds and RateLimited: your domain exceptions; Payments: an attributed service class
$endpoint = Endpoint::builder()
    ->errorMapper(static fn (\Throwable $e): TerminalError|RetryableError|null => match (true) {
        $e instanceof InsufficientFunds => new TerminalError($e->getMessage(), 422, ['account' => $e->account], $e),
        $e instanceof RateLimited => new RetryableError('rate limited', retryAfterMillis: $e->resetMillis, previous: $e),
        default => null, // unchanged: retried
    })
    ->services([new Payments()])
    ->build();
```

| The mapper returns | Result |
| --- | --- |
| `TerminalError` | Fails the invocation (or the run, without retrying it) with its status code and metadata |
| `RetryableError` | Retried, with its retry-after delay and status code (and, in a run, its metadata) |
| `PauseError` | Pauses the invocation |
| `null` | No change: the exception is retried |

- A mapper is a closure or an implementation of `Restate\ErrorMapper` (one method, `map(\Throwable $error)`).
- Pass the original exception as `previous`, so it stays in the logs.
- If the mapper throws, that exception fails the attempt.
- Services can have their own mapper: `Service::errorMapper()`, `VirtualObject::errorMapper()`, `Workflow::errorMapper()`, or `ServiceDefinition::withErrorMapper()` for an attributed service. The service's mapper is asked first, the endpoint's when the service's returns `null`.
- A handler's exception reaches the mapper after the handler interceptors, which see it unmapped ([Interceptors](logging-and-hooks.md#interceptors)).
- The mapper never sees a suspension.

## Exception reference

Every exception the SDK throws for you to handle implements the marker interface `Restate\Exception`, so `catch (\Restate\Exception $e)` catches all of them, and never a suspension.

| Exception | Package | Thrown when |
| --- | --- | --- |
| `Restate\TerminalError` | `restate/common` | You throw it to fail an invocation or run without retries. The SDK throws it for a terminal failure of a call, awakeable, signal, promise or run, and for a cancellation (409). The ingress client throws it when the invocation failed. |
| `Restate\RetryableError` | `restate/sdk` | You throw it to request a retry, optionally with a delay, status code and metadata. |
| `Restate\PauseError` | `restate/sdk` | You throw it to pause the invocation. |
| `Restate\JsonMappingException` | `restate/common` | A JSON value does not fit the declared PHP type (a DTO, enum or scalar), for example when a typed call result or an ingress response is decoded. Handler input that does not fit becomes a terminal `400` instead. |
| `Restate\UnsupportedJsonSchemaException` | `restate/sdk` | No JSON Schema provider can describe a handler's input or output type. Custom providers throw it to pass to the next one ([JSON Schema for discovery](serialization.md#json-schema-for-discovery)). |
| `Restate\DurableFutureAggregateException` | `restate/sdk` | Every future passed to `$ctx->any()` failed (or it got none); `failures()` lists them ([Durable Futures](concurrency.md)). |
| `Restate\BackendUnavailableException` | `restate/sdk` | Building the endpoint, when the Restate PHP extension is not loaded or does not match the SDK version ([Installation](../installation.md#checking-that-it-loads)). |
| `Restate\Ingress\IngressException` | `restate/ingress` | An ingress request failed for a reason other than the invocation's own failure: no response, a refusal by Restate (unknown service, unknown invocation), or an undecodable response ([Errors](../ingress-client.md#errors)). |
| `Restate\Testing\TestEnvironmentException` | `restate/testing` | The test environment could not start or be used: no container runtime, a container or endpoint that does not start, a Restate server that does not become ready ([When the environment does not start](testing.md#when-the-environment-does-not-start)). |
| `Restate\Testing\DeploymentRegistrationException` | `restate/testing` | Restate refused to register the test endpoint, for example because discovery failed. Extends `TestEnvironmentException`; `responseBody()` has Restate's reason. |

The SDK also uses an internal `Restate\Internal\BackendException` (it implements `Restate\Exception` too) for failures of the extension, such as a journal mismatch or a feature the Restate server is too old for. It fails the attempt, and Restate retries it. Do not catch it specifically.

PHP's own exceptions are not wrapped: invalid arguments throw `\InvalidArgumentException`, and a serializer's `\JsonException` propagates as it is.

## Design notes

- [Suspension Is Not an Exception](../decisions/0013-suspension-is-not-an-exception.md)
- [Exception Hierarchy](../decisions/0015-exception-hierarchy.md)
- [Runtime Execution Hooks](../decisions/0017-runtime-execution-hooks.md)
- [Pause](../decisions/0021-pause.md)
