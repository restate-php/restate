# Documentation

The Restate PHP SDK's documentation. New to Restate? Run the [Docker quickstart](../examples/quickstart/README.md),
then read [How Restate runs PHP handlers](concepts.md). For Restate itself (the server, its UI and CLI, operating
it), see the [Restate documentation](https://docs.restate.dev).

## Getting started

- [Docker quickstart](../examples/quickstart/README.md): a Basic Service, a Virtual Object and a Workflow with a
  Restate server, and a guided tour of durable execution.
- [Installation](installation.md): the extension, the packages, building from source.
- [How Restate runs PHP handlers](concepts.md): the journal, replay, suspension, determinism, deployments.

## Writing services

- [Services](develop/services.md): Basic Services, Virtual Objects and Workflows; attributes and builders;
  handler contexts; configuration; dependencies.
- [Durable steps](develop/durable-steps.md): `$ctx->run()`, retry policies, deterministic time and random values.
- [State](develop/state.md): reading and writing state, shared and exclusive handlers, eager and lazy state.
- [Service communication](develop/communication.md): calls, messages, delayed messages, idempotency keys,
  invocation handles, scopes and limit keys.
- [Timers and scheduling](develop/timers.md): durable sleep, delayed messages, timeouts, recurring work.
- [Awakeables, signals and durable promises](develop/external-events.md): waiting for events from outside.
- [Durable futures](develop/concurrency.md): waiting on several things at once; `all`, `race`, `select`.
- [Error handling](develop/errors.md): terminal and retryable errors, retry policies, pausing, error mappers.
- [Serialization](develop/serialization.md): JSON, DTOs and enums, JSON Schema, Symfony and Valinor, custom
  serializers.
- [Logging and hooks](develop/logging-and-hooks.md): PSR-3 logging and interceptors.
- [Testing](develop/testing.md): integration tests against a real Restate server.

## Running services

- [Serving](serving.md): `php -S`, PHP-FPM, FrankenPHP, PSR-15; registering deployments; PHP settings; request
  identity; the costs of request/response mode.
- [Ingress client](ingress-client.md): calling services from your application.
- [Troubleshooting](troubleshooting.md): common errors and how to report a bug.

## Reference

- [Compatibility](compatibility.md): versions, Restate server and platform support.
- [Status](status.md): what works and the tests behind it, parity with the official SDKs, known gaps.

## Contributing

These are for working on the SDK itself; start with [CONTRIBUTING.md](../CONTRIBUTING.md).

- [Decision records](decisions/README.md): why the SDK works the way it does.
- [Backend architecture](internals/architecture.md): how the packages, the extension and shared-core fit together.
- [Conformance](internals/conformance.md): the upstream SDK test suite.
- [TESTING.md](../TESTING.md) and [RELEASING.md](../RELEASING.md); [CONTEXT.md](../CONTEXT.md), the glossary.
- [Research notes](research/).
