# Service communication

Handlers call other handlers through the context: they wait for a response, send a one-way message, or schedule a message for later. This page covers the runtime clients, the target-based methods underneath them, idempotency keys and headers, handles on other invocations, and scopes and limit keys. To invoke services from code that is not a handler, use the [ingress client](../ingress-client.md).

## Request-response calls

A call invokes a handler and waits for its result. Build it with a runtime client from the context, then `call()` it:

```php
<?php

use Restate\Attributes as Restate;
use Restate\Context;

#[Restate\Service('Onboarding')]
final class Onboarding
{
    #[Restate\Handler]
    public function welcome(Context $ctx, string $name): string
    {
        // A Basic Service: name the service and the handler.
        $greeting = $ctx->serviceClient('Greeter')->request('greet', $name)->call();

        // A Virtual Object or a Workflow: also name the key.
        $count = $ctx->objectClient('Counter', $name)->request('add', 1)->call();

        return "{$greeting} (visit {$count})";
    }
}
```

The call is durable. Restate journals it, delivers the request to the callee, and journals the result. While the callee runs, the caller suspends; when the result is in, Restate resumes the caller, which replays its journal and continues with the result. A retried or resumed attempt never calls the handler a second time. A call to a handler without output (`void`) returns `null`, as does the output of its invocation handle; a custom output serializer gets the empty bytes to decode.

