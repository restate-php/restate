# Quickstart: a PHP service with Restate, in Docker Compose

In about ten minutes you run a PHP service with a Restate server, call it, look at what Restate recorded, make it
fail, kill it halfway through a request, and watch it finish anyway. All you need on your machine is Docker. You
don't need PHP.

The project has three services, one of each kind Restate has:

| Service | Kind | What it shows |
| --- | --- | --- |
| [`Greeter`](src/Greeter.php) | Basic Service | Durable steps, a durable timer, retries, recovery from a crash |
| [`Counter`](src/Counter.php) | Virtual Object | State per key, exclusive and shared handlers |
| [`Signup`](src/Signup.php) | Workflow | Waiting durably for an external event |

You need:

- Docker with Compose v2
- `curl` 7.82 or newer, for `--json`
- Ports 8080 and 9070 free
- Linux, macOS (Intel or Apple silicon), or Windows with WSL 2

## 1. Start everything

```bash
git clone https://github.com/restate-php/restate.git
cd restate/examples/quickstart
docker compose up --build -d
```

```bash
docker compose ps --all
```

You see three containers:

- `restate` is the Restate server, `healthy`.
- `greeter` is your PHP code, served by PHP's built-in server, `healthy`.
- `register` has `exited (0)`: it registered the PHP service with Restate.

What happened:

1. [`Dockerfile`](Dockerfile) built an image with PHP 8.4, the Restate extension and the `restate/sdk` package.
   It serves [`index.php`](index.php), the endpoint, on port 9080 with the settings in [`php.ini`](php.ini).
2. The `register` container told Restate where the endpoint is. It sent `POST /deployments` to Restate's admin API
   on port 9070. Restate then asked the endpoint which services and handlers it has:

   ```bash
   docker compose logs register
   ```

   ```json
   {"id":"dp_…","services":[{"name":"Signup","ty":"Workflow",…},{"name":"Greeter","ty":"Service",…},{"name":"Counter","ty":"VirtualObject",…}]}
   ```

From now on you call Restate, not PHP. Restate calls PHP.

## 2. Read the code

Open [`src/Greeter.php`](src/Greeter.php):

```php
#[Restate\Service('Greeter')]
final class Greeter
{
    #[Restate\Handler]
    public function greet(Context $ctx, Greeting $greeting): GreetingResponse
    {
        $greetingId = $ctx->uuid();

        $ctx->run('notification', fn () => $this->notifications->send($greetingId, $greeting->name));
        $ctx->logger()->info("Notification sent: {$greetingId}");

        $ctx->sleep(5_000);

        $ctx->run('reminder', fn () => $this->notifications->remind($greetingId, $greeting->name));
        $ctx->logger()->info("Reminder sent: {$greetingId}");

        return new GreetingResponse("You said hi to {$greeting->name}!");
    }
}
```

- **`#[Restate\Service]`** makes the class a Basic Service, and **`#[Restate\Handler]`** makes the method a handler
  that Restate can call.
- **`Context $ctx`** is how the handler talks to Restate. Restate records each thing you do through it in the
  invocation's *journal*.
- **`$ctx->uuid()`** returns a random UUID that Restate records, so every retry of this invocation gets the same
  one.
- **`$ctx->run()`** is a *durable step*. Once the callback succeeds, Restate records its result. When the handler
  runs again after a failure, it skips the step and returns the recorded result. The notification is sent once.
- **`$ctx->sleep()`** is a *durable timer*. Restate keeps the timer, and no PHP process waits for it.
- **`$ctx->logger()`** logs each line once per invocation, even though the handler may run several times.
- **`Greeting`** and **`GreetingResponse`** are plain PHP classes. The SDK maps the JSON request to `Greeting`,
  and `GreetingResponse` to the JSON response.

> [!NOTE]
> A handler doesn't `echo`. Restate reads the handler's response from the request's output, so the SDK discards
> anything printed and logs a warning instead. Log what you want to see.

## 3. Call the service

```bash
./scripts/greet.sh Sarah
```

About five seconds later:

```json
{"message":"You said hi to Sarah!"}
```

