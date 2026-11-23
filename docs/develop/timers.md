# Timers and scheduling

Restate keeps timers for you, so a handler can wait for minutes or months without a process staying alive. This page covers durable sleep, delayed messages, timeouts, and recurring work.

## Durable sleep

`$ctx->sleep()` pauses the handler for a number of milliseconds:

```php
use Restate\Context;
use Restate\Service;

// $notifier is your notification client, created elsewhere.
$reminders = Service::new('Reminders')
    ->handler('remind', static function (Context $ctx, array $reminder) use ($notifier): string {
        $ctx->sleep(24 * 60 * 60 * 1000); // one day

        $ctx->run('notify', static function () use ($notifier, $reminder): void {
            $notifier->send($reminder['user'], $reminder['text']);
        });

        return 'sent';
    });
```

The timer lives in Restate, not in PHP. In request/response mode the attempt ends at the sleep: PHP returns the response and the worker is free for other requests. When the timer fires, Restate starts a new attempt, which replays the journal up to the sleep and continues after it. The sleep survives restarts and redeployments of your endpoint and of Restate, and it costs no PHP process while it waits.

The delay must not be negative (`InvalidArgumentException`). Restate journals the wake-up time when the handler first reaches the sleep, so a replay does not restart the timer.

`$ctx->sleepFuture()` starts the same timer but does not wait. It returns a `DurableFuture` that completes with `null` when the timer fires, which you can combine with other futures ([Timeouts](#timeouts), [Durable Futures](concurrency.md)):

```php
$timer = $ctx->sleepFuture(5_000);
// ... start other operations ...
$timer->awaitResult(); // waits for the rest of the 5 seconds
```

> [!WARNING]
> In a Virtual Object, a sleeping exclusive handler still holds its key: other exclusive invocations for that key wait until the sleep ends and the handler finishes. To act later without blocking the object, send yourself a [delayed message](#scheduling-with-delayed-messages) instead.

Do not use PHP's `sleep()` or `usleep()` for waits that should last: they hold the worker, count towards the attempt's timeouts, and start over on every replay.

## Scheduling with delayed messages

To run a handler later, send it a delayed message with `$ctx->sendAfter()`. The current handler continues at once; Restate keeps the message and invokes the target when the delay is over:

```php
use Restate\Target;

// Invoke Reminders/remind in one hour, with this payload.
$handle = $ctx->sendAfter(Target::service('Reminders', 'remind'), ['user' => 'alice', 'text' => 'Stand up'], 3_600_000);
```

`sendAfter()` takes the same options as `send()`, such as an idempotency key and headers, and the delay in milliseconds as its third argument. It returns an `InvocationHandle` for the scheduled invocation: `$handle->cancel()` cancels it before it runs. The runtime clients have the same feature, `$ctx->serviceClient('Reminders')->request('remind', $payload)->sendAfter(3_600_000)`. [Delayed messages](communication.md#delayed-messages) covers the details.

Prefer a delayed message over a long `$ctx->sleep()` when nothing in the current handler needs to happen after the wait: the current invocation finishes, and no Virtual Object stays blocked.

## Timeouts

There is no timeout argument on calls, awakeables or promises. Instead, race the operation's future against a timer with `$ctx->select()`, which returns the key of the first future that completes:

```php
use Restate\Target;
use Restate\TerminalError;

$call = $ctx->callFuture(Target::service('Inventory', 'reserve'), $order);
$timeout = $ctx->sleepFuture(5_000);

if ($ctx->select(['call' => $call, 'timeout' => $timeout]) === 'timeout') {
    throw new TerminalError('Inventory did not answer within 5 seconds', 504);
}

$reservation = $call->awaitResult();
```

The same works for an awakeable, a signal or a workflow promise:

```php
use Restate\TerminalError;

['id' => $approvalId, 'future' => $approval] = $ctx->awakeableFuture();
// ... hand $approvalId to the approver ...

if ($ctx->select(['approval' => $approval, 'timeout' => $ctx->sleepFuture(3_600_000)]) === 'timeout') {
    throw new TerminalError('No approval within an hour', 408);
}
$decision = $approval->awaitResult();
```

When you only need the value, `$ctx->race()` returns or throws the result of the first future to complete. A timer that wins gives `null`, so use `select()` when `null` can also be a real result.

A timeout stops the wait, not the other operation: the called handler keeps running, and its result is simply not used. To cancel it, you need its invocation id, which a `callFuture()` does not expose. [Timeout And Cancellation Patterns](concurrency.md#timeout-and-cancellation-patterns) shows the options.

## Recurring work

Restate has no cron trigger. To repeat work, let a handler do its work and then schedule its next run with `$ctx->sendAfter()`. A Virtual Object is a good home for this: one key per job, and its state tracks the next scheduled run.

```php
use Restate\Endpoint;
use Restate\ObjectContext;
use Restate\Target;
use Restate\VirtualObject;

const POLL_INTERVAL_MILLIS = 60_000;

$poller = VirtualObject::new('FeedPoller')
    ->handler('start', static function (ObjectContext $ctx): string {
        if ($ctx->get('nextTick') !== null) {
            return 'already running';
        }

        $next = $ctx->sendAfter(Target::object('FeedPoller', $ctx->key(), 'tick'), null, POLL_INTERVAL_MILLIS);
        $ctx->set('nextTick', $next->id());

        return 'started';
    })
    ->handler('tick', static function (ObjectContext $ctx): void {
        // Only the tick that was scheduled last may continue the chain.
        if ($ctx->get('nextTick') !== $ctx->request()->id) {
            return;
        }

        $feedUrl = $ctx->key();
        $ctx->run('poll', static function () use ($feedUrl): void {
            // Fetch and process the feed here.
        });

        $next = $ctx->sendAfter(Target::object('FeedPoller', $ctx->key(), 'tick'), null, POLL_INTERVAL_MILLIS);
        $ctx->set('nextTick', $next->id());
    })
    ->handler('stop', static function (ObjectContext $ctx): string {
        $next = $ctx->get('nextTick');
        if ($next !== null) {
            $ctx->invocation($next)->cancel();
            $ctx->clear('nextTick');
        }

        return 'stopped';
    });

Endpoint::serve([$poller]);
```

Why the `nextTick` checks? The `sendAfter()` call is journaled, so a retry or replay of `tick` never schedules a second message. But a second `start` would start a second chain, and a tick that was already queued when `stop` ran would continue a chain you stopped. Storing the invocation id of the one scheduled tick, and letting only that tick continue, keeps exactly one chain per key. All three handlers are exclusive, so they never run at the same time for the same key.

Keep each tick short. The next tick is scheduled when the current one finishes, so the interval is the delay plus the tick's own run time, not a fixed rate.

## Design notes

- [Shared core](../decisions/0014-shared-core.md)
- [Tree awaits report their completed handles](../decisions/0036-tree-await-completed-handles.md) (how `select()` waits)
