# Restate PHP SDK — Ingress client

`Restate\Ingress\IngressClient` invokes [Restate](https://restate.dev) services through the Restate ingress from code that is not a handler: a web controller, a CLI command, a queue worker, a test. It calls, sends (now or delayed) and attaches to invocations, with idempotency keys, headers, scopes and limit keys, submits and attaches to workflows, and resolves or rejects awakeables. It runs over any PSR-18 HTTP client and needs neither [`restate/sdk`](https://github.com/restate-php/sdk), the Restate PHP extension nor an endpoint.

> [!NOTE]
> A community-maintained SDK: not an official Restate SDK, and not supported by Restate.

## Installation

```bash
composer require restate/ingress guzzlehttp/guzzle
```

Any PSR-18 client with PSR-17 factories works; Guzzle is one.

## Usage

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Restate\Ingress\IngressClient;

$restate = IngressClient::connect('http://localhost:8080', new Client(), new HttpFactory());

// Call and wait for the result.
$greeting = $restate->serviceClient('Greeter')->request('greet', 'Mary')->call();

// Send without waiting; attach later.
$sent = $restate->objectClient('Counter', 'alice')->request('add', 1)->idempotencyKey('add-1')->send();
$total = $sent->attach();
```

A handler's `TerminalError` is rethrown as is; every other failure is a `Restate\Ingress\IngressException`.

## Requirements

- PHP 8.4, 8.5 or 8.6
- A PSR-18 HTTP client and PSR-17 factories
- No Restate PHP extension

## Documentation

See [Ingress client](https://github.com/restate-php/restate/blob/main/docs/ingress-client.md) for the full API, and the [documentation](https://github.com/restate-php/restate/blob/main/docs/README.md) for the rest of the SDK.

This repository is a read-only subtree split of [restate-php/restate](https://github.com/restate-php/restate). Report issues and send pull requests there.

## License

MIT; see [LICENSE](LICENSE).
