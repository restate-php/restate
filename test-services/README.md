# Test Services

`test-services/` holds the Restate services the upstream SDK test suite drives, a few of which the integration suite (`tests/Integration`) serves too, as the other Restate SDKs' `test-services/` do. The images and server configuration that serve them are in [`docker/`](../docker/README.md); how the suites run is in [TESTING.md](../TESTING.md).

## Services

Each file in `services/` returns one service definition implementing a contract of the upstream suite (`restatedev/e2e` v2.3, [`sdk-tests/…/contracts/`](https://github.com/restatedev/e2e/tree/v2.3/sdk-tests/src/main/kotlin/dev/restate/sdktesting/contracts)). The integration suite serves some of them as well; its own services are in `tests/Integration/Services/`.

| File | Service | Served by |
| --- | --- | --- |
| `greeter.php` | `Greeter`: JSON in and out, optional input, no output, durable and retrying `ctx.run`, explicit discovery schemas | both |
| `communicator.php` | `Communicator`: calls, sends, timers, awakeables, combinators and invocation handles across the other services | both |
| `counter.php` | `Counter`: Virtual Object with per-key JSON state | both |
| `payments.php` | `Payments`: terminal errors with status code and metadata | both |
| `checkout.php` | `Checkout`: Workflow that waits for and resolves an approval promise | both |
| `echo_workflow.php` | `EchoWorkflow`: a Workflow that returns its input | both |
| `proxy.php` | `Proxy`: calls and sends on the suite's behalf, with scope and limit key | upstream |
| `test_utils_service.php` | `TestUtilsService`: echo, headers, raw bytes, side-effect counting, cancellation, signals | upstream |
| `failing.php` | `Failing`: terminal and retryable failures in handlers and runs | upstream |
| `virtual_object_command_interpreter.php` | `VirtualObjectCommandInterpreter`: runs command lists that combine awakeables, signals, sleeps and runs | upstream |
| `map_object.php`, `list_object.php` | `MapObject`, `ListObject`: state reads, writes and clearing | upstream |
| `awakeable_holder.php` | `AwakeableHolder`: holds an awakeable for another invocation to unlock | upstream |
| `kill_test_runner.php`, `kill_test_singleton.php` | `KillTestRunner`, `KillTestSingleton`: kill and cancellation trees | upstream |
| `cancel_test_runner.php`, `cancel_test_blocking_service.php` | `CancelTestRunner`, `CancelTestBlockingService`: cancellation | upstream |
| `block_and_wait_workflow.php` | `BlockAndWaitWorkflow`: a promise resolved or rejected from a shared handler | upstream |
| `non_deterministic.php` | `NonDeterministic`: alternates operation shapes per key to trigger journal mismatches | upstream |

"Upstream" services are served by `index.php` only, "both" by `index.php` and the integration suite's endpoint.

## Entrypoint and exclusions

- **`index.php`** serves every service above with `Endpoint::serve()`, which needs the Restate PHP extension loaded (`php -d extension=…`). `E2E_REQUEST_SIGNING` (comma-separated `publickeyv1_…` keys, set by the upstream suite for `ProxyRequestSigning`) becomes the endpoint's identity keys. `just serve` (which builds the extension first) serves it on port 9080; the `upstream-frankenphp` and `upstream-fpm-nginx` images of [`docker/Dockerfile`](../docker/README.md) serve it to the upstream suite.
- **`exclusions.yaml`** lists upstream cases excluded by name. It is empty: the SDK passes every case.

The integration suite does not use `index.php`. Its router, `tests/Integration/Support/endpoint.php`, builds the endpoints of `IntegrationEndpoints` for each request: the services listed in `tests/Integration/Support/services.php` (the "both" rows above plus the integration suite's own), with their configuration, the second endpoint under `/state-preload`, and the fault injector. `RESTATE_PHP_TEST_IDENTITY_KEYS` (comma-separated) sets its identity keys, for `RequestIdentityTest`. A FrankenPHP worker script (`tests/Integration/Support/worker.php`) serves the same endpoints.
