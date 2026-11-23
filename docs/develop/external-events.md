# Awakeables, signals and durable promises

Sometimes a handler must wait for something that happens after its invocation started: a person approves a request, a payment provider calls a webhook, another handler finishes a step. Restate offers three durable primitives for this. This page shows each one, and how to pick between them.

All three are durable: a waiting handler survives retries, restarts and redeployments, and Restate can [suspend](https://docs.restate.dev/foundations/key-concepts) it while it waits and resume it when the value arrives.

## Awakeables

An awakeable is a one-shot callback with a unique id. The handler creates it, hands the id to another system, and waits. The other system completes it through Restate, once, with a value or a failure.

```php
<?php

use Restate\Attributes as Restate;
use Restate\Context;

#[Restate\Service('Reviews')]
final class Reviews
{
    #[Restate\Handler]
    public function review(Context $ctx, string $documentId): string
    {
        $awakeable = $ctx->awakeable();

        // Hand the id to the reviewer, in a durable step so it happens once.
        $ctx->run('request review', static fn () => self::requestHumanReview($documentId, $awakeable->id()));

        // Waits (and suspends) until the reviewer completes the awakeable.
        return $awakeable->value();
    }

    private static function requestHumanReview(string $documentId, string $awakeableId): void
    {
        // Email the reviewer a link that carries $awakeableId, put it on a queue, …
    }
}
```

`$ctx->awakeable()` returns a `Restate\Awakeable` with two methods:

| Method | What it does |
| --- | --- |
| `id()` | The id to complete the awakeable with |
| `value($serde = null)` | Waits for the awakeable and returns its value; throws the `TerminalError` of a rejected awakeable |

To combine the wait with a timeout or with other futures, use `$ctx->awakeableFuture()` instead. It returns the id and a `DurableFuture` ([Durable Futures](concurrency.md)):

```php
<?php

use Restate\TerminalError;

// Inside a handler: $ctx is its Context; $approvals is your client for the approval system.
['id' => $id, 'future' => $approval] = $ctx->awakeableFuture();
$ctx->run('request approval', static fn () => $approvals->request($id));

if ($ctx->select(['approval' => $approval, 'timeout' => $ctx->sleepFuture(86_400_000)]) === 'timeout') {
    throw new TerminalError('No approval within a day', 408);
}
$decision = $approval->awaitResult();
```

Values are JSON by default. Pass a serializer to `awakeable()` or `awakeableFuture()` (or to `value()` for one read) when the completer sends another format ([Serialization](serialization.md#custom-serializers)).

> [!NOTE]
> A Virtual Object's exclusive handler that waits for an awakeable holds the object's key: other calls to the same key queue until it finishes.

### Completing an awakeable

Resolve an awakeable with a value, or reject it with a failure. The waiting handler gets the value, or throws a `TerminalError`. Each awakeable completes once.

From another handler, use the context:

```php
<?php

use Restate\TerminalError;

// Inside a handler: $ctx is its Context; $id is the awakeable id.
$ctx->resolveAwakeable($id, ['approved' => true]);
$ctx->rejectAwakeable($id, new TerminalError('Denied by policy', 403));
```

`resolveAwakeable()` takes an optional serializer as its third argument; it must match what the waiting handler decodes with. `rejectAwakeable()` takes a `TerminalError`, whose status code, message and metadata the waiting handler receives.

From PHP code outside a handler, use the [ingress client](../ingress-client.md#awakeables):

```php
// $restate is a Restate\Ingress\IngressClient.
$restate->resolveAwakeable($id, ['approved' => true]);
$restate->rejectAwakeable($id, 'Denied by policy');
```

From any other system, call Restate's ingress over HTTP. `resolve` takes the value as the request body (JSON, unless the handler decodes another format). `reject` takes a plain-text reason: the waiting handler throws a `TerminalError` with status code 500 and the reason as its message, as it does for `rejectAwakeable()` from the ingress client:

```bash
curl localhost:8080/restate/awakeables/<id>/resolve --json '{"approved": true}'

curl localhost:8080/restate/awakeables/<id>/reject \
    -H 'content-type: text/plain' -d 'Denied by policy'
```

## Signals

A signal is a named message to one invocation, sent by its invocation id. Any handler can wait for one: `$ctx->signal($name)` is a `DurableFuture` of the next delivery, so it combines with timers, calls and awakeables. Another handler resolves or rejects it through the target's [invocation handle](communication.md#invocation-handles):

```php
<?php

use Restate\TerminalError;

// Inside a handler: $ctx is its Context.
// The waiting handler: tell the approver $ctx->request()->id, then wait up to an hour.
$approval = $ctx->signal('approval');
if ($ctx->select(['approval' => $approval, 'timeout' => $ctx->sleepFuture(3_600_000)]) === 'timeout') {
    throw new TerminalError('No approval within an hour', 408);
}
$decision = $approval->awaitResult(); // a rejected signal throws its TerminalError

// The approver, in another handler; $invocationId is the waiting invocation's id.
$ctx->invocation($invocationId)->signal('approval')->resolve(['approvedBy' => 'alice']);
$ctx->invocation($invocationId)->signal('approval')->reject(new TerminalError('Denied', 403));
```

Signals need no registration. A delivery that arrives before the handler waits is kept, not lost. Each delivery is received once, in order: sending the same signal twice delivers it twice, and each `$ctx->signal($name)` takes the next one. Sending a signal is journaled and does not wait for the target.

The waiting handler gets its own id from `$ctx->request()->id` and must pass it to whoever will signal it. A handle from `send()` can be signalled too: it waits for the invocation id first. A handle from `$ctx->attach()` has no id, so signalling through it throws a `\RuntimeException`.

Values use the context's default serializer, unless you pass one to `$ctx->signal($name, $serde)` or to `SignalHandle::resolve($value, $serde)`.

Only handlers send signals. The ingress has no route for them: from outside, call a handler that sends the signal.

## Workflow durable promises

A durable promise belongs to one workflow run and has a name. Any handler of the workflow can wait for it, read it or complete it, and it completes once. Typically the `run` handler waits, and a shared handler, called from outside, completes it:

```php
<?php

use Restate\Attributes as Restate;
use Restate\TerminalError;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

#[Restate\Workflow('ReviewFlow')]
final class ReviewFlow
{
    #[Restate\Handler]
    public function run(WorkflowContext $ctx, string $documentId): string
    {
        $key = $ctx->key();
        $ctx->run('request review', static fn () => self::askForReview($documentId, $key));

        // Waits (and suspends) until a shared handler completes the promise.
        $review = $ctx->promise('review')->value();

        return "Document {$documentId}: {$review['verdict']}";
    }

    #[Restate\Handler]
    #[Restate\Shared]
    public function submit(WorkflowSharedContext $ctx, array $review): void
    {
        $ctx->promise('review')->resolve($review);
    }

    #[Restate\Handler]
    #[Restate\Shared]
    public function deny(WorkflowSharedContext $ctx, string $reason): void
    {
        $ctx->promise('review')->reject(new TerminalError($reason, 409, ['reason' => 'denied']));
    }

    #[Restate\Handler]
    #[Restate\Shared]
    public function status(WorkflowSharedContext $ctx): string
    {
        return $ctx->promise('review')->peek() === null ? 'waiting' : 'reviewed';
    }

    private static function askForReview(string $documentId, string $workflowKey): void
    {
        // Ask a reviewer to call ReviewFlow/{$workflowKey}/submit or /deny.
    }
}
```

`$ctx->promise($name)` returns a `Restate\DurablePromise`. Both workflow contexts have it: `WorkflowContext` in `run`, `WorkflowSharedContext` in shared handlers.

| Method | What it does |
| --- | --- |
| `value($serde = null)` | Waits for the promise and returns its value |
| `peek($serde = null)` | Returns the value, or `null` while the promise is not completed; does not wait |
| `future($serde = null)` | A `DurableFuture` of the value, to combine with other futures |
| `resolve($value, $serde = null)` | Completes the promise with a value |
| `reject(TerminalError $failure)` | Completes the promise with a terminal failure |

`resolve()` takes any JSON-serializable value by default; pass a serializer when promise values need a custom byte format. A waiting `value()` or `future()->awaitResult()`, and `peek()`, observe a rejected promise as a `TerminalError` with the original status code, message and metadata. Completing a promise that is already completed throws a `TerminalError`.

`$ctx->promiseFuture($name)` is a shortcut for `$ctx->promise($name)->future()`. Like `peek()` on a handle's output, `peek()` returns `null` both for a pending promise and for one resolved with `null`.

Restate keeps a workflow's promises, like its state, for a retention period after `run` finishes, so shared handlers can still read them. Set it with `InvocationConfiguration(workflowCompletionRetentionMillis: …)` ([Service configuration](services.md#service-configuration)).

## Choosing a primitive

| | Awakeable | Signal | Durable promise |
| --- | --- | --- | --- |
| Addressed by | A generated id | An invocation id and a name | A workflow key and a name |
| Completed by | Any handler, the ingress client, or any system over HTTP | Another handler | Any handler of the same workflow run |
| Completes | Once | Any number of times; each wait takes the next delivery | Once; every handler of the run can read the result |
| Waited for in | Any service type | Any service type | Workflow handlers |

- Use an **awakeable** when an external system needs a one-shot callback token that it completes through Restate's HTTP API.
- Use a **signal** when another handler needs to send one or more messages to an invocation that is already running, without setting up an id first.
- Use a **durable promise** when the value belongs to a workflow run and its handlers should be able to read it, during and after the run.

For long waits, combine any of them with a durable timer to time out ([Timeouts](timers.md#timeouts)).

## Design notes

Background for contributors:

- [Named Signals](../decisions/0019-named-signals.md)
