# Testing

The `restate/testing` package runs your services against a real Restate server. It starts Restate, serves your endpoint, registers it, and gives you an [ingress client](../ingress-client.md) to call your handlers the way production code would. This page covers the PHPUnit integration, the plain-PHP API, every configuration option, and the controls for testing failures.

[`examples/testing/`](../../examples/testing/) is a complete example: an endpoint and a PHPUnit test case.

## Installing the harness

```bash
composer require --dev restate/testing guzzlehttp/guzzle
```

What the harness needs:

| Requirement | Why |
| --- | --- |
| Docker or podman | Restate runs in a container by default. Rootless podman works: the harness runs the `docker` or `podman` CLI, so it needs no API socket and no Testcontainers setup. Not needed when you [run Restate as a process](#running-restate-without-containers). |
| The Restate PHP extension, for the PHP that serves the endpoint | Your endpoint needs it ([Configuring the endpoint](#configuring-the-endpoint) explains where the harness looks). The test process itself does not load it: `restate/testing` requires `restate/ingress`, not `restate/sdk`. |
| `guzzlehttp/guzzle` | The default HTTP client of `ingressClient()`. Any PSR-18 client works if you pass it yourself. |
| PHPUnit 10 or newer | Only for the `InteractsWithRestate` trait. The harness itself does not depend on PHPUnit. |
| `ext-pcntl` (optional) | Lets a run interrupted with Ctrl-C or SIGTERM remove what it started, unless your code installed its own handlers for those signals. |
| `ext-posix` (optional) | Lets `killEndpoint()` signal the `php -S` workers directly instead of running `kill`. |

## Testing with PHPUnit

Use the `InteractsWithRestate` trait in any test case. Implement `restateEndpoint()` to say what to serve, and call `self::restate()` to reach the environment:

```php
<?php

use PHPUnit\Framework\TestCase;
use Restate\Testing\PHPUnit\InteractsWithRestate;
use Restate\Testing\PhpCliServer;

final class GreeterTest extends TestCase
{
    use InteractsWithRestate;

    protected static function restateEndpoint(): PhpCliServer
    {
        // The script that calls Endpoint::serve(), as production serves it.
        return PhpCliServer::forScript(__DIR__ . '/../public/restate.php');
    }

    public function testGreets(): void
    {
        $greeter = self::restate()->ingressClient()->serviceClient('Greeter');

        self::assertSame('Hello Mary', $greeter->request('greet', 'Mary')->call());
    }
}
```

`PhpCliServer` serves the script with PHP's built-in server (`php -S`) and the extension loaded.

All test classes with the same configuration share one environment per PHP process. It starts before the first test that uses it, in about a second once the Restate image is present, and is removed when PHPUnit exits, also after a fatal error, and after Ctrl-C when `ext-pcntl` is loaded.

When a test fails, the trait prints the Restate log and what the endpoint logged during that test.

Restate keeps state between tests, because the environment is shared. Give each test its own object keys, workflow ids and idempotency keys:

```php
<?php

use PHPUnit\Framework\TestCase;
use Restate\Testing\PHPUnit\InteractsWithRestate;
use Restate\Testing\PhpCliServer;

final class CounterTest extends TestCase
{
    use InteractsWithRestate;

    protected static function restateEndpoint(): PhpCliServer
    {
        return PhpCliServer::forScript(__DIR__ . '/../public/restate.php');
    }

    public function testCountsPerKey(): void
    {
        $key = 'counter-' . bin2hex(random_bytes(4));
        $counter = self::restate()->ingressClient()->objectClient('Counter', $key);

        self::assertSame(2, $counter->request('add', 2)->call());
        self::assertSame(5, $counter->request('add', 3)->call());
    }
}
```

`CounterTest` and `GreeterTest` have the same configuration, so they share one environment.

The trait has two methods you can override:

| Method | Default | Override it to |
| --- | --- | --- |
| `restateEndpoint(): PhpCliServer\|EndpointContainer\|ExternalEndpoint` | (abstract) | Say what to serve: a script under `php -S`, [your own image](#serving-from-your-own-image), or [an endpoint that already runs](#testing-against-a-running-environment) |
| `restateContainer(): RestateContainer\|RestateProcess\|ExternalRestate` | `RestateContainer::default()` | Pin another Restate image, configure it, [run Restate as a process](#running-restate-without-containers), or [connect to one that already runs](#testing-against-a-running-environment) |

## Without PHPUnit

The same environment is a plain object. `RestateTestEnvironment::start()` starts Restate and the endpoint, registers the endpoint, and returns once Restate accepts invocations:

```php
<?php

use Restate\Testing\PhpCliServer;
use Restate\Testing\RestateContainer;
use Restate\Testing\RestateTestEnvironment;

$restate = RestateTestEnvironment::start(
    PhpCliServer::forScript(__DIR__ . '/endpoint.php')
        ->withExtension('/usr/lib/php/restate.so') // optional
        ->withEnvironment(['APP_ENV' => 'test']),
    RestateContainer::default()->withEnvironment(['RESTATE_LOG_FILTER' => 'restate=debug']), // optional
);

$greeting = $restate->ingressClient()->serviceClient('Greeter')->request('greet', 'Mary')->call();

$restate->registerEndpoint('/v2'); // a second endpoint the same script serves under /v2
$restate->stop();                  // or let the process exit
```

Everything the environment starts is removed by `stop()`, or at the latest when the PHP process exits.

| Method | What it does |
| --- | --- |
| `RestateTestEnvironment::start($endpoint, $restate = null)` | Starts a new environment. `$restate` defaults to `RestateContainer::default()`. |
| `RestateTestEnvironment::shared($endpoint, $restate = null)` | One environment per configuration and PHP process, started on first use and stopped when the process exits. The PHPUnit trait uses it. A failed start is not retried: later calls throw again. |
| `ingressClient($httpClient = null, $requestFactory = null, $streamFactory = null, $headers = [], $serde = null)` | An ingress client connected to this Restate. Without arguments it uses Guzzle; otherwise pass a PSR-18 client and a PSR-17 request factory. |
| `ingressUrl()`, `adminUrl()` | Restate's ingress and admin API, as the test process reaches them (`http://127.0.0.1:<port>`) |
| `endpointUrl()` | The endpoint, including its path, as the test process reaches it |
| `registerEndpoint($path = '')` | Registers a part of the endpoint served under `$path` below its own path, and returns the deployment id. `start()` has already registered the endpoint itself. |
| `serverVersion()` | Restate's version as its admin API reports it, such as `1.8.1` |
| `endpointLog($offset = 0)`, `endpointLogSize()` | What the endpoint wrote to stdout and stderr, across restarts. Pass a size you read earlier to get only what follows. |
| `restateLog($lines = 100)` | The last lines of the Restate log |
| `dumpLogs($endpointLogOffset = 0)` | Writes both logs to stderr |
| `stop()` | Removes the containers, stops Restate and the endpoint, deletes the scratch directory. Safe to call twice. |

## Configuring the endpoint

### Serving with `php -S`

`PhpCliServer::forScript($routerScript)` serves your endpoint entrypoint with the PHP that runs the tests. Every `with…()` method returns a new configuration.

| Method | Default | What it sets |
| --- | --- | --- |
| `withExtension(string $path)` | found automatically (see below) | The `restate.so` to load with `-d extension=…` |
| `withPhpBinary(string $binary)` | `PHP_BINARY`, the PHP running the tests | The PHP CLI binary. It must have the same version and thread safety (NTS or ZTS) as the extension. |
| `withIniSetting(string $name, string $value)` | `PhpCliServer::DEFAULT_INI_SETTINGS` | An ini setting (`-d name=value`). It replaces a default or an earlier value of the same name. |
| `withEnvironment(array $variables)` | the test process's environment | Variables added to the server's environment |
| `withWorkers(int $workers)` | 8 (`PhpCliServer::DEFAULT_WORKERS`) | Workers of the built-in server (`PHP_CLI_SERVER_WORKERS`). Each attempt holds a worker for its whole request, so with one worker a slow attempt would hold up every other invocation and the health checks. |
| `withPath(string $path)` | `''` | The path the endpoint is served and registered under, for an application that routes Restate requests below one, such as `/restate` |
| `withBindAddress(string $address)` | `0.0.0.0`, or `127.0.0.1` with a `RestateProcess` | The address the server listens on ([Network exposure](#network-exposure)) |
| `withDocumentRoot(string $directory)` | the router script's directory | `php -S -t` |
| `withWorkingDirectory(string $directory)` | the test process's | The server's working directory |

**Which extension it loads.** Without `withExtension()`, the harness takes the first of:

1. The file named by `RESTATE_PHP_EXTENSION_PATH`.
2. A module built from source in your project: `extension/target/debug/restate.so`, then `extension/target/release/restate.so`, under the directory that holds `vendor/`. A build from a checkout of this repository leaves it there.
3. Nothing: the PHP binary's own php.ini must load it, as installing it with PIE sets up.

Before serving, the harness builds an endpoint in a separate PHP process with the same binary, extension and ini settings. If the extension does not load, or does not match the installed SDK, the test fails right there with PHP's own error.

**Default ini settings.** The server starts with the settings a production server should have:

| Setting | Why |
| --- | --- |
| `display_errors=0`, `log_errors=1` | A warning or deprecation goes to the endpoint log instead of in front of the protocol frames. There it would corrupt the response, and Restate would retry the attempt for ever. `display_errors=stderr` does not help under `php -S`: it still writes into the response. |
| `enable_post_data_reading=0` | A journal larger than `post_max_size` raises no warning. The SDK reads only `php://input`, which still has the whole body. |

> [!WARNING]
> PHP 8.6's built-in server answers `413` to a body over `post_max_size` before your script runs, whatever `enable_post_data_reading` says. On PHP 8.6, raise it: `->withIniSetting('post_max_size', '0')` (no limit). See [Supported Platforms](../status.md#supported-platforms) and [PHP settings](../serving.md#php-settings).

### Serving from your own image

`EndpointContainer::forImage('my-app:test', 8080)` runs the endpoint in a container instead, for example your production FrankenPHP or PHP-FPM image. The image must exist locally or be pullable. It runs with the same container runtime as Restate (with a `RestateProcess`: `docker`, else `podman`, from `PATH`).

| Method | Default | What it sets |
| --- | --- | --- |
| `forImage(string $image, int $port = 8080)` | | The image, and the port the endpoint listens on inside the container |
| `withVolume(string $hostPath, string $containerPath, bool $readOnly = false)` | none | Bind-mounts a host file or directory, for example your code into a stock image |
| `withEnvironment(array $variables)` | none | Container environment variables |
| `withCommand(array $command)` | the image's command | Arguments after the image name |
| `withRunArguments(array $arguments)` | none | Extra `run` options, such as `['--cpus', '1', '--memory', '256m']`. The harness's own `--name`, `--rm`, `--detach` and `--publish` are refused. |
| `withHttp2(bool $http2 = true)` | HTTP/1.1 | Registers the endpoint for HTTP/2 (cleartext h2c with prior knowledge). The server in the image must accept it, as Caddy (FrankenPHP) and nginx with `http2 on` do. |
| `withPath(string $path)` | `''` | The path the endpoint is served and registered under |
| `withBindAddress(string $address)` | `0.0.0.0`, or `127.0.0.1` with a `RestateProcess` | The host address the port is published on |

## Configuring the Restate server

`RestateContainer::default()` runs `docker.restate.dev/restatedev/restate:1.8.1` (`RestateContainer::DEFAULT_IMAGE`, the version this SDK release is tested against), or the image in `RESTATE_PHP_RESTATE_IMAGE` if set. `RestateContainer::forImage($image)` pins another image and ignores the variable.

Restate's ingress and admin ports are published on `127.0.0.1` ports the harness picks.

| Method | Default | What it sets |
| --- | --- | --- |
| `withEnvironment(array $variables)` | one partition, compact logs without colors | Restate server configuration as `RESTATE_…` variables, such as `RESTATE_LOG_FILTER=restate=debug`. They replace the harness's defaults of the same name. |
| `withVolume(string $hostPath, string $containerPath, bool $readOnly = false)` | none | Mounts a file or directory that the configuration points to |
| `withContainerRuntime(string $command)` | `docker`, else `podman`, from `PATH` | The container CLI, as a name on `PATH` or a path |
| `withStartupTimeout(int $seconds)` | 60 | How long Restate and the endpoint may take to become ready |
| `withRunArguments(array $arguments)` | none | Extra `run` options, such as resource limits. The harness's own options are refused. |

For example, to test [request identity validation](../serving.md#request-identity-validation), mount the private key and point Restate at it:

```php
<?php

use Restate\Testing\RestateContainer;

// $pem is the path of an Ed25519 private key (PKCS#8 PEM) on the test host.
$restate = RestateContainer::default()
    ->withVolume($pem, '/key.pem', readOnly: true)
    ->withEnvironment(['RESTATE_WORKER__INVOKER__REQUEST_IDENTITY_PRIVATE_KEY_PEM_FILE' => '/key.pem']);
```

## Running Restate without containers

`RestateProcess::default()` runs `restate-server` from `PATH` as a process of the harness instead of a container. With a `PhpCliServer` endpoint nothing needs Docker, so your tests run where containers don't, such as macOS CI runners.

Get the binary from [nix-restate](https://github.com/sagikazarmark/nix-restate) (`restate-bin`, in a Nix or devenv shell) or a [release](https://github.com/restatedev/restate/releases). Ideally use the version of `RestateContainer::DEFAULT_IMAGE`, which the harness's defaults are tested with.

| Method | Default | What it sets |
| --- | --- | --- |
| `withBinary(string $binary)` | `restate-server` | The binary, as a name on `PATH` or a path |
| `withEnvironment(array $variables)` | one partition, compact logs without colors | Restate configuration, as for a container. A file a setting points to is a path on your machine: no volume needed. The variables the harness sets itself (`RestateProcess::HARNESS_VARIABLES`: the data directory, node name, listen mode and addresses) are refused. |
| `withStartupTimeout(int $seconds)` | 60 | How long Restate and the endpoint may take to become ready |

The process gets a fresh data directory and its own loopback ports. It sees none of your shell's `RESTATE_…` variables, as a container would not.

Return it from `restateContainer()` in the PHPUnit trait, or pass it to `start()`:

```php
<?php

use PHPUnit\Framework\TestCase;
use Restate\Testing\PHPUnit\InteractsWithRestate;
use Restate\Testing\PhpCliServer;
use Restate\Testing\RestateContainer;
use Restate\Testing\RestateProcess;

final class GreeterTest extends TestCase
{
    use InteractsWithRestate;

    protected static function restateEndpoint(): PhpCliServer
    {
        return PhpCliServer::forScript(__DIR__ . '/../public/restate.php');
    }

    protected static function restateContainer(): RestateContainer|RestateProcess
    {
        return RestateProcess::default();
    }
}
```

Every [failure-testing control](#failure-testing) works with a process too: a restart is SIGTERM (or SIGKILL) followed by a start on the same data directory and ports, and a pause is SIGSTOP.

## Testing against a running environment

The harness can also connect to a Restate server and an endpoint that already run, such as the ones `devenv up` starts or a Restate service container in CI, instead of starting its own. It waits until they are ready, registers the endpoint, and reads their log files if you name them. It never starts, stops or signals them.

`ExternalRestate` is the Restate server:

| Method | What it sets |
| --- | --- |
| `ExternalRestate::at(string $adminUrl, string $ingressUrl)` | The admin API and the ingress, as your tests reach them |
| `ExternalRestate::fromEnvironment()` | The same from `RESTATE_ADMIN_URL` and `RESTATE_INGRESS_URL`, which the `restate` CLI reads and devenv-extras' `services.restate` exports |
| `withTestHost(string $host)` | Where Restate reaches your machine, for an endpoint the harness serves (default `127.0.0.1`; `host.docker.internal` for a Restate in a container) |
| `withLogFile(string $path)` | A file the server logs to, printed when a test fails |
| `withStartupTimeout(int $seconds)` | How long Restate and the endpoint may take to become ready (default 60) |

`ExternalEndpoint` is the endpoint:

| Method | What it sets |
| --- | --- |
| `ExternalEndpoint::at(string $url)` | The endpoint as your tests reach it, with the path it is served under |
| `withRestateUri(string $uri)` | Where Restate reaches it, when that is not the same URL |
| `withHttp2()` | Register it for cleartext HTTP/2 (h2c) instead of HTTP/1.1 |
| `withLogFile(string $path)` | A file the endpoint logs to, for `endpointLog()` and failed tests |

Use either one, or both, in place of the kinds the harness runs:

```php
<?php

use PHPUnit\Framework\TestCase;
use Restate\Testing\ExternalEndpoint;
use Restate\Testing\ExternalRestate;
use Restate\Testing\PHPUnit\InteractsWithRestate;

final class GreeterTest extends TestCase
{
    use InteractsWithRestate;

    protected static function restateEndpoint(): ExternalEndpoint
    {
        return ExternalEndpoint::at('http://127.0.0.1:8000');
    }

    protected static function restateContainer(): ExternalRestate
    {
        return ExternalRestate::fromEnvironment();
    }
}
```

The harness only connects when you tell it to. It does not switch to a running Restate because `RESTATE_ADMIN_URL` is set.

There are a few differences from an environment the harness starts:

- The [failure-testing controls](#failure-testing) throw a `TestEnvironmentException`: `restartRestate()`, `pauseRestate()`, `unpauseRestate()`, `killEndpoint()`, `startEndpoint()`, `restartEndpoint()` and `signalEndpoint()`.
- The endpoint runs with its own configuration. The harness does not load the extension into it or set its environment variables.
- State outlives the run. Invocations, state and deployments of earlier runs are still there, so use fresh keys in every test, as you would anyway, and expect deployments the tests did not register.

## Network exposure

> [!WARNING]
> Restate in a container reaches your endpoint through `host.docker.internal`. So by default the endpoint listens on `0.0.0.0`, which **exposes it on your network for as long as the tests run**.

Where that matters, set a narrower address that the container can still reach through `host.docker.internal`: call `withBindAddress()` on the `PhpCliServer` or `EndpointContainer`, or set `RESTATE_PHP_TEST_BIND` for every test.

With a `RestateProcess`, Restate reaches the endpoint on loopback, so the endpoint listens on `127.0.0.1` by default.

Restate's own ports always listen on `127.0.0.1` only.

## Failure testing

To test what happens when either side fails, stop, pause or restart Restate or the endpoint while invocations are in flight. Then read Restate's view of the invocation through its admin SQL API.

```php
<?php

use PHPUnit\Framework\TestCase;
use Restate\Testing\PHPUnit\InteractsWithRestate;
use Restate\Testing\PhpCliServer;

final class GreeterFailureTest extends TestCase
{
    use InteractsWithRestate;

    protected static function restateEndpoint(): PhpCliServer
    {
        return PhpCliServer::forScript(__DIR__ . '/../public/restate.php');
    }

    public function testSurvivesAnEndpointCrash(): void
    {
        $restate = self::restate();
        $restate->killEndpoint();

        $invocationId = $restate->ingressClient()
            ->serviceClient('Greeter')->request('greet', 'Mary')->send()->invocationId();
        $restate->waitForInvocationStatus($invocationId, 'backing-off');

        $restate->startEndpoint();

        self::assertSame('Hello Mary', $restate->ingressClient()->invocation($invocationId)->attach());
    }
}
```

The environment is shared by every test class with the same configuration, so bring back what you broke before the test ends.

Restate's ports are chosen by the harness and stay the same across restarts. Its storage (deployments, journals, state, timers) survives restarts too. The endpoint keeps its port across restarts, so Restate's registration stays valid.

| Method | What it does |
| --- | --- |
| `restartRestate(bool $graceful = true, int $stopTimeoutSeconds = 10, ?float $timeoutSeconds = null)` | Stops and starts Restate, then waits until it is ready. Graceful is SIGTERM, then SIGKILL after `$stopTimeoutSeconds`; otherwise SIGKILL. Attempts in flight fail and are retried once Restate is back. |
| `pauseRestate()` | Freezes Restate: the container is paused (rootless podman needs cgroup v2 with the freezer delegated), or the process gets SIGSTOP. Connections stay open, but nothing runs. |
| `unpauseRestate(?float $timeoutSeconds = null)` | Thaws it and waits until it is ready |
| `killEndpoint()` | SIGKILLs the endpoint: the `php -S` server and its workers, or the endpoint container. Requests in flight end without a response. |
| `startEndpoint(?float $timeoutSeconds = null)` | Starts the endpoint again on the same port and waits until it is healthy. Nothing is registered again. |
| `restartEndpoint(bool $graceful = false, int $stopTimeoutSeconds = 10, ?float $timeoutSeconds = null)` | Kills the endpoint (or with `$graceful`, stops it with SIGTERM or the container's stop signal, then SIGKILL), and starts it again |
| `signalEndpoint(int $signal)` | Sends a signal to the `php -S` server or to the container's main process, for example SIGQUIT or SIGUSR2 to a php-fpm master |
| `isEndpointRunning()` | Whether the endpoint runs, as far as the harness can tell |
| `waitUntilReady(?float $timeoutSeconds = null)` | Waits until Restate is ready and, while it runs, the endpoint is healthy. `start()` and the lifecycle methods above call it themselves. |
| `registerDeployment(string $uri, bool $http2 = false, bool $force = true)` | Registers any URI with Restate, such as a proxy in front of the endpoint, and returns the deployment id. `$force` replaces a deployment registered under the same URI. |
| `testHostForRestate()` | The test host as Restate reaches it, for `registerDeployment()` URIs: `host.docker.internal` from a container, `127.0.0.1` for a process |

To inspect invocations:

| Method | What it does |
| --- | --- |
| `query(string $sql)` | Runs a query on Restate's admin SQL API (tables such as `sys_invocation`, `sys_journal` and `state`) and returns the rows |
| `invocationRow(string $invocationId, string ...$columns)` | The invocation's `sys_invocation` row with the given columns (all without any), or `null` while Restate has none. Useful columns: `status`, `retry_count`, `last_failure`, `last_failure_error_code`, `journal_size`. |
| `waitForInvocationStatus(string $invocationId, string $status, float $timeoutSeconds = 10.0)` | Polls the row every 100 ms until the status matches, and returns it. The statuses are in `RestateTestEnvironment::INVOCATION_STATUSES`: `pending`, `scheduled`, `ready`, `running`, `backing-off`, `suspended`, `paused`, `completed`. |

Restate answers queries from storage, so a row can lag the invocation by a moment. Poll for a condition, as `waitForInvocationStatus()` does, rather than asserting on a single read.

For commands of your own (`docker exec`, `docker inspect`):

| Method | Returns |
| --- | --- |
| `endpointPort()` | The host port the endpoint listens on or is published on |
| `containerRuntime()` | The container CLI the harness runs. Throws when nothing runs in a container. |
| `restateContainerName()` | The Restate container's name. Throws when Restate runs as a process. |
| `endpointContainerName()` | The endpoint container's name, or `null` for a `PhpCliServer` |

## When the environment does not start

Anything that does not start throws `Restate\Testing\TestEnvironmentException`. Its message ends with the tail of the Restate log and of the endpoint log.

When Restate refuses to register the endpoint, the exception is the subclass `DeploymentRegistrationException`. Its `statusCode()` and `responseBody()` carry Restate's answer, and `deploymentUri()` the URI it was asked to reach.

The harness cleans up when the PHP process exits. A process killed with SIGKILL (`kill -9`), or interrupted with Ctrl-C without `ext-pcntl`, leaves things behind:

- Containers carry the label `restate-php-test-environment`. Remove them with:

  ```bash
  docker rm -f $(docker ps -aq --filter label=restate-php-test-environment)
  ```

- A `RestateProcess` is left running as `restate-server`, with its data in a `restate-php-test-…` directory of the system temporary directory. Stop it by hand.

## Environment variables

| Variable | Effect |
| --- | --- |
| `RESTATE_PHP_EXTENSION_PATH` | The extension module a `PhpCliServer` loads when `withExtension()` is not called |
| `RESTATE_PHP_RESTATE_IMAGE` | The image of `RestateContainer::default()`, to run a suite against another Restate version |
| `RESTATE_PHP_TEST_BIND` | The address the endpoint listens on when `withBindAddress()` is not called |

## Design notes

- [Test Harness](../decisions/0023-test-harness.md)
- [External Test Environments](../decisions/0039-external-test-environments.md)
