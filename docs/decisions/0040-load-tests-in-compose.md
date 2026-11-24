# Load Tests in Compose

## Status

Accepted. Amends decision 6 of [Performance Benchmarks](0033-performance-benchmarks.md): the load tests no longer compare a run with earlier ones or gate on it. They are a compose project and a k6 script, with no PHP runner.

## Context

The load tests had about 1,200 lines of PHP around k6: `tests/Load/run.php`, `LoadRunner`, `LoadScenario` and `LoadHistory`, with a unit test. They did three things:

- **The environment.** Through the `restate/testing` harness, they started a fresh Restate container with a new signing key, then the endpoint container with the matching identity key, and registered it. This was done for every scenario.
- **The load.** A k6 warm-up, then the phases. In between, they waited until nothing was in flight. During `overload`, they polled the in-flight invocations.
- **The analysis.** They took CPU from the containers' cgroups, and read attempts, 401s and SDK time from the endpoint's request log, duplicated runs from its effects ledger, and retries and timestamps from `sys_invocation`. `LoadHistory` compared each result with the median of the last 5 comparable runs and a pinned baseline, and `--gate` failed the run on a regression.

Only the endpoint is what is under test. k6 needs nothing but Restate's ingress. The harness was reused because it existed, not because the load tests needed PHP.

The gate never gated. It needs the dedicated `restate-php-load` runner, which is not registered. Quick re-runs on one machine moved CPU per invocation by up to 27%, while the regression threshold was 10%. The micro-benchmarks' instruction counts gate pull requests instead ([0033](0033-performance-benchmarks.md), decision 5). The load tests are worth more for finding costs at the system level, and for their checks under load: 401s, attempt counts and duplicated runs. Those checks pass or fail outright, whatever the noise.

## Decision

1. **The environment is a compose project on fixed loopback ports:** `tests/load/compose.yaml`.
   - **Restate** listens on 18080 (ingress) and 19070 (admin). Its image is `RestateContainer::DEFAULT_IMAGE`, which a unit test checks. It has 4 partitions.
   - **The endpoint** listens on 18081. It is the `frankenphp-extension-release` image, which the project builds: FrankenPHP with the release ZTS extension and nothing else. The project mounts the repository at `/app`, and `tests/load/Caddyfile` and `php.ini` as its configuration.
   - Every port can be overridden. A plain `docker compose up` gives a usable environment, and the dev shell's k6 reaches it from the host. Restate reaches the endpoint as `http://endpoint:8080`.
2. **The signing key is a fixed test key:** `tests/load/request-identity.pem` for Restate, and its public key in `request-identity.pub`, which `endpoint.php` reads. Restate and the endpoint only ever run on loopback ports, here or in devenv's `load` profile (7).
3. **The endpoint counts for itself: `/load-stats`** (`tests/load/endpoint.php`). `POST` starts counting. `GET` answers what happened since:
   - its CPU time, user and system, from `getrusage()`: FrankenPHP is one process, its PHP threads included, so this is what the container's cgroup counts too, and it works outside a container;
   - the 401s and the attempts per service, from its request log;
   - the duplicated runs, from its effects ledger.

   The logs stay in the container's `/tmp`. An endpoint in another language serves the same services and the same two calls.
4. **A run is a k6 script:** `tests/load/k6.js`, with the scenarios in `tests/load/scenarios.json`.
   - **`setup()`:** waits for both services, registers the endpoint, warms up, waits until nothing is in flight, and starts the counts.
   - **The load:** one constant-arrival-rate executor per phase. For `overload`, a second scenario polls the in-flight invocations, then records their peak and the seconds until they are back to the nominal level.
   - **`teardown()`:** waits until nothing is in flight again, reads `/load-stats` and the run's `sys_invocation` rows, and records metrics. The rows are those of the scenario's service, created after `setup()` by Restate's clock. The metrics are endpoint CPU per invocation, which is what the run is for, plus attempts, 401s and duplicated runs.
   - **The checks are k6 thresholds**, so k6 exits 99 when one fails:
     - any 401;
     - attempts other than the scenario's;
     - no completed invocation;
     - outside `overload`, more than 0.1% failed calls or more than 1% dropped arrivals.
   - **The result** is k6's metrics as JSON.
5. **`bin/load-test.sh`** is the small wrapper around it. It builds the endpoint image. For each scenario, it applies the endpoint limits the scenario asks for (compose recreates the endpoint when they change), then runs k6. It loops over `all`. At the end it removes the project, unless `--keep` is given.
6. **No comparison, no repetitions.** A run is one window per scenario. Nothing compares it with earlier runs, and there is no `--gate` or baselines. To repeat a scenario, run it again.
7. **The same environment without Docker: devenv's `load` profile.** It runs Restate (`restate-bin`, with the same key and partitions) and the endpoint (FrankenPHP built with the Nix extension for the shell's PHP, with the same Caddyfile and `php.ini`) as devenv processes on loopback. Its shell exports the URLs k6 reads, including `LOAD_ENDPOINT_URI` for the registration. `devenv --profile load test` runs every scenario but `overload`, which needs a container's CPU cap. The Caddyfile takes the repository and the address from the same placeholders as the integration Caddyfiles (`RESTATE_PHP_TEST_APP_DIR`, `RESTATE_PHP_TEST_LISTEN`).

### Rejected

- **Keeping the PHP runner on top of compose**, connecting to the project with the harness's `ExternalRestate` and `ExternalEndpoint`. That keeps the size and the PHP dependency of what is generic glue around k6.
- **The analysis in shell** (curl, jq, awk), with k6 only generating load. It was tried, and was about 400 lines, then about 220 after trimming. That was mostly byte offsets, parsing and assembling the result, which k6's own setup, teardown and thresholds already provide.
- **A k6 extension for Restate.** k6's `http` already reaches the admin API. An extension means a custom k6 binary built with xk6, and Go code to maintain.
- **k6 in a container.** It would reach the services by name, but it pins another image, while fixed loopback ports let the dev shell's k6 do the same.
- **Restate in compose with a native endpoint.** Restate in a container reaches the host as `host.docker.internal`, and on Linux only an endpoint that listens beyond loopback. With both as devenv processes, everything stays on loopback.
- **The container's cgroup `cpu.stat` for the CPU time.** Outside a container, `/sys/fs/cgroup/cpu.stat` is the whole system's on Linux, and there is none on macOS. `getrusage()` counts the same process either way.
- **A fresh Restate per scenario**, as before. Nothing in the measurement needs it. Each figure is counted from `setup()` on, or filtered to the run's invocations.

## Consequences

- Outside the endpoint, which is PHP because the PHP SDK is what is under test, nothing in the load tests is PHP. The scenarios run against any endpoint that serves the services of `tests/load/services.php` and `/load-stats`.
- A result says less than before: CPU per invocation, the checks, and `overload`'s recovery. Restate's CPU, latency percentiles, the Restate vs SDK time split, `memory.peak` and the endpoint's PHP settings are gone. Latency mostly measures Restate and the round trips. k6's summary still has its HTTP timings.
- Comparing runs is by hand, from the result files, until there is a runner stable enough for a gate. A gate then needs designing again.
- The fixed ports can collide with something else on the machine. They are overridable for compose and k6 alike.
- The Restate pin is in two places, `RestateContainer` and `compose.yaml`, and a unit test keeps them equal.
- Restate's data grows over a long-lived project (`--keep`): journals are kept for an hour. It moves Restate's own costs, not the endpoint's CPU. `bin/load-test.sh down` clears it.
