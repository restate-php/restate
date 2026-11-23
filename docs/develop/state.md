# State

Virtual Objects and Workflows can store key-value state in Restate. This page shows how to read and write it, how handler types control access to it, and how to choose which state Restate sends with each invocation.

A Virtual Object is a set of handlers addressed by a key, such as a user id or a cart id. Each key has its own state and its own queue of invocations. If the concept is new to you, read [Key concepts](https://docs.restate.dev/foundations/key-concepts) and [Services](https://docs.restate.dev/foundations/services) first. Basic Services have no state.

## Reading and writing state

State is a map from string keys to values, separate for every object key. Exclusive Virtual Object handlers and the Workflow `run` handler receive an `ObjectContext` (or `WorkflowContext`), which can read and write:

```php
use Restate\Endpoint;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\VirtualObject;

$cart = VirtualObject::new('Cart')
    ->handler('add', static function (ObjectContext $ctx, string $item): array {
        $items = $ctx->get('items') ?? [];
        $items[] = $item;
        $ctx->set('items', $items);

        return $items;
    })
    ->handler('checkout', static function (ObjectContext $ctx): array {
        $items = $ctx->get('items') ?? [];
        $ctx->clearAll();

        return $items;
    })
    ->sharedHandler('items', static fn (ObjectSharedContext $ctx): array => $ctx->get('items') ?? []);

Endpoint::serve([$cart]);
```

Calling `Cart/alice/add` and `Cart/bob/add` changes two independent carts: `$ctx->key()` is `alice` in one and `bob` in the other, and neither sees the other's state.

| Method | Context | What it does |
| --- | --- | --- |
| `$ctx->get(string $key, $serde = null)` | shared and exclusive | Returns the stored value, or `null` when the key has no value. |
| `$ctx->stateKeys()` | shared and exclusive | Returns the keys that have a value, as a list of strings. |
| `$ctx->key()` | shared and exclusive | Returns the object or workflow key of this invocation. |
| `$ctx->set(string $key, mixed $value, $serde = null)` | exclusive only | Stores a value under the key, replacing any previous one. |
| `$ctx->clear(string $key)` | exclusive only | Removes one key. |
| `$ctx->clearAll()` | exclusive only | Removes every key of this object. |

`get()` returns `null` both for a missing key and for a stored `null`. When the difference matters, check `stateKeys()`: a key set to `null` is listed, a missing one is not.

State changes are journaled like every other operation, so a retried attempt replays them instead of applying them twice. `set()`, `clear()` and `clearAll()` do not end the attempt: Restate receives them with the rest of the attempt's journal.

Values are JSON by default: anything `json_encode()` can encode. Objects come back from `get()` as associative arrays, like any JSON value the SDK decodes ([JSON by default](serialization.md#json-by-default)). To store your own classes, use a [custom serializer](#custom-serializers-for-state).

## Shared and exclusive handlers

Restate runs at most one exclusive handler per object key at a time. Other invocations of exclusive handlers for that key wait in a queue. This is what makes the read-modify-write in `Cart/add` above safe without locks: two `add` calls for `alice` never interleave.

Shared handlers run concurrently, with each other and with the exclusive handler. Their context, `ObjectSharedContext`, can read state but has no `set()`, `clear()` or `clearAll()`. Use them for queries that must not wait behind a long exclusive handler.

You choose the handler type when you define the service, and the context type follows from it:

| Definition | Handler type | Context |
| --- | --- | --- |
| `VirtualObject::handler()` or `#[Handler]` | exclusive | `ObjectContext` |
| `VirtualObject::sharedHandler()` or `#[Handler]` with `#[Shared]` | shared | `ObjectSharedContext` |
| `Workflow::run()` or the `#[Handler]` named `run` | workflow | `WorkflowContext` |
| `Workflow::sharedHandler()` or `#[Handler]` with `#[Shared]` | shared | `WorkflowSharedContext` |

A handler may declare the context it receives or one it extends, such as `Context`. Declaring a more capable one fails when the service is defined: a shared handler that declares `ObjectContext` throws `InvalidArgumentException` ("… handler declares context Restate\ObjectContext, but receives a Restate\ObjectSharedContext"). [Handler contexts](services.md#handler-contexts) lists every context.

The same `Cart` with attributes:

```php
use Restate\Attributes\Handler;
use Restate\Attributes\Shared;
use Restate\Attributes\VirtualObject;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;

#[VirtualObject('Cart')]
final class Cart
{
    #[Handler]
    public function add(ObjectContext $ctx, string $item): array
    {
        $items = $ctx->get('items') ?? [];
        $items[] = $item;
        $ctx->set('items', $items);

        return $items;
    }

    #[Handler, Shared]
    public function items(ObjectSharedContext $ctx): array
    {
        return $ctx->get('items') ?? [];
    }
}
```

## Workflow state

A Workflow has state too, one map per workflow key (per workflow execution). The `run` handler gets a `WorkflowContext` and can read and write it. Shared workflow handlers get a `WorkflowSharedContext` and can only read it, so they are the place for status queries while `run` is in progress:

```php
use Restate\Workflow;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

$signup = Workflow::new('Signup')
    ->run(static function (WorkflowContext $ctx, string $email): string {
        $ctx->set('status', 'creating-account');
        // ... create the account, send the confirmation mail ...
        $ctx->set('status', 'done');

        return 'ok';
    })
    ->sharedHandler('status', static fn (WorkflowSharedContext $ctx): ?string => $ctx->get('status'));
```

Restate keeps a finished workflow's state for its completion retention period, so shared handlers can still read it after `run` returns. Set the period with `InvocationConfiguration(workflowCompletionRetentionMillis: …)` ([Service configuration](services.md#service-configuration)). Workflows can also exchange values through durable promises ([Workflow durable promises](external-events.md#workflow-durable-promises)).

## Eager and lazy state

By default Restate sends a Virtual Object's or Workflow's whole state along with each invocation ("eager state"), so `get()` and `stateKeys()` cost nothing. For large state you can choose what is sent with `InvocationConfiguration(statePreload: …)`, on the service or on one handler. The handler's setting wins:

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

| `statePreload` | What Restate sends |
| --- | --- |
| `null` (not set) or `true` | All state. This is Restate's default. |
| `false` or `[]` | Nothing ("lazy state"). Every read asks Restate. |
| A list of keys, such as `['plan']` | Only those keys, best-effort within the server's eager state size limit. Any other key, and a listed key that did not fit or does not exist, is fetched on read. |

A read that is not preloaded is a round trip to Restate. In request/response mode that ends the attempt: the invocation suspends, and the next attempt replays up to the read and gets the value. Lazy state saves transferring data you do not read, at the cost of one attempt per read that is not preloaded, so keep it for state that is large and rarely needed.

The list must contain strings only; anything else throws `InvalidArgumentException`. Basic Services have no state, so defining one with `statePreload` throws `InvalidArgumentException` too.

> [!NOTE]
> A list of keys needs Restate 1.8 or newer. On 1.7 registering the deployment fails with a message saying so; `true`, `false` and `[]` work on 1.7 too ([Compatibility](../compatibility.md#restate-17)).

## Custom serializers for state

`get()` and `set()` take an optional serializer: a `Restate\Serde` instance or class name. Use the same one for reading and writing a key:

```php
// ProfileSerde is your own Restate\Serde implementation; $profile is a Profile object.
$ctx->set('profile', $profile, serde: ProfileSerde::class);
$profile = $ctx->get('profile', serde: ProfileSerde::class);
```

Without one, state uses the service's or the endpoint's default serializer, and JSON when neither is set. Changing the serializer of a key that already holds data means the stored bytes no longer decode, so treat it like a data migration. [Custom serializers](serialization.md#custom-serializers) explains how to write one, and [Precedence](serialization.md#precedence) which serializer applies when several are configured.

## Design notes

- [Selective state preloading](../decisions/0020-selective-state-preloading.md)
- [Operation-level custom serialization](../decisions/0008-operation-level-custom-serialization.md)