If the callee throws a terminal error, the call throws the same `TerminalError` in the caller, with its status code, message and metadata. Catch it to compensate, or let it fail the caller as well ([Error handling](errors.md#terminal-and-retryable-errors)).

To start several calls and wait for them together, use `callFuture()` instead of `call()`. It returns a `DurableFuture` that you combine with `$ctx->all()`, `$ctx->select()` and the other combinators ([Durable Futures](concurrency.md)):

```php
// Inside a handler: $ctx is its Context.
$futures = [
    'en' => $ctx->serviceClient('Greeter')->request('greet', 'Mary')->callFuture(),
    'fr' => $ctx->serviceClient('Translator')->request('greet', 'Marie')->callFuture(),
];
$greetings = $ctx->all($futures); // ['en' => …, 'fr' => …]
```

> [!WARNING]
> A Virtual Object's exclusive handler that calls an exclusive handler of the same object and key waits forever: the callee queues behind the caller. Use `send()`, or make the callee shared.

The callee is invoked through its deployment like any other request. With `php -S`, set `PHP_CLI_SERVER_WORKERS` so the endpoint serves several requests at once ([Running with php -S](../serving.md#running-with-php--s)).

## Sending messages

A send starts an invocation and does not wait for it. Restate journals the send and guarantees the callee runs, independently of the caller:

```php
// Inside a handler: $ctx is its Context.
$handle = $ctx->objectClient('Counter', 'alice')->request('add', 3)->send();
```

`send()` returns an [invocation handle](#invocation-handles) on the new invocation. Use it to read the invocation's id, wait for its output later, cancel it or signal it. You do not have to: a send is fire-and-forget, and the caller may finish before the callee starts. A failure of the callee does not reach the caller unless it waits for the output.

## Delayed messages

`sendAfter()` sends a message that Restate delivers after a delay, in milliseconds. Restate keeps the timer, so the delay survives restarts of your endpoint and of Restate:

```php
// Inside a handler: $ctx is its Context.
$ctx->workflowClient('Checkout', 'order-1')
    ->request('run', ['sku' => 'book'])
    ->sendAfter(500);
```

The caller does not wait for the delay; it gets an invocation handle right away. A negative delay throws an `\InvalidArgumentException`. [Timers](timers.md#scheduling-with-delayed-messages) shows how to schedule work with delayed messages.

## Runtime clients

The context hands out one client per target kind. Each builds requests to one service, object key or workflow key:

| Method | Returns | Targets |
| --- | --- | --- |
| `$ctx->serviceClient($service)` | `ServiceClient` | A Basic Service |
| `$ctx->objectClient($object, $key)` | `ObjectClient` | One Virtual Object key |
| `$ctx->workflowClient($workflow, $key)` | `WorkflowClient` | One workflow run |
| `$ctx->scope($scope)` | `Scope`, with the same three methods | The same, within a scope ([Scopes and limit keys](#scopes-and-limit-keys)) |

`request($handler, $payload)` on a client returns a `ClientRequest`. Its configuration methods each return a new request, so a configured request can be reused:

| `ClientRequest` method | Effect |
| --- | --- |
| `idempotencyKey($key)` | Run the request once per key ([Idempotency keys and headers](#idempotency-keys-and-headers)) |
| `limitKey($limitKey)` | The concurrency limit key within the target's scope ([Scopes and limit keys](#scopes-and-limit-keys)) |
| `header($name, $value)` | Add one header |
| `headers($headers)` | Replace the request's headers |
| `serde($serde)` | The serializer for both the payload and the result |
| `inputSerde($serde)` / `outputSerde($serde)` | The serializer for the payload / for the result |
| `call()` / `callFuture()` | Call and wait / call and return a `DurableFuture` |
| `send()` / `sendAfter($delayMillis)` | Send now / after a delay, and return an `InvocationHandle` |

Serializers are covered in [Serialization](serialization.md#custom-serializers).

`WorkflowClient` adds two shortcuts. `submit($input)` sends the workflow's `run` handler; a workflow runs once per key, so Restate does not start a second run for the same key. `attach()` returns a handle on the run, the same as `$ctx->attach(AttachTarget::workflow(…))`:

```php
// Inside a handler: $ctx is its Context.
$checkout = $ctx->workflowClient('Checkout', 'order-1');
$checkout->submit(['sku' => 'book']);
$checkout->request('approve', ['status' => 'approved'])->send();
$receipt = $checkout->attach()->output();
```

### Targets and the generic methods

Every client request ends in one of the context's own methods: `$ctx->call()`, `callFuture()`, `send()` and `sendAfter()`. You can call them directly with a `Restate\Target`, built by `Target::service($service, $handler)`, `Target::object($object, $key, $handler)` or `Target::workflow($workflow, $key, $handler)`. Their options are named arguments:

```php
<?php

use Restate\Target;

// Inside a handler: $ctx is its Context.
$greeting = $ctx->call(Target::service('Greeter', 'greet'), 'Mary');
$future = $ctx->callFuture(Target::object('Counter', 'alice', 'get'), null);
$handle = $ctx->send(Target::object('Counter', 'alice', 'add'), 3, idempotencyKey: 'add-3');
$later = $ctx->sendAfter(Target::service('Mailer', 'remind'), 'mary@example.com', 60_000, headers: ['x-trace-id' => 'trace-1']);
```

`$ctx->genericCall()` and `$ctx->genericSend()` take the options as one object instead: a `CallOptions` for calls and a `SendOptions` for sends. `ClientRequest::call()`, `callFuture()`, `send()` and `sendAfter()` accept the same objects; their values win over the request's, and their headers are added after the request's.

```php
<?php

use Restate\CallOptions;
use Restate\SendOptions;
use Restate\Target;

// Inside a handler: $ctx is its Context.
$receipt = $ctx->genericCall(
    Target::service('Payments', 'charge'),
    ['amount' => 1200],
    CallOptions::idempotencyKey('charge-order-1')->withHeader('x-trace-id', 'trace-1'),
);

$ctx->genericSend(Target::service('Mailer', 'remind'), 'mary@example.com', SendOptions::delayMillis(60_000));

$ctx->serviceClient('Mailer')
    ->request('remind', 'mary@example.com')
    ->send(SendOptions::delayMillis(60_000)->withIdempotencyKey('remind-mary'));
```

Both option classes start from a static constructor and chain `with…()` methods, each returning a new object:

| Static constructor | Chained method | Option | `CallOptions` | `SendOptions` |
| --- | --- | --- | --- | --- |
| `none()` | | No options | ✓ | ✓ |
| `idempotencyKey($key)` | `withIdempotencyKey($key)` | Idempotency key | ✓ | ✓ (chained only) |
| `limitKey($limitKey)` | `withLimitKey($limitKey)` | Limit key | ✓ | ✓ |
| `header($name, $value)` | `withHeader($name, $value)` | Add a header | ✓ | ✓ |
| `headers($headers)` | `withHeaders($headers)` | Replace the headers | ✓ | ✓ |
| `serde($serde)` | `withSerde($serde)` | Payload and result serializer | ✓ | ✓ |
| `inputSerde($serde)` | `withInputSerde($serde)` | Payload serializer | ✓ | ✓ |
| `outputSerde($serde)` | `withOutputSerde($serde)` | Result serializer | ✓ | ✓ |
| `delayMillis($ms)` | `withDelayMillis($ms)` | Delay | | ✓ |

`SendOptions` has no static `idempotencyKey()`: start from another constructor, such as `SendOptions::none()->withIdempotencyKey($key)`. `ClientRequest::sendAfter($delayMillis, $options)` always uses its own delay argument; `send($options)` uses the delay in the options, if any.

## Idempotency keys and headers

An idempotency key makes a request run once. Restate runs the first call or send with a given key for a target, and answers every repeat with the first one's result instead of running the handler again. It keeps the key for a retention period, set per service or handler with `InvocationConfiguration(idempotencyRetentionMillis: …)` ([Service configuration](services.md#service-configuration)).

```php
// Inside a handler: $ctx is its Context; $order is the order being paid.
$ctx->serviceClient('Payments')
    ->request('charge', $order)
    ->idempotencyKey('charge-' . $order['id'])
    ->call();
```

Calls and sends from a handler are already exactly-once: Restate journals them, so a retry does not send them again. Idempotency keys matter when the same logical request can come from several invocations, or from outside Restate. Use them to [attach to the invocation](#invocation-handles) later with `AttachTarget::idempotency()`. An empty key throws an `\InvalidArgumentException`.

Headers travel with the request to the callee, which reads them from `$ctx->request()->headers` (lowercase names) or `$ctx->request()->header($name)` (any case) ([The invocation request](services.md#the-invocation-request)):

```php
// Inside a handler: $ctx is its Context.
$message = $ctx->serviceClient('Greeter')
    ->request('greet', 'Mary')
    ->header('x-trace-id', 'trace-1')
    ->call();
```

Pass several headers as a map (`['x-trace-id' => 'trace-1']`) or as a list of pairs (`[['x-tag', 'a'], ['x-tag', 'b']]`); the list keeps duplicate names and their order. A header name must be a non-empty HTTP token, and a value must not contain CR or LF; anything else throws an `\InvalidArgumentException`.

## Invocation handles

An `InvocationHandle` refers to another invocation. You get one from:

- `send()` and `sendAfter()`, on the invocation they started;
- `$ctx->invocation($invocationId)`, on an existing invocation by id;
- `$ctx->attach($target)`, on an existing invocation by `AttachTarget`:

| `AttachTarget` | Finds |
| --- | --- |
| `AttachTarget::invocationId($invocationId)` | The invocation with this id |
| `AttachTarget::idempotency($target, $idempotencyKey)` | The invocation of `$target` called or sent with this idempotency key |
| `AttachTarget::workflow($workflow, $key, $scope = null)` | The run of a workflow |

```php
<?php

use Restate\AttachTarget;
use Restate\Target;

// Inside a handler: $ctx is its Context; $request is the report request.
$handle = $ctx->send(Target::service('Reports', 'build'), $request);

$handle->peekOutput(); // the output if the invocation finished, null while it runs: does not wait
$handle->output();     // waits for the invocation to finish and returns its output
$handle->cancel();

$receipt = $ctx->attach(AttachTarget::workflow('Checkout', 'order-1'))->output();
```

| Method | What it does |
| --- | --- |
| `output($serde = null)` | Waits for the invocation to finish and returns its output. The wait is durable, like a call. |
| `peekOutput($serde = null)` | Returns the output if the invocation has finished, or `null` while it runs. Does not wait, like `DurablePromise::peek()`. |
| `id()` | The invocation id. |
| `cancel()` | Cancels the invocation. Waiting for its output then throws a `TerminalError` with status code 409. |
| `signal($name)` | A `SignalHandle` to resolve or reject a [signal](external-events.md#signals) of the invocation. |

`output()` and `peekOutput()` throw the invocation's `TerminalError` if it failed. Because `peekOutput()` also returns `null` for an invocation that returned `null`, use `output()` when you need to tell them apart. Both decode with the serializer you pass, or else the handle's (the request's output serializer for a send, the `$serde` argument of `invocation()` and `attach()`, else the context's default).

`id()`, `cancel()` and `signal()` need the invocation id. A handle from `send()` or `sendAfter()` waits until Restate has assigned it, and a handle from `$ctx->invocation()` has it. A handle from `$ctx->attach()` does not know the id, even for `AttachTarget::invocationId()`: there they throw a `\RuntimeException`. Use `$ctx->invocation($id)` when you need to cancel or signal an invocation by id.

## Scopes and limit keys

A **scope** groups invocations, Virtual Object instances and workflow runs, for example per tenant. It is part of the target's identity: an object key, a workflow key and an idempotency key name a different instance in each scope, and another one outside any scope. Restate co-locates everything in one scope, and its concurrency rules apply to scopes.

A **limit key** narrows the concurrency limit within a scope, at one or two levels, such as `user-42` or `eu/user-42`. It is not part of the identity. You configure the rules on the server, for example `restate rules set "tenant-1/*" --concurrency 10`; see Restate's [flow control](https://docs.restate.dev/services/flow-control) docs.

```php
<?php

use Restate\AttachTarget;
use Restate\CallOptions;
use Restate\Target;

// Inside a handler: $ctx is its Context; $order, $cart and $cartId come from the input.

// Within a scope: the clients of $ctx->scope() build scoped targets.
$ctx->scope('tenant-1')->serviceClient('Billing')
    ->request('charge', $order)
    ->limitKey('user-42')
    ->call();

// The same with a target: the scope on the target, the limit key on the call or send.
$ctx->send(Target::object('Cart', $cartId, 'checkout')->scoped('tenant-1'), $cart, limitKey: 'eu/user-42');
$ctx->genericCall(Target::service('Billing', 'charge')->scoped('tenant-1'), $order, CallOptions::limitKey('user-42'));

// Attach to a scoped workflow run or idempotent request.
$ctx->attach(AttachTarget::workflow('Checkout', 'order-1', 'tenant-1'))->output();
$ctx->attach(AttachTarget::idempotency(Target::service('Billing', 'charge')->scoped('tenant-1'), 'charge-1'))->output();
```

The callee reads them from `$ctx->request()->scope` and `$ctx->request()->limitKey`.

The SDK checks both before the call or send is journaled, and throws an `\InvalidArgumentException` when:

- a scope is not 1 to 36 characters from `[a-zA-Z0-9_.-]`;
- a limit key is not one or two such levels separated by `/`;
- a limit key is given for a target without a scope.

> [!NOTE]
> Needs Restate 1.8 or newer. On 1.7 a call or send with a scope or limit key fails the attempt, and Restate retries it ([Compatibility](../compatibility.md#restate-17)).

## Design notes

Background for contributors:

- [Call And Send Option Headers](../decisions/0009-call-send-option-headers.md)
- [Named Signals](../decisions/0019-named-signals.md)
- [Scope and Limit Key](../decisions/0024-scope-and-limit-key.md)
