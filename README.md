# Restate PHP SDK

[![GitHub Workflow Status](https://img.shields.io/github/actions/workflow/status/restate-php/restate/ci.yaml?style=flat-square)](https://github.com/restate-php/restate/actions/workflows/ci.yaml)
[![Quickstart](https://img.shields.io/github/actions/workflow/status/restate-php/restate/quickstart.yaml?style=flat-square&label=quickstart)](https://github.com/restate-php/restate/actions/workflows/quickstart.yaml)
[![OpenSSF Scorecard](https://api.securityscorecards.dev/projects/github.com/restate-php/restate/badge?style=flat-square)](https://securityscorecards.dev/viewer/?uri=github.com/restate-php/restate)
[![PHP](https://img.shields.io/badge/php-8.4%20%7C%208.5%20%7C%208.6-777bb4?style=flat-square)](docs/compatibility.md)

[Restate](https://restate.dev) is a system for building resilient applications with durable execution. This
repository is an SDK for writing Restate services in **PHP**: handlers whose steps, calls, timers and state Restate
records, so they run to completion across crashes, restarts and deploys.

> [!WARNING]
> This is a **community-maintained, unofficial** SDK: it is not an official Restate SDK and is not supported by
> Restate.

## Highlights

- **Every service type**: [Basic Services, Virtual Objects and Workflows](docs/develop/services.md), via attributes
  or builders.
- **Steps, state, timers**: [`run()` with retry policies](docs/develop/durable-steps.md),
  [key-value state](docs/develop/state.md), [durable sleeps and timeouts](docs/develop/timers.md).
- **Service calls**: [request-response, one-way and delayed](docs/develop/communication.md), with idempotency keys.
- **External events**: [awakeables, signals and durable promises](docs/develop/external-events.md);
  [futures](docs/develop/concurrency.md) to wait on several at once.
- **No worker process**: Restate calls you over HTTP. [`php -S`, PHP-FPM, FrankenPHP or PSR-15](docs/serving.md).
- **Typed payloads**: [DTOs and enums](docs/develop/serialization.md) with inferred JSON Schemas; Symfony and
  Valinor adapters.
- **Production-ready**: [terminal errors and pausing](docs/develop/errors.md),
  [PSR-3 logging and interceptors](docs/develop/logging-and-hooks.md), PSR-11 containers,
  [request signing](docs/serving.md#request-identity-validation).
- **Ingress client and test harness**: [call services from your app](docs/ingress-client.md);
  [test in PHPUnit](docs/develop/testing.md) against a real Restate server.
- **Restate's own core**: the [Rust core](https://crates.io/crates/restate-sdk-shared-core) of the official SDKs, as
  a PHP extension. Passes their full [conformance suite](docs/internals/conformance.md).

## Example

```php
use Restate\Attributes as Restate;
use Restate\Context;
use Restate\Endpoint;
use Restate\ObjectContext;

#[Restate\Service('Onboarding')]
final class Onboarding
{
    public function __construct(private readonly Mailer $mailer) // your own mail client
    {
    }

    #[Restate\Handler]
    public function start(Context $ctx, string $email): string
    {
        // A durable step: once it succeeds, a retry of the handler skips it.
        $ctx->run('welcome email', fn () => $this->mailer->send($email, 'Welcome!'));

        // A durable timer: no PHP process waits, and it survives restarts.
        $ctx->sleep(3 * 24 * 3600 * 1000);

        $ctx->run('follow-up email', fn () => $this->mailer->send($email, 'How is it going?'));

        return "Onboarded {$email}";
    }
}

#[Restate\VirtualObject('Counter')]
final class Counter
{
    // One invocation per key at a time, with that key's own durable state.
    #[Restate\Handler]
    public function add(ObjectContext $ctx, int $amount): int
    {
        $count = ($ctx->get('count') ?? 0) + $amount;
        $ctx->set('count', $count);

        return $count;
    }
}

Endpoint::serve([new Onboarding(new Mailer()), new Counter()]);
```

## Quickstart

### With Docker

The [Docker quickstart](examples/quickstart/README.md) runs a PHP service and a Restate server with Docker Compose.
You don't need PHP on your machine.

```bash
git clone https://github.com/restate-php/restate.git
cd restate/examples/quickstart
```

```bash
docker compose up --build -d
./scripts/greet.sh Sarah            # {"message":"You said hi to Sarah!"}, about 5 seconds later
./scripts/kill-and-resume.sh        # kills PHP mid-request; Restate finishes the greeting anyway
```

Then open the Restate UI at <http://localhost:9070>. The
[walkthrough](examples/quickstart/README.md) explains each step, and continues with retries, state in a Virtual
Object, and a Workflow waiting for an event.

### Without Docker

You need PHP 8.4 or newer with the Restate extension ([Installation](docs/installation.md)), Composer, and a
Restate server ([`restate-server`](https://docs.restate.dev/installation), or the Docker image).

```bash
mkdir greeter && cd greeter
composer require restate/sdk
```

Write the endpoint, `index.php`:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Restate\Attributes as Restate;
use Restate\Context;
use Restate\Endpoint;

#[Restate\Service('Greeter')]
final class Greeter
{
    #[Restate\Handler]
    public function greet(Context $ctx, string $name): string
    {
        $ctx->sleep(1_000);

        return "Hello {$name}";
    }
}

Endpoint::serve([new Greeter()]);
```

Serve it with PHP's built-in server. The [serving guide](docs/serving.md#running-with-php--s) explains the flags:

```bash
PHP_CLI_SERVER_WORKERS=4 php -d display_errors=0 -d enable_post_data_reading=0 -S 0.0.0.0:9080 index.php
```

In a second terminal, start Restate, register the endpoint, and call the handler through Restate:

```bash
restate-server
```

```bash
# In a third terminal. PHP's built-in server speaks HTTP/1.1 only, hence use_http_11.
curl localhost:9070/deployments --json '{"uri": "http://localhost:9080", "use_http_11": true}'
curl localhost:8080/restate/call/Greeter/greet --json '"Mary"'    # "Hello Mary"
```

If Restate runs in Docker instead, start it with
`docker run --rm -p 8080:8080 -p 9070:9070 --add-host=host.docker.internal:host-gateway docker.restate.dev/restatedev/restate:1.8.1`
and register `http://host.docker.internal:9080`: inside the container, `localhost` is the container itself.

## Installation

| Package | Install | For |
| --- | --- | --- |
| `restate/ext-restate` | `pie install restate/ext-restate` | The PHP extension, needed wherever services are served |
| `restate/sdk` | `composer require restate/sdk` | Writing and serving services |
| `restate/ingress` | `composer require restate/ingress` | Calling services from your application, without the extension |
| `restate/testing` | `composer require --dev restate/testing` | Integration tests against a real Restate server |

[PIE](https://github.com/php/pie) installs a prebuilt extension on [supported platforms](#compatibility).
[Installation](docs/installation.md) covers Docker images, building from source, Nix, and verifying releases.

## Documentation

Start with [How Restate runs PHP handlers](docs/concepts.md), then:

| Writing services | Running them | Reference |
| --- | --- | --- |
| [Services](docs/develop/services.md) | [Installation](docs/installation.md) | [Compatibility](docs/compatibility.md) |
| [Durable steps](docs/develop/durable-steps.md) | [Serving](docs/serving.md): `php -S`, PHP-FPM, FrankenPHP, PSR-15 | [Troubleshooting](docs/troubleshooting.md) |
| [State](docs/develop/state.md) | [Logging and hooks](docs/develop/logging-and-hooks.md) | [Status](docs/status.md): features, gaps, parity |
| [Service communication](docs/develop/communication.md) | [Testing](docs/develop/testing.md) | [All docs](docs/README.md) |
| [Timers and scheduling](docs/develop/timers.md) | [Ingress client](docs/ingress-client.md) | |
| [Awakeables, signals and durable promises](docs/develop/external-events.md) | | |
| [Durable futures](docs/develop/concurrency.md) | | |
| [Error handling](docs/develop/errors.md) | | |
| [Serialization](docs/develop/serialization.md) | | |

For Restate itself (the server, its UI and CLI, deployment, operations), see the
[Restate documentation](https://docs.restate.dev).

## Examples

- [`examples/quickstart`](examples/quickstart/README.md): a Basic Service, a Virtual Object and a Workflow with
  Docker Compose, and a guided tour of durable execution.
- [`examples/testing`](examples/testing): an integration test with the test harness and PHPUnit.
- [More examples](examples/README.md): builders, PSR-15 frameworks, Symfony and Valinor payloads.

## Compatibility

| | Supported |
| --- | --- |
| PHP | 8.4, 8.5, 8.6; NTS and ZTS |
| Restate server | 1.8 or newer; 1.7 best-effort, [without a few features](docs/compatibility.md#restate-17) |
| Prebuilt extension | Linux x86_64 and arm64, glibc 2.28+ and musl. Elsewhere, including macOS: build from source |

Endpoints use Restate's request/response protocol, not bidirectional streaming. [Compatibility](docs/compatibility.md)
has the details.

## License

The project is licensed under the [MIT License](LICENSE).
