# Services

This page shows how to write Restate services in PHP: the three service types, the two ways to define them (attributes and builders), what a handler may look like, the context it receives, and how to put services on an endpoint.

## Service types

A Restate application is made of services, and each service has handlers that Restate invokes. Restate has three service types ([Services](https://docs.restate.dev/foundations/services) explains them in depth):

| Type | Use it for | Handlers | State |
| --- | --- | --- | --- |
| Basic Service | Stateless work: calling APIs, orchestrating other services, sagas | Any number; invocations run in parallel | None |
| Virtual Object | An entity addressed by a key, such as a user, a cart or a counter | Exclusive handlers (one at a time per key) and shared handlers (concurrent, read-only) | Key-value state per key |
| Workflow | A process that runs exactly once per key, such as a signup or an order | One `run` handler, executed once per key, plus shared handlers to query or signal it | Key-value state per key, durable promises |

Pick a Basic Service unless you need state or one-at-a-time access per key (Virtual Object), or a run-once process that other handlers interact with while it runs (Workflow).

In PHP each type has an attribute (`#[Service]`, `#[VirtualObject]`, `#[Workflow]` in `Restate\Attributes`) and a builder (`Restate\Service`, `Restate\VirtualObject`, `Restate\Workflow`). Both produce the same `Restate\ServiceDefinition`, and an endpoint accepts either style.

## Defining services with attributes

Mark a class with exactly one service type attribute, and each handler method with `#[Handler]`. Only public methods with `#[Handler]` become handlers. The handler name defaults to the method name; pass a name to override it.

A Basic Service:

```php
<?php

use Restate\Attributes as Restate;
use Restate\Context;

#[Restate\Service('Greeter')]
final class Greeter
{
    #[Restate\Handler]
    public function greet(Context $ctx, string $name): string
    {
        return "Hello {$name}";
    }
}
```

A Virtual Object. Handlers are exclusive by default; add `#[Shared]` for a shared handler:

```php
<?php

use Restate\Attributes as Restate;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;

#[Restate\VirtualObject('Counter')]
final class Counter
{
    #[Restate\Handler]
    public function add(ObjectContext $ctx, int $amount): int
    {
        $value = $ctx->get('value'); // decoded JSON, or null for a key without a value
        $value = (is_int($value) ? $value : 0) + $amount;
        $ctx->set('value', $value);

        return $value;
    }

    #[Restate\Handler]
    #[Restate\Shared]
    public function get(ObjectSharedContext $ctx): int
    {
        $value = $ctx->get('value');

        return is_int($value) ? $value : 0;
    }
}
```

A Workflow. The handler named `run` is the workflow itself; every other handler must be `#[Shared]`:

```php
<?php

use Restate\Attributes as Restate;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

#[Restate\Workflow('Signup')]
final class Signup
{
    #[Restate\Handler]
    public function run(WorkflowContext $ctx, string $email): bool
    {
        $ctx->set('status', 'waiting for verification');
        $verified = $ctx->promise('email-verified')->value();
        $ctx->set('status', 'done');

        return $verified === true;
    }

    #[Restate\Handler]
    #[Restate\Shared]
    public function verify(WorkflowSharedContext $ctx, bool $verified): void
    {
        $ctx->promise('email-verified')->resolve($verified);
    }

    #[Restate\Handler]
    #[Restate\Shared]
    public function status(WorkflowSharedContext $ctx): ?string
    {
        return $ctx->get('status');
    }
}
```

`#[Shared]` has no effect on a Basic Service handler. A class with no service type attribute, or with more than one, is rejected, as is a method with two `#[Handler]` attributes.

To get the `ServiceDefinition` of an attributed object yourself (for example to add interceptors, see [Service configuration](#service-configuration)), call `Restate\Reflection\ServiceReflector::fromObject(new Greeter())`. You do not need it to serve the object: endpoints reflect attributed objects for you. [`examples/attribute-greeter.php`](../../examples/attribute-greeter.php) is a complete endpoint in this style, [`examples/greeter.php`](../../examples/greeter.php) one with a builder.

## Defining services with builders

Builders define the same services with closures, without a class. Each builder is immutable: every method returns a new builder.

```php
<?php

use Restate\Context;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Service;
use Restate\VirtualObject;
use Restate\Workflow;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

$greeter = Service::new('Greeter')
    ->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}");

$counter = VirtualObject::new('Counter')
    ->handler('add', static function (ObjectContext $ctx, int $amount): int {
        $value = $ctx->get('value'); // decoded JSON, or null for a key without a value
        $value = (is_int($value) ? $value : 0) + $amount;
        $ctx->set('value', $value);

        return $value;
    })
    ->sharedHandler('get', static function (ObjectSharedContext $ctx): int {
        $value = $ctx->get('value');

        return is_int($value) ? $value : 0;
    });

$signup = Workflow::new('Signup')
    ->run(static function (WorkflowContext $ctx, string $email): bool {
        $ctx->set('status', 'waiting for verification');
        $verified = $ctx->promise('email-verified')->value();
        $ctx->set('status', 'done');

        return $verified === true;
    })
    ->sharedHandler('verify', static function (WorkflowSharedContext $ctx, bool $verified): void {
        $ctx->promise('email-verified')->resolve($verified);
    })
    ->sharedHandler('status', static fn (WorkflowSharedContext $ctx): ?string => $ctx->get('status'));
```

| Builder | Handler methods |
| --- | --- |
| `Service::new($name)` | `handler($name, $callback)` |
| `VirtualObject::new($name)` | `handler($name, $callback)` (exclusive), `sharedHandler($name, $callback)` |
| `Workflow::new($name)` | `run($callback)`, `sharedHandler($name, $callback)` |

Every handler method also takes `$input` and `$output` (a `Restate\Payload`, see [Content types](serialization.md#content-types)), `$documentation`, `$metadata`, `$invocationRetryPolicy` and `$invocationConfiguration` as named arguments. `->definition()` returns the `ServiceDefinition`; endpoints accept the builder itself, so you rarely need it.

Names are checked when you define them:

- Service names start with a letter (or `_` and a letter or digit) and may contain letters, digits, `_`, `.` and `-`. Handler names are the same without `.` and `-`.
- A handler name may appear once per service.
- A Workflow must have a `run` handler, and may not use the names `workflowSubmit`, `workflowAttach` or `workflowOutput`, which Restate reserves.

Invalid definitions throw `\InvalidArgumentException` when you define the service (for an attributed class, when it is reflected, at the latest in the endpoint's `build()`), not when Restate first invokes it.

## Handler signatures

A handler is a method or closure with:

1. **A context as its first parameter**, typed as one of the context interfaces (see [Handler contexts](#handler-contexts)).
2. **At most one input parameter.** Restate passes the request body, decoded from JSON into the declared type. Without an input parameter, the handler takes no input. To accept several values, take an array or a DTO ([DTOs and enums](serialization.md#dtos-and-enums)).
3. **An optional return value**, the handler's output, encoded to JSON. A `void` or `never` return type means no output.

| Signature | Input | Output |
| --- | --- | --- |
| `greet(Context $ctx, Person $person): Greeting` | JSON mapped to the `Person` DTO | the `Greeting` DTO as JSON |
| `ping(Context $ctx): void` | none | none |
| `echo(Context $ctx, mixed $value): mixed` | any JSON value | any JSON value |

The declared input and output types also produce the JSON Schema Restate shows for the handler ([JSON Schema for discovery](serialization.md#json-schema-for-discovery)). A handler without a context parameter, or with two input parameters, is rejected when you define it.

## Handler contexts

Every handler takes a context as its first parameter. The contexts are interfaces; the SDK creates the context for each attempt, and there is no implementation for you to construct. Each kind of handler receives a different one:

| Handler | Context | Adds |
| --- | --- | --- |
| Basic Service handler | `Context` | calls, sends, `run`, sleeps, awakeables, signals, deterministic random values |
| Virtual Object shared handler | `ObjectSharedContext` (extends `Context`) | `key()`, `get()`, `stateKeys()` |
| Virtual Object exclusive handler | `ObjectContext` (extends `ObjectSharedContext`) | `set()`, `clear()`, `clearAll()` |
| Workflow shared handler | `WorkflowSharedContext` (extends `ObjectSharedContext`) | `promise()`, `promiseFuture()` |
| Workflow `run` handler | `WorkflowContext` (extends `WorkflowSharedContext` and `ObjectContext`) | |

A handler may declare any context that its own context extends, for example `Context` for an exclusive handler that only calls other services. A handler that declares a context with more capabilities than its kind receives, such as `ObjectContext` on a shared handler, is rejected when the service is defined, instead of failing every invocation with a `TypeError`.

What a context hands out is an interface too: `DurableFuture`, `Awakeable`, `DurablePromise`, `InvocationHandle` and `SignalHandle`. The future combinators are context methods (`$ctx->all()`, `$ctx->race()`, `$ctx->any()`, `$ctx->allSettled()`, `$ctx->waitCompleted()`, `$ctx->select()`, see [Durable Futures](concurrency.md)), so a test double of a context controls them as well.

Everything under the `Restate\Internal` namespace is internal and may change in any release. Use only the public classes and interfaces.

## The invocation request

`$ctx->request()` returns the request that started the invocation, as a read-only `Restate\Request`:

```php
$request = $ctx->request();

$request->id;              // invocation id ("inv_…"), the same across retries and suspensions
$request->target;          // Restate\Target: serviceName(), handlerName(), key()
$request->headers;         // the caller's headers, lowercase names; $request->header('X-Tenant') ignores case
$request->attemptHeaders;  // HTTP headers of this attempt's request from Restate; they change between attempts
$request->body;            // raw input bytes, before the input serializer and any journal value codec
$request->idempotencyKey;  // the idempotency key the invocation was submitted with, or null
$request->scope;           // the scope it was submitted with, or null
$request->limitKey;        // the limit key it was submitted with, or null
```

Everything except `attemptHeaders` is the same on every attempt. Because the attempt headers change, do not base handler logic on them; they are meant for things like tracing ([Tracing](logging-and-hooks.md#tracing)).

## Documentation and metadata

Services and handlers can carry documentation and metadata. Restate shows both in its UI and admin API after you register the deployment, and generates OpenAPI from them; the SDK itself only publishes them during discovery.

- `documentation` is free text.
- `metadata` is a map of string keys to string values. Anything else throws `\InvalidArgumentException`.

```php
<?php

use Restate\Attributes as Restate;
use Restate\Context;

#[Restate\Service('Greeter', documentation: 'Greeter service.', metadata: ['domain' => 'examples'])]
final class Greeter
{
    #[Restate\Handler('greet', documentation: 'Greets a person.', metadata: ['owner' => 'sdk'])]
    public function greet(Context $ctx, string $name): string
    {
        return "Hello {$name}";
    }
}
```

The builders take the same named arguments:

```php
<?php

use Restate\Context;
use Restate\Service;

$greeter = Service::new('Greeter', documentation: 'Greeter service.', metadata: ['domain' => 'examples'])
    ->handler(
        'greet',
        static fn (Context $ctx, string $name): string => "Hello {$name}",
        documentation: 'Greets a person.',
        metadata: ['owner' => 'sdk'],
    );
```

Handler input and output schemas and content types can be set explicitly with `#[Input]` and `#[Output]` (or `Payload` arguments on the builders); when you don't, the SDK infers JSON Schema from the parameter and return types. See [JSON Schema for discovery](serialization.md#json-schema-for-discovery) and [Content types](serialization.md#content-types).

## Service configuration

Restate reads per-service and per-handler settings from the endpoint during discovery: an invocation retry policy and invocation options. Set them on the service type attribute or `::new()`, and on `#[Handler]` or the handler method. A handler's setting wins over its service's.

```php
<?php

use Restate\Context;
use Restate\InvocationConfiguration;
use Restate\InvocationRetryPolicy;
use Restate\Service;

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

With attributes: `#[Restate\Service('Greeter', invocationRetryPolicy: new InvocationRetryPolicy(maxAttempts: 5))]`, and the same on `#[Handler]`. The builders also have `->invocationConfiguration($configuration)` to set it on the service after `::new()`.

### `InvocationRetryPolicy`

How Restate retries a failed handler attempt. Every option is optional; an unset one keeps Restate's default. This is not the retry policy of `$ctx->run()` (`RunRetryPolicy`, see [Retry policies](errors.md#retry-policies)).

| Parameter | Type | Meaning |
| --- | --- | --- |
| `initialIntervalMillis` | `?int` | Delay before the first retry. At least 0. |
| `exponentiationFactor` | `?float` | Factor the delay grows by after each retry. Finite, at least 1.0. |
| `maxIntervalMillis` | `?int` | Upper bound for the delay. At least 0. |
| `maxAttempts` | `?int` | Attempts before Restate gives up. At least 0. |
| `onMaxAttempts` | `?string` | What Restate does when it gives up: `InvocationRetryPolicy::ON_MAX_ATTEMPTS_PAUSE` (pause the invocation until someone resumes it, see [Pausing invocations](errors.md#pausing-invocations)) or `InvocationRetryPolicy::ON_MAX_ATTEMPTS_KILL` (kill it). |

### `InvocationConfiguration`

| Parameter | Type | Meaning | Where it is valid |
| --- | --- | --- | --- |
| `inactivityTimeoutMillis` | `?int` | How long an attempt may make no progress before Restate asks it to suspend | Anywhere |
| `abortTimeoutMillis` | `?int` | How long Restate then waits before it aborts the attempt and retries | Anywhere |
| `journalRetentionMillis` | `?int` | How long Restate keeps the journal of a completed invocation | Anywhere |
| `idempotencyRetentionMillis` | `?int` | How long Restate remembers the result of an invocation sent with an idempotency key | Not on a Workflow `run` handler |
| `workflowCompletionRetentionMillis` | `?int` | How long Restate keeps a completed workflow's result and state | Only on a Workflow `run` handler (not on the service) |
| `statePreload` | `bool\|list<string>\|null` | Which state Restate sends with each invocation: `true` all, `false` none, a list only those keys. See [Eager and lazy state](state.md#eager-and-lazy-state) | Virtual Objects and Workflows only |
| `ingressPrivate` | `?bool` | `true` hides the service or handler from the ingress, so only other handlers can call it | Anywhere |

Durations must be 0 or more. `null` keeps Restate's default. A `statePreload` on a Basic Service throws `\InvalidArgumentException` when you define the service. Misplaced `workflowCompletionRetentionMillis` or `idempotencyRetentionMillis` are only detected at discovery, where they fail the deployment registration with a message naming the handler.

### Interceptors, error mappers and serializers

The builders have three more service-level settings:

| Method | Purpose | See |
| --- | --- | --- |
| `->interceptor(...$interceptors)` | Code that runs around this service's handler attempts and `$ctx->run()` callbacks | [Interceptors](logging-and-hooks.md#interceptors) |
| `->errorMapper($mapper)` | Turns domain exceptions into terminal, retryable or pause errors | [Mapping exceptions with an error mapper](errors.md#mapping-exceptions-with-an-error-mapper) |
| `->defaultSerde($serde)` | The serializer for this service's payloads, state and journal values | [Custom serializers](serialization.md#custom-serializers) |

Attributes have no equivalent for interceptors and error mappers. Reflect the class and add them to the definition: `ServiceReflector::fromObject($greeter)->withInterceptors(...)` and `->withErrorMapper($mapper)`.

## Service instances and dependencies

`Endpoint::serve([new Greeter($repository)])` binds the handlers to one object for the endpoint's lifetime. Under `php -S` or PHP-FPM that is one request. Under a long-lived worker such as FrankenPHP worker mode it is many, so dependencies like database connections or tenant-scoped clients would leak from one invocation to the next.

Bind the class instead, with a factory or a PSR-11 container. The endpoint then creates a new instance for every attempt:

```php
<?php

use Restate\Endpoint;
use Restate\Request;

// $clients: your tenant-aware client registry; $container: any PSR-11 container
$endpoint = Endpoint::builder()
    // A factory gets the invocation's request (target, headers, id).
    ->bind(Greeter::class, static fn (Request $request): Greeter => new Greeter($clients->forTenant($request->header('x-tenant'))))
    // Classes bound without a factory come from the container: get(Checkout::class) per attempt.
    ->container($container)
    ->bind(Checkout::class)
    ->services([Payments::class, new Counter()])
    ->build();
```

- **One instance per attempt, not per invocation.** Each attempt is one HTTP request from Restate, so a retried or resumed invocation gets a fresh instance. The instance is created right before the handler runs, inside the [interceptors](logging-and-hooks.md#interceptors).
- **Failures.** A factory or container that throws fails the attempt like a handler exception, and goes through the error mapper.
- **The container decides.** Whether `get()` returns a new object each time is the container's configuration (a non-shared service, or a container reset between requests).
- **No instance for discovery.** Building the endpoint and answering discovery never instantiate the class: names, handler kinds, payloads and schemas come from its attributes and method signatures.
- **Checked at build time.** `build()` rejects a class bound without a factory when no container is configured, or one the container does not have.

`ServiceReflector::fromClass(Greeter::class, $factoryOrContainer)` returns the `ServiceDefinition` directly, as `fromObject()` does for an instance.

## Putting services on an endpoint

An endpoint is the HTTP entry point Restate sends invocations to. It accepts builders, `ServiceDefinition`s, attributed objects and (through the builder) attributed classes.

For a plain PHP entrypoint (`php -S`, PHP-FPM, FrankenPHP without worker mode), `Endpoint::serve()` builds the endpoint and answers the current request:

```php
<?php

use Restate\Endpoint;

require __DIR__ . '/vendor/autoload.php';

Endpoint::serve([new Greeter(), new Counter(), new Signup()]);
```

For more options, use the builder. `Endpoint::builder()` is immutable like the service builders; `build()` returns the endpoint:

```php
<?php

use Restate\Endpoint;

require __DIR__ . '/vendor/autoload.php';

// $counter and $signup: the builders from "Defining services with builders"; $logger: any PSR-3 logger
$endpoint = Endpoint::builder()
    ->services([new Greeter(), $counter])
    ->bind($signup)
    ->logger($logger)
    ->build();

$endpoint->handle($_SERVER, (string) file_get_contents('php://input'));
```

| Builder method | Purpose |
| --- | --- |
| `bind($service, $factory = null)` | Adds one service; a class name may get a factory |
| `services($services)` | Adds several services |
| `container($container)` | PSR-11 container for classes bound without a factory |
| `identityKeys($keys)` | Accept only requests signed by your Restate server ([Request identity validation](../serving.md#request-identity-validation)) |
| `logger($logger, $coreLogLevel)` | PSR-3 logger ([Logging](logging-and-hooks.md#logging)) |
| `interceptor(...$interceptors)` | Interceptors for every service ([Interceptors](logging-and-hooks.md#interceptors)) |
| `errorMapper($mapper)` | Error mapper for every service ([Error mapper](errors.md#mapping-exceptions-with-an-error-mapper)) |
| `defaultSerde($serde)`, `journalValueCodec($codec)`, `jsonPayloads($options)` | Serialization ([Serialization](serialization.md)) |

`build()` throws `\InvalidArgumentException` for an invalid configuration and `Restate\BackendUnavailableException` when the Restate PHP extension is not loaded or does not match the SDK.

For frameworks that speak PSR-7, `Endpoint::psr($services, $responseFactory)` returns a PSR-15 request handler. `Endpoint::serve()` and `Endpoint::psr()` also take `identityKeys:` and `logger:` named arguments.

The [serving guide](../serving.md) covers the rest: PHP-FPM, FrankenPHP worker mode, PSR-15, and the PHP settings an endpoint needs.

## Design notes

- [Public API Boundary](../decisions/0016-public-api-boundary.md)
- [Handler Instantiation](../decisions/0018-handler-instantiation.md)
