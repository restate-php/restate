# Test Matrix

## Status

Accepted. Supersedes decisions 2 to 5 of [External Test Environments](0039-external-test-environments.md) (the `managed` group, the suite's external mode, the devenv profiles and their CI job; decision 1, `ExternalRestate` and `ExternalEndpoint`, stands), decision 6 of [Image Builds and CI Caches](0042-image-builds-and-ci-caches.md) (the reusable conformance workflow) and the release's test jobs in [Release](0031-release.md). Restate 1.8 becomes the minimum server ([Shared Core](0014-shared-core.md)).

## Context

Every pull request ran 25 CI jobs, the conformance suite once and the extension's 7 jobs. Measured on one run:

- The whole integration suite (47 classes) ran on six runtimes (php -S, FrankenPHP classic and worker, php-fpm behind nginx over HTTP/1.1 and h2c, and behind Caddy), all on PHP 8.4 but FrankenPHP's three minors, all on x86_64 with glibc, all with the debug build.
- The `external` job ran the suite without its 18 `managed` classes against FrankenPHP and Restate that devenv ran, for two profiles, three PHP minors and two OSes: 12 jobs of 2 to 28 minutes, most of a pull request's wall-clock time. Its FrankenPHP is Nix's, which nobody deploys; the official images, which users run, had jobs of their own.
- Restate 1.7.14 ran the php -S suite as the "best-effort" older server.
- The conformance suite (322 cases in eight configurations, 4 minutes) ran once, on FrankenPHP classic mode.
- Nothing ran on arm64 or on musl beyond loading the extension archives and running `extension/tests/*.phpt`; nothing ran the release build beyond that, the soak suite (with diagnostics) and the load tests. NTS PHP 8.5 and 8.6 ran unit tests only.

Most of the integration suite tests the SDK's logic (objects, futures, workflows, timers, state), which does not depend on the runtime. What does is a minority: the serving path (request bodies, headers, h2c and HTTP/1.1, the front door's buffering), output and error display, the process and worker lifecycle (kills, exits, time and memory limits, worker isolation), identity headers through FastCGI. The conformance suite exercises every protocol feature through the real serving path and is the densest feature coverage there is.

## Decision

1. **Two questions, tested differently.** Whether the SDK's logic is right does not depend on the runtime, so it runs once, as fully as possible. Whether it behaves the same everywhere needs every runtime and platform, with a broad but cheap probe: the conformance suite, and the integration tests whose behaviour depends on the runtime.
2. **The `runtime` group** marks those integration tests: per class (PHPUnit 13 refuses group attributes on parent classes), or per method where only one test of a class is about the runtime. `just test integration <server> --group runtime` runs them; the groups `phpunit.xml.dist` excludes stay excluded.
3. **A runtime cell** (`.github/workflows/runtime.yaml`) is one job: the integration suite on the cell's servers (its group, or the whole suite), then the conformance suite on the cell's conformance image, on the cell's PHP minor, C library and CPU. The integration suite runs the debug build of the extension, whose assertions catch more; the conformance suite runs the release build, the one PIE ships (the images take the same Nix derivation as the archives). Each runtime has a conformance image: `conformance-frankenphp`, `conformance-frankenphp-worker` (the test services in a worker script, `test-services/worker.php`), `conformance-fpm-nginx`, `conformance-fpm-caddy`. The FrankenPHP and php-fpm images build on Debian or, with `LIBC=musl` (`bin/build-image.sh --libc musl`), on Alpine with the musl module.
4. **Every pull request and push to `main`** (`ci.yaml`):
   - `just check` on PHP 8.4, 8.5 and 8.6;
   - the whole integration suite under php -S (PHP 8.4, Linux x86_64, Restate in a container): the SDK's logic;
   - the whole integration suite on macOS (Restate as a process), the development platform without Docker;
   - four runtime cells, `runtime` group plus conformance, with the PHP minors, C libraries and CPUs spread across the runtimes rather than crossed:

     | Runtime | PHP | Platform |
     | --- | --- | --- |
     | FrankenPHP classic | 8.6 (ZTS) | arm64 |
     | FrankenPHP worker | 8.5 (ZTS) | x86_64 |
     | nginx + php-fpm, HTTP/1.1 and h2c | 8.4 (NTS) | x86_64, Alpine |
     | Caddy + php-fpm | 8.5 (NTS) | arm64 |

   - the extension's archives, their loading into PHP's official images, and the flake's checks (`extension.yaml`).
5. **Nightly** (`nightly-runtime.yaml`): every runtime on every PHP minor (Debian, x86_64) with the whole integration suite and conformance, and every runtime on arm64 and on Alpine (newest PHP) with the `runtime` group and conformance: 20 cells. The other nightly workflows (failure injection, large bodies and front-door hazards, scale, soak) and the quickstart stay as they are.
6. **Restate versions are an axis of the cells** (`restate`, an image; empty is `RestateContainer::DEFAULT_IMAGE`). Today every cell runs the pinned version, 1.8.1, which is also the minimum supported: Restate 1.8 is required, and the SDK advertises service protocol v7 as its minimum: Restate 1.7.14 still registers the deployment, then fails every invocation with `RT0013` (the protocol versions are incompatible). When a newer Restate is pinned, the minimum supported version goes back into the nightly cells.
7. **No external environment in the suite.** The `external` job, the devenv profiles `frankenphp` and `frankenphp-worker`, `RESTATE_PHP_TEST_RESTATE=external` and the `managed` group are gone. `ExternalRestate` and `ExternalEndpoint` stay public API; `ExternalEnvironmentTest` runs them against the shared environment's real Restate and endpoint. The `load` profile, which serves the load endpoint without Docker, stays.
8. **A release runs no tests.** Test results are on `main`, and whether to release despite a failure is the person releasing's call. `release.yaml` lists the release commit's check runs in its summary, and builds and verifies the archives it publishes (`extension.yaml` without the flake's checks): those checks are of the artifact, not regression tests, and a failure would publish a broken binary.
9. **CI runs the conformance suite as developers do**: `just test conformance` (`bin/conformance-local.sh`), the jar pinned by SHA-256, instead of Restate's `restatedev/e2e/sdk-tests` action, which downloads it by tag and serves one image per workflow. A failed cell uploads the suite's reports.

## Consequences

- A pull request runs 3 + 2 + 4 test jobs and the extension's jobs instead of 25 + 1 + 7, each a few minutes; nothing waits for a FrankenPHP built from source.
- Every runtime, PHP minor, thread safety, CPU and C library is tested on every pull request, each at least once; their combinations nightly. A failure specific to one combination can reach `main` and show up there the next night.
- The SDK's logic runs once per pull request on Linux (and once on macOS). A logic test that depends on the runtime without being in the `runtime` group runs on every runtime only nightly; a new test that is about the runtime needs the group.
- The release build is tested at runtime on every pull request, through conformance.
- Restate 1.7 users must upgrade: every invocation fails with Restate's own `RT0013` about the protocol range. The code, tests and docs that existed for 1.7 (the core's below-v7 refusals and their tests, the suite's version branches) are gone.
- The devenv profiles no longer give a contributor a FrankenPHP environment with `devenv up`; the images do, with Docker.
- Conformance results are no longer published as a check run with a pull request comment; a failed cell's log has the suite's table, and its artifact the JUnit reports.
