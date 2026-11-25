# Examples

| Example | What it shows | Runs as is? |
| --- | --- | --- |
| [`quickstart/`](quickstart/README.md) | A Basic Service, a Virtual Object and a Workflow, with a Restate server in Docker Compose, and a walkthrough of durable execution: steps, timers, retries, a crash mid-request, state, a workflow waiting for an event | Yes, a standalone project; start here |
| [`testing/`](testing) | An integration test of an endpoint with the [test harness](../docs/develop/testing.md) and PHPUnit | Yes, in this repository: it is part of the integration suite (`just test integration php-cli`) |
| [`greeter.php`](greeter.php) | A Basic Service defined with the builder API | Yes, in this repository (see below) |
| [`attribute-greeter.php`](attribute-greeter.php) | A Basic Service defined with attributes, with documentation and metadata | Yes, in this repository |
| [`psr-endpoint-handler.php`](psr-endpoint-handler.php) | `Endpoint::psr()`: the endpoint as a PSR-15 request handler | Snippet: your framework provides the request and the response factory |
| [`slim-psr-endpoint.php`](slim-psr-endpoint.php), [`mezzio-psr-endpoint.php`](mezzio-psr-endpoint.php) | Routing to the PSR-15 handler from Slim and Mezzio | Snippets: the framework calls are commented out |
| [`symfony-json-payloads.php`](symfony-json-payloads.php), [`valinor-json-payloads.php`](valinor-json-payloads.php) | DTO payloads through the Symfony Serializer and Valinor adapters, with attribute and builder services | Snippets: they build an endpoint but don't serve it |

The guides explain each topic: [Services](../docs/develop/services.md), [Serving](../docs/serving.md) for the PSR-15
handler, and [Serialization](../docs/develop/serialization.md#symfony-and-valinor-adapters) for the adapters.

## Running the single-file examples

They load this repository's autoloader, so run them from a checkout, in the dev shell
([CONTRIBUTING.md](../CONTRIBUTING.md)):

```bash
composer install
just extension build  # extension/target/debug/restate.so
php -d extension=extension/target/debug/restate.so -d display_errors=0 -d enable_post_data_reading=0 \
    -S 0.0.0.0:9080 examples/greeter.php
```

Then register `http://localhost:9080` with a Restate server and call `Greeter/greet`, as in the
[README](../README.md#without-docker).
