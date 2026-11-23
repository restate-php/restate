# Serving

How to serve a PHP endpoint and register it with Restate: which entrypoint to use, running it under `php -S`, PHP-FPM or FrankenPHP, the extension it needs, request identity, the PHP settings every server needs, and what Restate's request/response mode costs.

Every endpoint needs the Restate PHP extension: there is no other backend and no pure-PHP fallback ([Installing the extension](installation.md#installing-the-extension)). [How Restate runs PHP handlers](concepts.md) explains what Restate sends the endpoint and why a handler runs several times.

## Entrypoint Decision Table

| Entrypoint | Use when |
| --- | --- |
| `Endpoint::serve($services)` | A plain PHP entrypoint that answers the current request: the built-in server, PHP-FPM behind nginx, Caddy or Apache ([PHP-FPM and FastCGI](#php-fpm-and-fastcgi) has the front-door settings), FrankenPHP in classic mode |
| `Endpoint::psr($services, $responseFactory)` | A PSR-7/PSR-15 framework or runtime provides requests and a response factory ([PSR-15 Handler](#psr-15-handler)) |
| `Endpoint::builder()->…->build()` | You need payload, serializer, codec, interceptor, logger or identity options |
| `build()` once, then `->handle($_SERVER, file_get_contents('php://input'))` per request | A long-lived worker: FrankenPHP worker mode ([FrankenPHP Worker Mode](#frankenphp-worker-mode)) |

`Endpoint::serve()` and `Endpoint::psr()` build the endpoint on every call: they reflect the services, check the extension and build the identity verifier. That suits PHP's one-request-per-script model. In a long-lived worker, `serve()` would build it again for every request, so build it once instead.

Where the services live matters in a worker. A service registered as an object is shared by every invocation the worker serves; bind a class with a factory or a container to get a new instance per attempt ([Service instances and dependencies](develop/services.md#service-instances-and-dependencies)).

## Running with php -S

PHP's built-in server is the quickest way to run an endpoint during development. With the endpoint in `index.php`:

```bash
PHP_CLI_SERVER_WORKERS=4 php -d display_errors=0 -d enable_post_data_reading=0 -S 0.0.0.0:9080 index.php
```

| Part | Why |
| --- | --- |
| `PHP_CLI_SERVER_WORKERS=4` | Without it the built-in server handles one request at a time, so one slow attempt holds up every other invocation and `/health`, while Restate sends attempts of different invocations at once. Each worker is a separate process; the variable is not supported on Windows |
| `-d display_errors=0` | PHP's default without a php.ini prints warnings into the response, which Restate rejects ([PHP Settings](#php-settings)) |
| `-d enable_post_data_reading=0` | Stops PHP from checking Restate's request body against `post_max_size` (8M by default), which would print a warning once a journal grows past it ([PHP Settings](#php-settings)) |
| `-S 0.0.0.0:9080` | Listens on every interface, so Restate in a container can reach it. This also exposes the endpoint to your network; use `127.0.0.1:9080` when Restate runs directly on the same host |
| `index.php` | The router script: every request, whatever its path, runs it |

On **PHP 8.6**, the built-in server checks `post_max_size` itself and answers `413` before the script runs, even with `enable_post_data_reading=0`. Add `-d post_max_size=0` (no limit) there.

The built-in server speaks **HTTP/1.1 only**, so register it with `use_http_11` ([Registering the deployment](#registering-the-deployment)). Its `max_execution_time` is 30 s, which also bounds each attempt ([Long Journals](#long-journals-cost-quadratic-replay)).

`php -S` is for development. For production, serve with PHP-FPM behind a front door ([PHP-FPM and FastCGI](#php-fpm-and-fastcgi)) or with FrankenPHP ([FrankenPHP Worker Mode](#frankenphp-worker-mode)).

## Registering the deployment

Restate only calls handlers of endpoints it knows. Register the endpoint's URL as a deployment through Restate's admin API (port 9070). Restate then calls the endpoint's `/discover` path to learn its services and handlers:

```bash
curl localhost:9070/deployments --json '{"uri": "http://host.docker.internal:9080", "use_http_11": true}'
```

(`--json` needs curl 7.82 or newer; with an older curl, pass `-H 'content-type: application/json' -d '…'`.) The answer lists the deployment's id and the services Restate discovered. Call a handler through the ingress (port 8080) to check:

```bash
curl localhost:8080/Greeter/greet --json '"Mary"'
# "Hello Mary"
```

The `restate` CLI does the same:

```bash
restate deployments register http://host.docker.internal:9080 --use-http1.1
```

| Request field | CLI flag | Use |
| --- | --- | --- |
| `uri` | the argument | The URL **as Restate reaches it**, not as you reach it |
| `use_http_11` | `--use-http1.1` | The server speaks HTTP/1.1 only, as `php -S` does. Without it, registration fails with `META0014` ("server possibly supports only HTTP1.1"). PHP-FPM behind nginx or Caddy can serve HTTP/2 ([PHP-FPM and FastCGI](#php-fpm-and-fastcgi)) |
| `force` | `--force` | Replace the deployment already registered at this URL. Development only (see below) |

The URI depends on where Restate and PHP run:

| Restate runs | PHP runs | URI |
| --- | --- | --- |
| On the host | On the host | `http://localhost:9080` |
| In Docker | On the host | `http://host.docker.internal:9080`. PHP must listen on `0.0.0.0`, not `127.0.0.1`. On Linux, start the container with `--add-host=host.docker.internal:host-gateway`; Docker Desktop defines the name itself |
| In Docker Compose | In another service of the same file | `http://<service name>:9080`, for example `http://php:9080` |

For example, Restate in Docker with the endpoint on the host:

```bash
docker run --rm -p 8080:8080 -p 9070:9070 --add-host=host.docker.internal:host-gateway \
    docker.restate.dev/restatedev/restate:1.8.1
```

`127.0.0.1` and `localhost` always mean Restate's own host: inside a container that is the container, so registering `http://127.0.0.1:9080` fails with `META0003` ("unable to reach the remote endpoint … Connection refused"). Check that the endpoint answers `GET /health` from where Restate runs.

Registering a URL that is already registered changes nothing: Restate keeps the services it discovered first. After you add or rename handlers in development, register again with `"force": true` (`--force`), which replaces the deployment in place. Do not use it in production: in-flight invocations would continue on the new code. Deploy each new version at a new URL instead ([Deployments and versioning](concepts.md#deployments-and-versioning)).

An endpoint with identity keys refuses unsigned discovery, so registration fails with `META0003 got status code '401 Unauthorized'` until Restate signs its requests ([Request Identity Validation](#request-identity-validation)).

## PHP Extension

The module is named `restate` and its file is `restate.so`. [Installing the extension](installation.md#installing-the-extension) covers PIE, Docker images and building from source. To load a module file by hand for one command, pass `-d extension=<path to restate.so>` to `php`.

Check that it loads in the PHP that serves: `php -m` (with the same `-d` options) lists the module as `restate`, and `php --ri restate` and `Restate\Extension\version()` report its version. php-fpm and the CLI can read different php.ini files.

A module loads only into the PHP minor version and thread-safety mode (NTS or ZTS) it was built for, so a threaded runtime such as FrankenPHP needs a ZTS build; PIE picks the right one for the PHP it installs into. Serve with a release build (about 3 MB, as PIE installs it). A debug build (about 42 MB, with debug assertions) is for developing the extension.

The extension and the SDK are installed separately, so the SDK checks that they match before it uses the extension. The extension reports a contract version (`Restate\Extension\contract_version()`), and building the endpoint fails unless it equals the version the installed `restate/sdk` requires.

When the extension is not loaded, or its contract version differs, `Endpoint::serve()`, `Endpoint::psr()` and `Endpoint::builder()->build()` throw `BackendUnavailableException`. The message names the module, the extension version, both contract versions and the package version, and says which side to upgrade and how to install the extension ([Troubleshooting](troubleshooting.md)). [Compatibility](compatibility.md) lists each extension version with its contract version.

## PHP Settings

These apply to every SAPI (`php -S`, PHP-FPM, FrankenPHP):

| Setting | Use | Why |
| --- | --- | --- |
| `display_errors` | `Off`, with `log_errors=On` | PHP's built-in default, without a php.ini, is on: warnings, notices and deprecations are printed into the response. The endpoint discards and logs what is printed while it handles a request. What PHP prints before the script runs has already been sent: the response goes out as `text/html`, Restate rejects it (`unexpected content type`, RT0012) and retries the attempt, and the endpoint logs an error naming where the output started, without running the handler. Measured under `php -S`: with `display_errors=1` and `enable_post_data_reading=1`, a 9 or 31 MiB input fails every attempt this way until the retry policy ends it; the same inputs complete with either setting changed. |
| `enable_post_data_reading` | `0` | Restate sends chunked bodies, and PHP still checks the de-chunked length against `post_max_size` (8M by default). Above it, PHP prints a `POST Content-Length … exceeds the limit` warning during request startup, which with `display_errors` on breaks every attempt as above. The body itself still arrives. With `enable_post_data_reading=0` PHP does not check the length, and the SDK reads only `php://input`. Raising `post_max_size` above the largest journal works too. **PHP 8.6's built-in server** (`php -S`) checks `post_max_size` itself and answers 413 before the script runs, even with `enable_post_data_reading=0`: there, set `post_max_size=0` (no limit) as well. php-fpm and FrankenPHP on 8.6 have not been checked. |
| `memory_limit` | above the largest journal, several times over | The request body, the journal decoded in the extension and the handler's values are all in memory during an attempt. Measured (debug extension, peak per attempt): a 31 MiB input peaks at about 66 MiB of PHP memory (`memory_get_peak_usage`) but 150–186 MiB of RSS (php-fpm and `php -S`), about five to six times the body, because the copies in the extension are invisible to PHP; a 31 MiB output peaks at about 118 MiB of RSS. Size container memory by RSS, not by `memory_limit`. |
| `max_execution_time` | above the slowest attempt | 30 s by default outside the CLI. Exceeding it is a fatal error, which Restate sees as a failed attempt and retries. |
| OPcache | on, as for any PHP application | Nothing in the SDK depends on it; the PHP-FPM tests run with it on. |

## Request Identity Validation

Give the endpoint the public keys of your Restate server (`publickeyv1_…`), and it rejects with `401` every invocation and discovery request that Restate did not sign with a matching private key. Only `/health` stays open; unknown paths still get `404`. This is the `identityKeys` option of the official SDKs, checked by the same verifier.

Without identity keys (do not pass `identityKeys`, or pass `null` to the shortcuts) the endpoint accepts unsigned requests, for local development.

```php
<?php

use Restate\Endpoint;

// $greeter is a service object; $responseFactory is your PSR-17 response factory.

// Throws InvalidArgumentException when RESTATE_IDENTITY_KEY is not set or empty, rather than accepting unsigned requests.
$identityKeys = [getenv('RESTATE_IDENTITY_KEY')];

Endpoint::serve([$greeter], identityKeys: $identityKeys);

$endpoint = Endpoint::builder()
    ->identityKeys($identityKeys)
    ->services([$greeter])
    ->build();

$handler = Endpoint::psr([$greeter], $responseFactory, identityKeys: $identityKeys);
```

**Configured keys fail closed.** Building the endpoint throws `InvalidArgumentException` when the configured list is empty, or a key is an empty string or `false`, which is what `getenv()` returns for a variable that is not set. A missing key therefore stops the endpoint instead of turning verification off. A malformed key throws `InvalidArgumentException` too. Under PHP-FPM, check `clear_env` ([PHP-FPM and FastCGI](#php-fpm-and-fastcgi)).

**Giving Restate its key.** Restate signs its requests once it has an Ed25519 private key: a PKCS#8 PEM file named by `worker.invoker.request-identity-private-key-pem-file` (environment variable `RESTATE_WORKER__INVOKER__REQUEST_IDENTITY_PRIVATE_KEY_PEM_FILE`). Restate 1.7.14 and 1.8.1 still read the top-level `request-identity-private-key-pem-file`, with a deprecation warning. The endpoint's identity key is the matching public key as `publickeyv1_<base58 of the 32 key bytes>`.

An endpoint that does not trust the key refuses discovery, so Restate cannot register it: on 1.8.1 `POST /deployments` fails with `500` and `META0003 got status code '401 Unauthorized'`, carrying the endpoint's body.

**What a request must carry.** When keys are configured, invocation and discovery requests must carry `x-restate-signature-scheme: v1` and a valid `x-restate-jwt-v1` token. Invalid, missing or explicitly unsigned identity is rejected with `401` before discovery or handler execution. The endpoint answers `/health` first and unknown paths with `404` before it checks identity. With a logger, the rejection is logged at error level, with the verifier's reason in `restate.identity.error` (for example `invalid JWT: ExpiredSignature`).

**The audience is the request path:** `/invoke/<Service>/<handler>` or `/discover`. Restate never puts an object or workflow key in the path (the key travels in the invocation's start message), so `/invoke/Counter/add` is the audience for every Counter key. Restate signs the path it appends to the deployment URI, not including the path of the deployment URI itself (1.8.1 and 1.7.14 alike). The SDK therefore compares the token with the request path's last three segments when they are `/invoke/<Service>/<handler>`, or with `/discover`, whatever prefix the endpoint is mounted under.

**The token checks**, with zero leeway:

| Claim | Rule |
| --- | --- |
| `alg` | Must be `EdDSA` |
| signature | The Ed25519 signature must verify against one of the configured keys |
| `aud` | The request path, as a string or a list of strings that contains it |
| `nbf`, `exp` | Both required, non-negative numbers (a fraction is rounded to the nearest second). The token is valid from `nbf` through `exp` inclusive |
| `iat`, `sub`, `target` | Not checked; newer servers add `sub` and `target` |

restate-server signs `nbf = now - leeway`, `iat = now` and `exp = now + leeway` with `request-identity-expiration` (default 60 s), so that setting is the clock-skew allowance; the SDK adds none.

The endpoint builds the verifier once, from its keys, and reuses it for every request. In a worker that is the worker's lifetime.

Discovery is identity-checked as in the official SDKs: Restate signs discovery requests through the same client as invocations, with audience `/discover`.

## FrankenPHP Worker Mode

In a FrankenPHP worker, build the endpoint once and hand it every request:

```php
<?php
// worker.php
require __DIR__ . '/vendor/autoload.php';

use Restate\Endpoint;

$endpoint = Endpoint::builder()
    ->services([new Greeter()]) // your services
    ->identityKeys([getenv('RESTATE_IDENTITY_KEY')])
    ->build();

$handler = static function () use ($endpoint): void {
    $endpoint->handle($_SERVER, (string) file_get_contents('php://input'));
};

$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);
for ($served = 0; $maxRequests === 0 || $served < $maxRequests; ++$served) {
    $keepRunning = frankenphp_handle_request($handler);
    gc_collect_cycles();
    if (!$keepRunning) {
        break;
    }
}
```

```caddyfile
{
	frankenphp {
		php_ini display_errors 0
		php_ini log_errors 1
		php_ini enable_post_data_reading 0
		worker {
			file /app/worker.php
			num 4
		}
	}
}

:8080 {
	root * /app
	rewrite * /worker.php
	php
}
```

`handle()` never throws and sends the response through the SAPI; `handleRequest()` returns it instead. `gc_collect_cycles()` after each request collects the context ↔ logger cycle a handler that calls `$ctx->logger()` leaves.

What to expect in worker mode, as tested with FrankenPHP 1.13.5 on PHP 8.4 ZTS ([TESTING.md](../TESTING.md#frankenphp-worker-mode)):

- **Service instances.** A service registered as an object is shared by every invocation the worker serves; one bound with a factory or a container gets a new instance per attempt ([Service instances and dependencies](develop/services.md#service-instances-and-dependencies)).
- **A context kept past its invocation** fails loudly: any operation on it throws a `BackendException` ("Extension VM has already been freed"). Log records stay with their own invocation.
- **Per-request state.** FrankenPHP resets superglobals, headers and output between requests, `headers_sent()` included, so the endpoint's handling of stray output works per request.
- **`exit()` and fatal errors** end the worker script. FrankenPHP starts it again (`unexpected termination, restarting`), which builds the endpoint again, and Restate retries the attempt that died; requests that arrive meanwhile are queued and served. FrankenPHP's `max_consecutive_failures` (6 by default) counts only scripts that fail before they first reach `frankenphp_handle_request()`, so fatal errors in handlers, even many in a row, do not stop the worker.
- **PHP settings.** The `dunglas/frankenphp` images ship no php.ini, so `display_errors` is on (`STDOUT`), and `max_execution_time` is 30, applied per request (the worker script itself is never timed out). A request body over `post_max_size` makes `frankenphp_handle_request()` print a warning before the endpoint runs, which breaks the response as described under [PHP Settings](#php-settings); `display_errors=0` or `enable_post_data_reading=0` avoids it, as for any SAPI.
- **Memory.** The extension's Rust heap is not in `memory_get_usage()` or `memory_limit`. Neither it nor PHP's heap grows per request once the worker is warm, and a VM never outlives its request (measured by the [soak suite](../TESTING.md#soak-suite)). A fatal error in the middle of a conversion in the extension leaks the Rust values being converted (at most the payload), because PHP unwinds past their destructors.
- **Threads.** Requests on different threads of one process share no VM, lock or log capture: the extension captures its log events per thread.

## PHP-FPM and FastCGI

**Tested** with php-fpm (PHP 8.4, OPcache on, `php.ini-production`) behind nginx 1.26 over HTTP/1.1 and h2c and behind Caddy 2.11.9 `php_fastcgi` over h2c, with the settings below. Apache httpd 2.4.71 `mod_proxy_fcgi` and every "at its defaults" outcome below were measured too ([TESTING.md](../TESTING.md#serving-large-bodies-php-fpm-and-front-door-hazards)).

**Restate sends invocation bodies without a length.** Restate 1.8.1 sends every invocation request with `Transfer-Encoding: chunked` over HTTP/1.1 (and no length over h2c), never with `Content-Length`. php-fpm reads at most `CONTENT_LENGTH` bytes of a FastCGI request body, so the front door must buffer the body and send a length.

With the defaults, each common front door fails above some size. Each failure is transient for Restate: it retries every attempt until the invocation's retry policy gives up, and the handler never runs. Measured outcomes (Restate 1.8.1, retry policy of three attempts then kill):

| Front door | At its defaults | Set |
| --- | --- | --- |
| Caddy `php_fastcgi` | Since 2.9.0 it answers `411 Length Required` to a body without a length, and since 2.10.0 it buffers only 4096 bytes before doing so. Any journal over 4 KiB fails every attempt. Measured on 2.11.9: 3.5 KiB passes, 5 KiB fails with the `411` in the invocation's failure. | `request_buffers` in the `php_fastcgi` block, above the largest journal (for example `request_buffers 64MiB`) |
| Apache `mod_proxy_fcgi` (2.4.47 or newer) | It prefetches up to 16 KiB and sends a length only if the whole body fit. Above that it streams without a length, and php-fpm reads an empty body. Measured on 2.4.71: 8 KiB passes; 64 KiB reaches PHP empty, and the attempt fails with the VM's `571 Protocol violation: The input was closed while still waiting to receive all journal to replay`. | `SetEnv proxy-sendcl 1`, which makes it spool the body and send its length (measured: 9 MiB passes) |
| nginx | It buffers the whole body and sends its length, but answers `413` above `client_max_body_size`, 1m by default (`client intended to send too large chunked body` in its log). Measured on 1.26.3: 512 KiB passes, 2 MiB fails with `service replied with content too large`. Do not turn off `fastcgi_request_buffering`: then nginx sends no length and php-fpm reads 0 bytes. | `client_max_body_size` above the largest journal |

**How large to set them.** Restate's own per-message limit is 32 MiB (`networking.message-size-limit`), and its ingress rejects a 40 MiB input with `413 length limit exceeded`, so a limit just above 32 MiB covers anything Restate sends. A 31 MiB input or output passes every front door with the settings above, and a 31 MiB response passes all three at their defaults too.

A value over 32 MiB that a handler produces (its output, a state value, a run result) reaches Restate in a valid response, and Restate refuses the message: `RT0003 message encoding error: hit message size limit: 34603018 >= 33816576`. That error is transient as well, not the non-transient `OutOfMemory`: Restate retries the attempt, the handler produces the value again, and only the retry policy ends it.

**Timeouts.** In request/response mode Restate guards an attempt only with its abort timeout, 600 s by default (see [Long Journals](#long-journals-cost-quadratic-replay); the inactivity timeout does not apply). Every timeout between Restate and the handler must be at least the handler's abort timeout, or a long attempt fails at the front door on every retry and the invocation never completes:

- nginx: `fastcgi_read_timeout` (60 s by default: an attempt over 60 s gets `504`).
- Caddy: the `php_fastcgi` transport's `read_timeout`.
- Apache: `ProxyTimeout` or the `timeout=` of the `ProxyPass`/`SetHandler` worker.
- php-fpm: `request_terminate_timeout` (a terminated worker is a `502`), and PHP's `max_execution_time`.

Either raise them to the abort timeout, or lower the abort timeout of long-running handlers to fit (`InvocationConfiguration(abortTimeoutMillis: …)`).

Measured with the timeouts lowered to 2 s: a run that sleeps 5 s gets `504` from nginx (`upstream timed out`) or a timeout from Caddy; with php-fpm's `request_terminate_timeout` the worker is killed and nginx answers `502`. Each time Restate retries, the run executes again because its result was never journaled (at least once), and the invocation completes on the third attempt. The first worker keeps running until its own work ends, holding a pool slot. A handler that is always slower than the front door never completes: every attempt fails the same way, and only the retry policy stops it (paused after five attempts in the test).

**Workers that die, reloads and back-pressure** (measured):

- A php-fpm worker killed after writing its response (SIGKILL, the OOM killer) loses the attempt in full behind nginx, even a complete response: nginx logs `readv() failed (104: Connection reset by peer) while reading upstream` and Restate sees the body break off (RT0010). Behind Caddy what was written arrives. Either way Restate retries, and runs that were not journaled execute again: at least once.
- A graceful reload (`USR2`) or stop (`QUIT`, the php images' stop signal) loses the attempts in flight with php-fpm's default `process_control_timeout = 0`. For rolling deploys set it to at least the longest attempt (the handlers' abort timeout): with `10s` a 3 s attempt finishes and its run executes once.
- A front door that sheds load with `503` or `429` and `Retry-After`: Restate waits that long before the next attempt, and such attempts do not count towards the invocation's retry policy.

**Environment.** php-fpm's own default is `clear_env = yes` (php-src `www.conf`), which hides the environment from workers: `getenv('RESTATE_IDENTITY_KEY')` then returns `false`. The official `php:*-fpm` Docker images set `clear_env = no`. Either set `clear_env = no`, or pass the variable explicitly (`env[RESTATE_IDENTITY_KEY] = $RESTATE_IDENTITY_KEY` in the pool).

With identity keys configured as in [Request Identity Validation](#request-identity-validation), a hidden key makes building the endpoint throw instead of accepting unsigned requests. Measured: with `clear_env = yes` the endpoint answers `500` to every request, `/health` included, and php-fpm logs `Uncaught InvalidArgumentException` naming the identity key; with `clear_env = no` the same endpoint answers unsigned discovery with `401` and signed discovery with `200`.

**PHP settings** as in [PHP Settings](#php-settings): `display_errors=Off` with `log_errors=On`, `enable_post_data_reading=0`, and OPcache on.

**Other differences from `php -S`.** FastCGI front doors pass `CONTENT_TYPE` and `CONTENT_LENGTH` even when the request had neither (empty); the endpoint treats an empty one as missing. Caddy gives a `POST` without a content type a form content type, so such a request (never one from Restate) gets the VM's `500` instead of the endpoint's `415`.

**HTTP/2.** Restate talks h2c to a deployment registered with `use_http_11: false` (the default; see [Registering the deployment](#registering-the-deployment)). nginx (1.25.1 or newer, `http2 on`) and Caddy (`servers { protocols h1 h2c }`) then serve h2c with prior knowledge and HTTP/1.1 on the same port.

## PSR-15 Handler

Frameworks and runtimes that speak PSR-7/PSR-15 can use `Endpoint::psr($services, $responseFactory)` to create a PSR-15 request handler.

```php
<?php

use Restate\Endpoint;

// $services is the list of your service objects, $responseFactory your PSR-17 response factory,
// and $serverRequest the PSR-7 request your framework received.
$handler = Endpoint::psr($services, $responseFactory);
$response = $handler->handle($serverRequest);
```

The SDK depends only on the PSR interfaces. Your application provides the concrete PSR-7 request/response implementation and a PSR-17 response factory. See [`examples/psr-endpoint-handler.php`](../examples/psr-endpoint-handler.php) for a complete snippet.

## PSR-Native Framework Examples

The SDK ships no framework-specific packages; PSR-native frameworks route requests to the handler returned by `Endpoint::psr(...)`:

- [`examples/slim-psr-endpoint.php`](../examples/slim-psr-endpoint.php) shows a Slim-style catch-all route closure that delegates to the `Endpoint::psr(...)` handler.
- [`examples/mezzio-psr-endpoint.php`](../examples/mezzio-psr-endpoint.php) shows a Mezzio-style handler registration using the `Endpoint::psr(...)` handler.

Both examples keep the framework registration calls commented out, so the files do not require `slim/slim`, `mezzio/*`, or a concrete PSR implementation.

## Request/Response Protocol Mode

Every PHP entrypoint serves Restate's request/response protocol mode: the endpoint reads the whole request body, runs the attempt from that closed input, and returns its output as the response. The discovery manifest therefore declares `protocolMode: REQUEST_RESPONSE`, never `BIDI_STREAM`. [Request/response mode and suspension](concepts.md#requestresponse-mode-and-suspension) explains what that means for a handler.

Bidirectional streaming needs a server that hands PHP request data before the request ends and sends response data before it ends. FrankenPHP, php-fpm and `php -S` all buffer the request body, so none of them can. `amphp/http-server` over h2c can, but the SDK has no entrypoint for it.

### Long Journals Cost Quadratic Replay

In request/response mode an attempt ends whenever the handler needs something Restate has not sent yet:

- every `$ctx->run()`, because its result must be journaled and acknowledged before the handler may continue;
- every await of a call, sleep, awakeable or promise result that is not in the journal yet.

The next attempt replays the journal from the start. A handler with N sequential steps therefore takes N + 1 attempts, and attempt k replays about 2k journal entries (each step is a command and its completion), each decoded once. The total replay work grows as O(N²): a few dozen steps cost little, a few thousand cost a lot.

Measured under `php -S` with the debug extension ([Scale tests](../TESTING.md#scale-tests)):

| Sequential `$ctx->run()` steps | Attempts | Total time |
| --- | --- | --- |
| 1000 | 1001 | 62 s |
| 3000 | 3001 | 363 s |

Each attempt was about 0.07 ms slower per step already journaled, most of it outside the handler.

**The abort timeout bounds each attempt.** In this mode it is the only timer on an attempt: the inactivity timeout does not apply, and the abort timer is re-armed only when response bytes arrive, which a PHP endpoint sends at the end. The default is 600 s (`worker.invoker.abort-timeout`), and a service or handler can set its own with `InvocationConfiguration(abortTimeoutMillis: …)`.

An invocation whose replay alone takes longer than its abort timeout can never finish. Restate aborts every attempt (`RT0001`, "the invocation stream was closed after the 'abort timeout' fired") and retries it until its retry policy gives up. The endpoint does not notice the abort: it finishes the replay, executes the next step's `$ctx->run()` again, and writes a response nobody reads, so that side effect repeats once per aborted attempt.

Measured with Restate 1.8.1: Restate does not cut the attempt off when the timeout expires. It reports the timeout only once the late response arrives, discards that complete response (a run computed in it is not journaled), and only then starts the retry. So attempts never overlap, but any attempt slower than its abort timeout is wasted and its side effects repeat. A connection that never answers is held until it closes.

PHP's `max_execution_time` (30 s under `php -S`) is a second ceiling of the same kind: it counts the replay too, and the fatal error it ends an attempt with is retried like any other failure.

**Keep journals short:** batch work into fewer, coarser `$ctx->run()` steps, or split a long loop into child invocations. Bidirectional streaming would be the structural fix, since one attempt would run the whole invocation and no step would cost a replay.

### Wide Fan-out Costs Quadratic Time per Attempt

Combining many futures in one invocation costs more than linear time per attempt, replay included:

- `all()` and `allSettled()` await one combinator tree over every future. The extension's state machine reduces the whole tree again after each result it takes in, so resolving n futures is O(n²) work, with a small constant: about 70 ms for 2000 futures with a release build of the extension, about 1 s with a debug build.
- A `select()` loop, a `DurableFuturesUnordered` (which is one) and `any()` await one tree per result, over the futures left. Each step also costs the SDK and the extension a pass over those futures, so the loop is O(n²) per attempt with a larger constant: about 350 ms for 2000 futures with a release build.
- When the futures of such a loop complete one per attempt, each attempt replays every `select()` before it, so the invocation as a whole is O(n³). With a debug build under `php -S`, a `select()` loop over awakeables completed one by one spent 4.5 s in the handler for 200 of them, 57 s for 500 and 7.5 minutes for 1000, the slowest attempt 1.1 s. Doubling n multiplies the time by about 8.

What to do: keep the futures one invocation combines to a few hundred, and fan out wider through child invocations that each combine a part. When you need every result, prefer `all()` or `allSettled()` to a `select()` loop: one tree, and on Restate 1.8 a single resumption once everything is in ([Durable Futures](develop/concurrency.md#combinators)). The benchmark and the scale tests behind these numbers are in [TESTING.md](../TESTING.md#scale-tests).

## Error Responses

`Endpoint::handleRequest()` (and so `handle()`, `serve()` and the PSR-15 handler) never throws. What it answers when something goes wrong:

| Situation | Response |
| --- | --- |
| An invocation without a `content-type` header | `415`, before the VM is created |
| A discovery request whose `Accept` header names no manifest version the SDK serves | `415` |
| A failure once the VM exists: the VM not becoming ready, invalid input from the backend, a backend call throwing while writing the output | Reported to the VM as a retryable error; the response is the VM's head plus whatever output it produced |
| Anything else, including a failure to create the VM or to report to it, and a failure while building the discovery manifest | `500` |

Error responses are `application/json` `{"error": "<message>"}`, like the `401` for a request without a valid identity.

## Tested Runtimes

The integration suite runs, unchanged, under PHP's built-in server, under FrankenPHP with the ZTS build in classic and in worker mode, and against php-fpm behind nginx (HTTP/1.1 and h2c) and Caddy (h2c), with the settings above. The upstream SDK test suite runs against `php -S` behind a Caddy h2c front door and against php-fpm behind nginx. `php -S` is a test fixture there, not a deployment recommendation. [Status](status.md#supported-platforms) lists the PHP and Restate versions, [TESTING.md](../TESTING.md) how each suite runs.

## Runtime Limitations

- Request/response mode only: manifests declare `REQUEST_RESPONSE`, not `BIDI_STREAM` ([Request/Response Protocol Mode](#requestresponse-protocol-mode)).
- Every `$ctx->run()` and every await of a result not yet journaled costs a new attempt and a full replay: O(N²) replay work for N steps, bounded per attempt by the abort timeout ([Long Journals](#long-journals-cost-quadratic-replay)).
- PHP-FPM's front doors need settings for Restate's length-less bodies and long attempts; at their defaults they fail every attempt above 4 KiB (Caddy), 16 KiB (Apache) or 1 MiB (nginx) ([PHP-FPM and FastCGI](#php-fpm-and-fastcgi)).
- No bundled PSR-7 implementation and no framework-specific adapters.
- The upstream SDK test suite passes (see [Conformance](internals/conformance.md)); that is not an official conformance claim.

## Design notes

Background for contributors:

- [Single Native Backend](decisions/0012-single-native-backend.md#why-not-a-pure-php-implementation): why there is no pure-PHP fallback; [Backend architecture](internals/architecture.md) shows how the pieces fit.
- [Bidirectional Streaming](decisions/0007-bidirectional-streaming.md): what was found about streaming PHP servers, and what an entrypoint on `amphp/http-server` would take.
- [Request Identity Through sdk-shared-core](decisions/0028-request-identity-shared-core.md): the verifier is sdk-shared-core's `IdentityVerifier` ([`src/request_identity.rs`](https://github.com/restatedev/sdk-shared-core/blob/v8.7.2/src/request_identity.rs), jsonwebtoken with zero leeway), the one the official TypeScript and Python SDKs use. Restate builds the signed path `/invoke/{service}/{handler}` in [`service_protocol_runner_v4.rs`](https://github.com/restatedev/restate/blob/20efb1b83a39b8227451e531c366e067588ae9ee/crates/invoker-impl/src/invocation_task/service_protocol_runner_v4.rs#L176-L182) and signs `parts.path` in [`service-client/src/lib.rs`](https://github.com/restatedev/restate/blob/20efb1b83a39b8227451e531c366e067588ae9ee/crates/service-client/src/lib.rs#L145-L150) (both v1.8.1); it signs discovery with audience `/discover` ([`discovery.rs`](https://github.com/restatedev/restate/blob/12adfb14c497b1577b1bba117f769e5eba941ee6/crates/service-protocol-v4/src/discovery.rs#L70), [`service-client/src/lib.rs`](https://github.com/restatedev/restate/blob/12adfb14c497b1577b1bba117f769e5eba941ee6/crates/service-client/src/lib.rs#L163-L177)). The routing order (`/health`, then `404` for unknown paths, then identity, then discovery or the invocation) follows TypeScript ([`endpoint/handlers/generic.ts`](https://github.com/restatedev/sdk-typescript/blob/492920de40602f7cdf6a0d56c363151309ebcb24/packages/libs/restate-sdk/src/endpoint/handlers/generic.ts#L162-L183)); Python ([`server.py`](https://github.com/restatedev/sdk-python/blob/377d6345505a481c07ba537a498f5b86d694aa66/python/restate/server.py#L369-L388)) and Java ([`EndpointRequestHandler.java`](https://github.com/restatedev/sdk-java/blob/6b0a96cd5ee4f1b241a929ce1dafc44db4bc4c9f/sdk-core/src/main/java/dev/restate/sdk/core/EndpointRequestHandler.java#L148-L163)) check identity before answering `404`.
- Error responses follow TypeScript ([`generic.ts`](https://github.com/restatedev/sdk-typescript/blob/492920de40602f7cdf6a0d56c363151309ebcb24/packages/libs/restate-sdk/src/endpoint/handlers/generic.ts#L120-L142): `handle` does not throw, a missing `content-type` is `415` ([L232-L238](https://github.com/restatedev/sdk-typescript/blob/492920de40602f7cdf6a0d56c363151309ebcb24/packages/libs/restate-sdk/src/endpoint/handlers/generic.ts#L232-L238)), other failures before the VM are `500`, a failure after it is `notify_error` followed by flushing the VM's output). The SDK serves discovery manifest versions v2 to v5. Java answers a VM creation error with the core's code (for example `415` for an unsupported protocol version); PHP answers `500`.
- [Stray Output](decisions/0032-stray-output.md): how the endpoint discards and logs what PHP prints during a request.
- [PHP-FPM Serving](decisions/0034-php-fpm-serving.md): the PHP-FPM test setup behind the measurements above.
- [Tree Awaits Report Their Completed Handles](decisions/0036-tree-await-completed-handles.md): the SDK's share of fan-out cost is one extension call per await. The quadratic re-reduction is shared-core's (`AsyncResultsState::try_resolve_future`, unchanged in restate-sdk-shared-core 8.7.2); an upstream fix, [restatedev/sdk-shared-core#122](https://github.com/restatedev/sdk-shared-core/pull/122) ("Resolve a future in linear time over its queued notifications"), is open and not in 8.7.2.