The script calls `POST http://localhost:8080/restate/call/Greeter/greet` with `{"name": "Sarah"}`. Port 8080 is
Restate's ingress, where clients call services.

Now look at the service's log:

```bash
docker compose logs greeter
```

Among the lines PHP's built-in server prints, you see the SDK's:

```text
[debug] [inv_17UB…] Starting invocation
[debug] [inv_17UB…] Invocation suspended
[debug] [inv_17UB…] Replaying invocation
[info] [inv_17UB…] Notification sent: c4f408ce-82be-4d9d-baed-3e8d95ec9fd8
[debug] [inv_17UB…] Invocation suspended
[debug] [inv_17UB…] Replaying invocation
[debug] [inv_17UB…] Invocation suspended
[debug] [inv_17UB…] Replaying invocation
[info] [inv_17UB…] Reminder sent: c4f408ce-82be-4d9d-baed-3e8d95ec9fd8
[debug] [inv_17UB…] Invocation completed
```

One invocation took four short HTTP requests from Restate, each one an *attempt*:

1. The handler started and ran the notification step. The attempt ended so that Restate could record the result.
2. The handler replayed from the top. The step returned its recorded result instead of running again. The
   handler logged, then started the timer and *suspended*: the request ended and PHP stopped.
3. Five seconds later, Restate called again. The handler replayed up to the timer and ran the reminder step.
4. The last replay returned the response.

The greeting id is the same in every attempt, and each line is logged once.
[How Restate runs PHP handlers](../../docs/concepts.md) explains the journal, replay and suspension.

## 4. Look inside with the Restate UI

Open <http://localhost:9070>.

- **Invocations**: open Sarah's invocation to see its journal: the `notification` run, the sleep, the `reminder`
  run, the output.
- **Services → Greeter**: the handler, the JSON Schema of its input that the SDK inferred from `Greeting`, and a
  playground to call it from a form.

## 5. Watch Restate retry a failing step

[`src/Notifications.php`](src/Notifications.php) fails 7 times out of 10 for Alice, as a flaky email provider
would:

```bash
./scripts/greet.sh Alice
docker compose logs greeter | grep SIMULATED
```

```text
[warning] [inv_1eyz…] Run 'notification' failed; Restate retries it: [SIMULATED] Failed to send notification 0c9cf407-… to Alice
[warning] [inv_1eyz…] Run 'notification' failed; Restate retries it: [SIMULATED] Failed to send notification 0c9cf407-… to Alice
```

The call still returns `{"message":"You said hi to Alice!"}`. Restate retried the failing step until it succeeded,
with the same greeting id each time. Once the notification has been sent, a failing reminder never sends it
again. Any exception retries; throw a `TerminalError` to fail for good
([Error handling](../../docs/develop/errors.md)).

## 6. Kill the service mid-invocation

This is what Restate is for. The script sends a greeting without waiting for it, kills the PHP container while the
handler sleeps, and starts it again:

```bash
./scripts/kill-and-resume.sh
```

While the container is down, the timer fires and Restate can't reach the service. The script shows what Restate
knows about the invocation (`restate invocations describe`, run inside the Restate container):

```text
 Status:               backing-off  (3 seconds and 829 ms. Retried 3 time(s). Next retry in 302 ms.)
 Error:                [500] error when calling 'http://greeter:9080/': unable to reach the remote endpoint. …

Journal:
 [0]: Input command
 [1]: Run command         notification
 [2]: Run notification    notification
 [3]: Sleep command
 [4]: Sleep notification
   └ completion of [3]
 >> backing-off
```

Restate has recorded the notification and the completed sleep, and keeps retrying. Once the container is back, the
next retry finishes the invocation:

```json
{"message":"You said hi to Mary!"}
```

The log shows one notification and one reminder, with the same greeting id, although PHP was killed in between.
Try `docker compose restart restate` during a sleep too: Restate keeps its journals and timers on disk, so the
greeting still finishes.

> [!TIP]
> A crash *inside* a `$ctx->run()` callback, before Restate has recorded its result, runs the step again. Steps run
> at least once, so make side effects idempotent, for example by passing `$greetingId` to the email provider as an
> idempotency key. See [Durable steps](../../docs/develop/durable-steps.md).

