# Native Restate for the Test Harness

## Status

Accepted. Amends decision 1 of [Test Harness](0023-test-harness.md): Restate runs in a container or as a process of the harness.

## Context

The harness runs Restate only as a container ([Test Harness](0023-test-harness.md), decision 1). That costs three things:

- **Docker for every integration test.** GitHub's macOS runners have no Docker, so no SDK suite runs on macOS. Only unit tests run there, and since [Extension Flake](0037-extension-flake.md), the extension's Nix checks.
- **The endpoint on the LAN.** Restate reaches the endpoint through `host.docker.internal`, so `php -S` listens on `0.0.0.0` by default (0023, Consequences).
- **A container per run:** an image pull and a container start before the first test.

Two ways to run Restate without a container are at hand.

### devenv-extras' Restate service

[devenv-extras](https://github.com/sagikazarmark/devenv-extras) has `services.restate`. It runs `restate-server` as a devenv process (`devenv up`) with ports devenv allocates. It exports `RESTATE_INGRESS_URL`, `RESTATE_ADMIN_URL` and `RESTATECTL_ADDRESS`, and registers deployments with tasks once Restate is ready. Its CI runs it on Linux x86_64, Linux arm64 and macOS 15. The package should be [nix-restate](https://github.com/sagikazarmark/nix-restate)'s `restate-bin`, the official release binaries: nixpkgs' `restate` is unfree (BUSL) and built from source, which takes long. `restate-bin` is 1.8.1, the version `RestateContainer::DEFAULT_IMAGE` runs.

It is a long-running server for development. The test harness would only connect to it:

- **No lifecycle control.** `restartRestate()`, `pauseRestate()` and `unpauseRestate()` need control over the server's process. `RestateRestartTest`, `NetworkFaultTest` and `HarnessLifecycleTest` use them.
- **No log tails.** `restateLog()` and the log dumps on failure read the container's logs.
- **State across runs.** Its state lives in `.devenv/state/restate`, with 4 partitions. The container starts empty, with 1 partition. Every run would register a new deployment (the endpoint's port changes), and invocations of earlier runs stay.
- **No server settings.** Tests that set `RestateContainer::withEnvironment()` (Restate's configuration) cannot.

### `restate-server` as a process of the harness

The binary runs natively, configured through `RESTATE_*` environment variables as the container is. Probed on macOS (arm64) with `restate-bin` 1.8.1:

```sh
RESTATE_BASE_DIR=<temp dir> RESTATE_LISTEN_MODE=tcp \
RESTATE_BIND_ADDRESS=127.0.0.1:<node> RESTATE_ADVERTISED_ADDRESS=http://127.0.0.1:<node>/ \
RESTATE_INGRESS__BIND_ADDRESS=127.0.0.1:<ingress> RESTATE_ADMIN__BIND_ADDRESS=127.0.0.1:<admin> \
RESTATE_DEFAULT_NUM_PARTITIONS=1 RESTATE_DISABLE_TELEMETRY=true RESTATE_NODE_NAME=<name> \
restate-server --no-logo
```

- The admin and ingress health checks answered in under a second.
- A `sys_invocation` query right after failed ("node lookup for partition 0 failed") until the partition was up, which the harness already waits for.
- `SIGSTOP` made it stop answering and `SIGCONT` brought it back, as the cgroup freezer does for `pauseRestate()`.

`RESTATE_LISTEN_MODE=tcp` matters: Restate otherwise creates Unix sockets under its base directory, whose path easily exceeds macOS's 104-byte limit (devenv-extras sets it for the same reason).

The suite's lifecycle scenarios were replayed against it by hand: the suite's `php -S` endpoint (`tests/Integration/Support/endpoint.php`, PHP 8.5, debug extension), `restate-server` with the harness's settings (one partition, compact logs), and the lifecycle done with signals to its PID. Every result is the one the suite asserts for the container:

| Scenario | Lifecycle | Result |
| --- | --- | --- |
| `RestateRestartTest`, a run in flight | `SIGTERM`, then a start on the same base directory and ports | exit 0 in under a second ("Restate has gracefully shutdown in 93ms", RocksDB flushed); ready again in about 1 s; `{"attempt":3,"execution":2}`; one deployment |
| the same | `SIGKILL`, then a start | `{"attempt":3,"execution":2}`; one deployment |
| `RestateRestartTest`, the object lock and its queue | `SIGTERM` and `SIGKILL` | holder `1`, queued `2` |
| `NetworkFaultTest`, Restate frozen 3 s with a 1 s run in flight | `SIGSTOP`, then `SIGCONT` | `{"attempt":2,"execution":1}` |

So a restart keeps the state (journals, the object's lock and queue, the deployment), and a stopped process behaves as the frozen container does: its connections stay open, the endpoint's response waits in the kernel, and Restate reads it once resumed. This was on macOS only. Linux would keep the container (decision 7).

This is the container's model without the container. Each environment gets a fresh base directory and its own ports, and the harness owns the process: start, stop, `SIGKILL`, pause and resume, and its output as the Restate log.

### Also needed either way

- **Registration.** It uses `http://host.docker.internal:<port>`. A native Restate reaches the endpoint on `127.0.0.1`, so `php -S` can listen there.
- **Process handling on macOS.** `Internal\Process` finds a `php -S` server's workers through `/proc/<pid>/task/*/children`, which only Linux has: on macOS they would outlive the server and keep its port. `pgrep -P <pid>` works on both. Implementing it showed more: on Linux, php -S sets `PR_SET_PDEATHSIG` on its workers, so they die with their server whatever ends it; macOS has nothing like it. Whenever the harness ends a `php -S` server (kill, stop, or a signal it dies of), it ends the workers it saw too: SIGKILL with a kill, SIGTERM (then SIGKILL after the grace period) otherwise, as the kernel's SIGTERM does on Linux.
- **Endpoint containers.** A `php -S` endpoint is a process like Restate. An `EndpointContainer` (the repository's FrankenPHP and php-fpm suites) publishes its port on the host, where a native Restate reaches it on `127.0.0.1` too.

## Decision

1. **A second kind of Restate in `restate/testing`: `RestateProcess`**, which runs `restate-server` as a process of the harness.
   - `RestateProcess::default()` takes `restate-server` from `PATH` (`withBinary()` names another).
   - `withEnvironment()` (Restate's configuration, over the same defaults as the container: one partition, compact logs without ANSI codes) and `withStartupTimeout()`. `withEnvironment()` refuses what the harness sets itself (`RestateProcess::HARNESS_VARIABLES`: the data directory, node name, listen mode and addresses), as `withRunArguments()` refuses `--publish`.
   - Like a container, the process sees only the configuration the harness gives it: none of the test process's `RESTATE_…` variables, which `restate-server` would read as configuration (unknown ones are ignored; a `RESTATE_LOG_FILTER` in the shell would silently change it).
   - `RestateTestEnvironment::start()` and `shared()`, and `InteractsWithRestate::restateContainer()`, accept `RestateContainer|RestateProcess`.
   - Its base directory is a temporary directory, removed by `stop()`. It listens on three free loopback ports, picked and retried as the container's are. Its output is the Restate log.
   - The lifecycle controls map to signals: `restartRestate()` is `SIGTERM` (or `SIGKILL`), then a start on the same base directory and ports; `pauseRestate()` and `unpauseRestate()` are `SIGSTOP` and `SIGCONT`. Every test runs against either kind.
   - `restateContainerName()` and `containerRuntime()` throw when there is no container (no Restate container, and a `php -S` endpoint). Nothing needs Docker then. An `EndpointContainer` with a Restate process takes `docker`, else `podman`, from `PATH`.
   - `testHostForRestate()` is the test host as Restate reaches it, `host.docker.internal` or `127.0.0.1`, for `registerDeployment()` URIs of servers on the test host (the suite's fault proxy).
2. **Restate reaches the endpoint on loopback when Restate is a process.** `registerEndpoint()` uses `127.0.0.1`, and `php -S` listens on `127.0.0.1` unless `withBindAddress()` or `RESTATE_PHP_TEST_BIND` says otherwise. The LAN-exposure consequence of 0023 then applies to the container only.
3. **Chosen in code, and by `RESTATE_PHP_TEST_RESTATE` in this repository's suite.** The harness never switches by itself. In particular it does not switch because `RESTATE_ADMIN_URL` is set: devenv-extras exports it in every shell that enables the service, and a test that restarts Restate would then fail in a way unrelated to the test. `IntegrationRestate` reads `RESTATE_PHP_TEST_RESTATE` (`container`, the default, or `process`), as `IntegrationEndpoint` reads `RESTATE_PHP_TEST_SERVER`, and `IntegrationTestCase` takes its Restate from it. The tests that build their own Restate go through the same factory, which builds either kind:
   - `RequestIdentityTest` and `ClockSkewTest` mount a request identity key as a volume. A process reads it from its host path instead.
   - `WideStateTestCase` sets scale configuration through `withEnvironment()`, which works for both kinds.
4. **The dev shell provides `restate-server`:** nix-restate's `restate-bin` (an input, with its nixpkgs following the extension flake's), in `packages`. CI checks that its version is `RestateContainer::DEFAULT_IMAGE`'s, as it checks the workflows' images.
5. **devenv-extras' service for development, not for the suites.** `services.restate` (with `restate-bin`) can give `devenv up` a Restate for `composer serve`, with the test services registered by a deployment task; this repository does not set it up yet. Enabling it also puts `restate-server` on the shell's `PATH`, so `RestateProcess::default()` works in that shell as it is.
6. **No connection to a running Restate, for now.** A Restate the harness does not own has no restart, pause or log tail, keeps the state of earlier runs, and collects a deployment per run (the endpoint's port changes). For devenv users, `RestateProcess` already covers testing without a container. Connecting (`RestateTestEnvironment` against given URLs) would be an addition that breaks nothing, so it can wait until someone needs it, such as a Restate already provided as a CI service.
7. **CI: a macOS job** (`integration-macos` in `.github/workflows/ci.yaml`) runs the `php -S` integration suite with `RESTATE_PHP_TEST_RESTATE=process` (`composer test:integration:process`), in the dev shell. It runs `tests/Integration`: `examples/testing` shows a user the default, a container, and stays that way. The Linux jobs keep the container: it is what users of `restate/testing` get by default, and running the same suite twice on Linux would only test Restate's packaging. The FrankenPHP, php-fpm, upstream, soak and load suites stay on Docker and Linux.

### Rejected

- **Connecting the suite to devenv-extras' service.** That means no fault-injection tests, no log tails, and state shared across runs. The process kind has none of these problems and needs only the binary devenv provides.
- **Switching on the environment** (`RESTATE_ADMIN_URL`): see decision 3.
- **Running Restate as a process in the extension flake's checks:** the flake has neither the SDK nor Composer's dependencies.

## Consequences

- The `php -S` integration suite, fault injection included, runs without Docker: on macOS, in CI and for contributors, and for users who have `restate-server` but no container runtime.
- `restate/testing` maintains a second Restate kind: its process handling (already there for `php -S`), port allocation and configuration. Its tests must cover both kinds.
- Two Restate pins, the image tag and `restate-bin`, kept equal by a check.
- `restate-server` is BUSL and unfree in nixpkgs. The dev shell takes it from nix-restate (release binaries, its own binary cache at restate.cachix.org), not nixpkgs.
