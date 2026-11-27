# Durable steps

A durable step wraps code that talks to the outside world, such as an HTTP request, a database write or a payment, so that Restate records its result. This page covers `$ctx->run()`, its retry policies, and the context helpers that give you time and random values that stay the same on replay.

## Running a side effect

Handler code runs again every time Restate replays an invocation (see [The journal and replay](../concepts.md#the-journal-and-replay)). Anything that is not deterministic, or that changes something outside the handler, belongs in `$ctx->run()`:

```php
use Restate\Context;
use Restate\Service;

// $gateway and $mailer are your payment and mail clients, created elsewhere.
$payments = Service::new('Payments')
    ->handler('charge', static function (Context $ctx, array $order) use ($gateway, $mailer): string {
        $chargeId = $ctx->run('charge-card', static fn (): string => $gateway->charge($order['card'], $order['amount']));

        $ctx->run('send-receipt', static function () use ($mailer, $order, $chargeId): void {
            $mailer->sendReceipt($order['email'], $chargeId);
        });

        return $chargeId;
    });
```

The signature is `run(string $name, callable $callback, RunRetryPolicy|RunOptions|null $retryPolicy = null, Serde|string|null $serde = null): mixed`. The name shows up in the Restate UI, the CLI and the SDK's log records, so pick one that says what the step does.

### What Restate guarantees

- **The result is journaled.** Once the callback returns, Restate stores its result in the journal. On replay `$ctx->run()` returns the stored result and does not call the callback again.
- **The callback runs at least once.** If the process crashes or the attempt is lost after the callback started but before Restate stored its result, the next attempt calls it again. Make side effects idempotent: pass an idempotency key to the payment provider, use upserts, check before you insert.
- **A failure is retried.** An exception from the callback is retried under the step's [retry policy](#retry-policies). A `TerminalError` is not retried: `$ctx->run()` throws it, and throws the same error again on replay.

### Return values

The callback's return value is serialized with the context's default serializer, JSON unless you configured another one ([JSON by default](serialization.md#json-by-default)). Return scalars, arrays, or objects that `json_encode()` can encode. A callback without a return value (`: void`) gives `null`.

`$ctx->run()` always returns the decoded value, also the first time the callback runs. With JSON this means objects come back as associative arrays: a `\DateTimeImmutable` returned from the callback comes back as `['date' => …, 'timezone_type' => …, 'timezone' => …]`. Return the fields you need, or pass a custom serializer that rebuilds your type ([Custom serializers](serialization.md#custom-serializers)):

```php
use Restate\RunOptions;

// ProfileSerde is your own Restate\Serde implementation; $repository loads Profile objects by $id.
$profile = $ctx->run('load-profile', static fn (): Profile => $repository->load($id), serde: ProfileSerde::class);

// The same, through RunOptions:
$profile = $ctx->run('load-profile', static fn (): Profile => $repository->load($id), RunOptions::serde(ProfileSerde::class));
```

A value the serializer cannot encode, such as a resource, does not fail the step: it fails the whole attempt, and Restate retries the invocation, which runs the callback again. Fix the return type instead of relying on retries.

### Do not use the context inside the callback

Do not call `$ctx` methods that talk to Restate (`run`, `call`, `send`, `sleep`, `get`, `set`, awakeables, promises…) from inside a run callback. The SDK does not stop you, but the result is broken: the operation lands in the journal in the middle of the step, and since replay skips the callback, the next attempt does not produce it. Restate then reports a journal mismatch (`RT0016`, "Detected journal mismatch") and retries the invocation forever.

Do that work in the handler, before or after the step, and pass values into the callback with `use`.

### Each step ends the attempt

The PHP SDK serves Restate's request/response mode: an attempt ends whenever the handler needs something from Restate. Every `$ctx->run()` ends the attempt, because Restate must store the result before the handler may continue. The next attempt replays the journal from the start.

A handler with N sequential steps therefore takes N + 1 attempts, and replay work grows with N². A few dozen steps cost little; a few thousand cost a lot. Batch work into fewer, coarser steps, or split a long loop into child invocations. [Long Journals Cost Quadratic Replay](../serving.md#long-journals-cost-quadratic-replay) has the numbers.

## Retry policies

Without a policy, a failing step is retried as a failure of the whole attempt: the invocation retry policy decides the delay and whether there is another attempt ([Retry policies](errors.md#retry-policies)). Pass a `RunRetryPolicy` to control the retries of one step:

```php
use Restate\OnMaxAttempts;
use Restate\RunOptions;
use Restate\RunRetryPolicy;

// At most 3 attempts, 100 ms apart, and give up after one second.
$ctx->run('charge-card', $charge, RunRetryPolicy::fixedDelay(100, maxAttempts: 3, maxDurationMillis: 1000));

// 100 ms, 200 ms, 400 ms … up to 2 s apart; pause the invocation after 5 attempts.
$ctx->run('charge-card', $charge, RunRetryPolicy::exponential(maxAttempts: 5, onMaxAttempts: OnMaxAttempts::Pause));

// A retry policy and a serializer together.
$ctx->run('charge-card', $charge, RunOptions::retryPolicy(RunRetryPolicy::none())->withSerde(ChargeSerde::class));
```

`$charge` here is any callable, for example a closure that calls your payment gateway, and `ChargeSerde` is your own `Restate\Serde` implementation.

| Factory | Behaviour |
| --- | --- |
| `RunRetryPolicy::infinite()` | Sets no limit of its own: each failure fails the attempt, and the invocation retry policy decides the delay and when to give up. The default when you pass no policy. |
| `RunRetryPolicy::none()` | Never retries: the first failure is the step's terminal failure. |
| `RunRetryPolicy::fixedDelay(?int $intervalMillis = null, ?int $maxAttempts = null, ?int $maxDurationMillis = null, OnMaxAttempts $onMaxAttempts = OnMaxAttempts::Fail)` | Waits `intervalMillis` between attempts (`null`: Restate picks the delay from the invocation retry policy). Without `maxAttempts` and `maxDurationMillis` it sets no limit of its own. |
| `RunRetryPolicy::exponential(int $initialDelayMillis = 100, float $factor = 2.0, ?int $maxDelayMillis = 2000, ?int $maxAttempts = null, ?int $maxDurationMillis = 50000, OnMaxAttempts $onMaxAttempts = OnMaxAttempts::Fail)` | Multiplies the delay by `factor` (at least 1.0) after each attempt, up to `maxDelayMillis`. Note the default 50-second limit. |

`maxAttempts` counts every execution of the callback, the first one included: `maxAttempts: 2` allows one retry. `maxDurationMillis` limits how long the retries may go on in total. Durations and attempt counts must not be negative; the factories throw `InvalidArgumentException` otherwise.

Once the policy gives up, `onMaxAttempts` decides what happens:

| `OnMaxAttempts` case | What happens |
| --- | --- |
| `OnMaxAttempts::Fail` (default) | The last failure becomes the step's terminal failure, and `$ctx->run()` throws it as a `TerminalError`. Replay throws the same error. |
| `OnMaxAttempts::Pause` | The invocation pauses instead, until someone resumes it. The step records no result and runs again after the resume ([Pausing invocations](errors.md#pausing-invocations)). |

The terminal failure keeps the last exception's message. Its status code and metadata come from a `RetryableError`, which takes them as `TerminalError` does (a status code of 400–599 other than 570, which Restate reserves); any other exception gives 500 and no metadata:

```php
use Restate\RetryableError;

throw new RetryableError('payment provider unavailable', 503, ['provider' => 'acme']);
```

`RunOptions` bundles a retry policy and a serializer: `RunOptions::none()`, `RunOptions::retryPolicy($policy)` and `RunOptions::serde($serde)` create one, `withRetryPolicy()` and `withSerde()` return a changed copy. A `$serde` argument passed to `$ctx->run()` wins over the serializer in `RunOptions`.

An endpoint or service error mapper also sees exceptions from run callbacks, and can turn them into terminal errors or pauses ([Mapping exceptions with an error mapper](errors.md#mapping-exceptions-with-an-error-mapper)). Throwing `PauseError` from a callback pauses the invocation whatever the step's policy ([Pausing invocations](errors.md#pausing-invocations)).

## Retry-after delays

Some failures say when to try again, such as a rate limit with a reset window. Throw `RetryableError` with `retryAfterMillis` from the callback to ask for that delay:

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

Restate waits `retryAfterMillis` before the next attempt instead of the policy's interval. The policy still decides whether another attempt is allowed: its attempt and duration limits apply as usual, and `RunRetryPolicy::none()` never retries. A negative `retryAfterMillis` throws `InvalidArgumentException`.

Thrown by the handler itself, outside `$ctx->run()`, a `RetryableError` fails the attempt like any other exception, and its `retryAfterMillis` replaces the delay of the invocation retry policy for the next attempt ([Retrying with a delay](errors.md#retrying-with-a-delay)).

## Deterministic time

Use `$ctx->now()` whenever the current time influences control flow, state, output, IDs or scheduling. It returns Unix epoch milliseconds and records them in the journal, so replay sees the same timestamp:

```php
use Restate\Target;

// $deadlineMillis is a Unix timestamp in milliseconds, for example from the request.
$createdAtMillis = $ctx->now();
$delayMillis = max(0, $deadlineMillis - $ctx->now());

$ctx->sendAfter(Target::service('Reminder', 'send'), ['createdAt' => $createdAtMillis], $delayMillis);
```

Why not `time()`? PHP's clock functions (`time()`, `microtime(true)`, `new \DateTimeImmutable()`, `hrtime()`) return a new value on every replay. A handler that branches on them can take a different path on replay than it did the first time; Restate then detects a journal mismatch and retries the invocation without getting past it. Use them only outside replay-sensitive logic, for example in log messages, or inside a `$ctx->run()` callback when you capture a time together with another side effect.

`$ctx->now()` is a durable step named `date.now` that always uses JSON, whatever the default serializer. It costs what any step costs: in request/response mode each call ends the attempt. Call it once and reuse the value rather than calling it in a loop.

## Deterministic random values

The context has a random number generator seeded from the invocation id. It produces the same sequence on every attempt of the same invocation, so you can use it in handler code without a durable step:

| Method | Returns |
| --- | --- |
| `$ctx->randInt(int $min, int $max)` | An integer in `[$min, $max]`, both included. `$min > $max` and a range wider than 32 bits throw `InvalidArgumentException`. |
| `$ctx->randBytes(int $length)` | `$length` random bytes. A negative length throws `InvalidArgumentException`. |
| `$ctx->uuid()` | A random version 4 UUID string, for example as an idempotency key for a downstream call. |
| `$ctx->randomSeed()` | The seed itself, to seed a generator of your own. |

```php
use Random\Engine\Mt19937;
use Random\Randomizer;

$orderId = $ctx->uuid();
$shard = $ctx->randInt(0, 15);

// A PHP Randomizer that is deterministic too.
$randomizer = new Randomizer(new Mt19937($ctx->randomSeed()));
$sample = $randomizer->shuffleArray(['a', 'b', 'c']);
```

The values are not journaled: replay draws them again from the same seed. They only match if the handler draws them in the same order every time, so keep the code around them deterministic. Do not draw them inside a `$ctx->run()` callback: replay skips the callback, which shifts the sequence for every later draw. Inside a callback, PHP's own `random_int()` is fine, since the callback's result is journaled anyway.

These values are not cryptographically secure. Use `random_bytes()` inside `$ctx->run()` for secrets and tokens.

## Design notes

Contributor background on how steps are implemented:

- [Shared core](../decisions/0014-shared-core.md) (including retry-after on runs)
- [Pause](../decisions/0021-pause.md)
- [Runtime execution hooks](../decisions/0017-runtime-execution-hooks.md) (run interceptors and the error mapper)