## 7. Keep state in a Virtual Object

[`src/Counter.php`](src/Counter.php) is a Virtual Object: each key, here `mary`, has its own state, and Restate
runs one exclusive handler per key at a time, so `add` needs no locks:

```bash
curl localhost:8080/restate/call/Counter/mary/add --json 1          # 1
curl localhost:8080/restate/call/Counter/mary/add --json 2          # 3
curl -X POST localhost:8080/restate/call/Counter/mary/get           # 3
```

The state lives in Restate: there is no database to set up. `get` is a shared handler (`#[Restate\Shared]`): it
can read state but not write it, and runs concurrently with other invocations of the same key. See
[State](../../docs/develop/state.md).

## 8. Wait for an event in a Workflow

[`src/Signup.php`](src/Signup.php) is a Workflow. Its `run` handler sends a verification email (a stub here), then
waits for the user to click the link, for as long as it takes:

```bash
curl localhost:8080/restate/send/Signup/mary/run --json '"mary@example.com"'
docker compose logs greeter | grep "Verification secret"
```

```text
[info] [inv_119l…] Verification secret for mary@example.com: 319ae43d-6e5c-4c09-9b58-7df03b019846
```

"Click the link" by calling the shared handler `click` with that secret, then get the workflow's result:

```bash
curl localhost:8080/restate/call/Signup/mary/click --json '"319ae43d-6e5c-4c09-9b58-7df03b019846"'
curl localhost:8080/restate/workflow/Signup/mary/attach             # "Welcome, mary@example.com!"
```

While it waited, no PHP process was running. See
[Awakeables, signals and durable promises](../../docs/develop/external-events.md).

## 9. Change the code

Edit [`src/Greeter.php`](src/Greeter.php), for example the message, then rebuild and register again:

```bash
docker compose up --build -d
```

The `register` container runs again with `force`, which replaces the registration.

> [!WARNING]
> `force` is for development. Changing a handler while invocations of it are still in flight can make their replay
> diverge from their journal. In production, register a new deployment and keep the old one running until its
> invocations finish. See [Deployments and versioning](../../docs/concepts.md#deployments-and-versioning).

## 10. Run PHP on your machine instead

If you have PHP 8.4 or newer with the Restate extension ([Installation](../../docs/installation.md)), run the
service directly, with Restate still in Docker:

```bash
docker compose up -d restate
composer install
PHP_CLI_SERVER_WORKERS=4 php -c php.ini -S 0.0.0.0:9080 index.php
```

In another terminal, register it. Restate reaches your machine as `host.docker.internal`:

```bash
./scripts/register.sh http://host.docker.internal:9080
./scripts/greet.sh Sarah
```

## Clean up

```bash
docker compose down
```

This also deletes Restate's state, so the next `up` starts fresh.

## Next steps

- [How Restate runs PHP handlers](../../docs/concepts.md): the journal, replay, suspension and determinism.
- [Services](../../docs/develop/services.md): writing services of your own.
- [Testing](../../docs/develop/testing.md): integration tests with `restate/testing` ([`examples/testing`](../testing)).
- [Serving](../../docs/serving.md): PHP-FPM, FrankenPHP and PSR-15 instead of the built-in server.
- [Ingress client](../../docs/ingress-client.md): calling services from your PHP application.

## Files

| File | What it is |
| --- | --- |
| [`compose.yaml`](compose.yaml) | Restate, the PHP service and the one-shot registration |
| [`Dockerfile`](Dockerfile) | PHP 8.4, the extension (PIE), the SDK (Composer), `php -S` |
| [`php.ini`](php.ini) | The PHP settings every Restate endpoint needs |
| [`index.php`](index.php) | The endpoint: serves the three services |
| [`src/`](src) | The services, their input and output classes, a notification stub and a stderr logger |
| [`scripts/`](scripts) | `greet.sh`, `kill-and-resume.sh`, `register.sh`, and `smoke-test.sh`, which CI runs to check this walkthrough |
| [`compose.local.yaml`](compose.local.yaml) | Builds against this repository instead of the released packages |
