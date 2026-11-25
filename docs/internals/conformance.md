# Conformance

How the SDK is checked against the behaviour of the official Restate SDKs, and what that does and does not claim.

## The Upstream SDK Test Suite

The official SDKs are tested with one shared suite: `sdk-tests.jar` from [`restatedev/e2e`](https://github.com/restatedev/e2e) (the successor of `restatedev/sdk-test-suite`). It starts Restate in containers, registers an SDK's test-service image, and drives the services its contracts specify through every feature of the service protocol, in eight suite configurations (`default`, `alwaysSuspending`, `threeNodes`, `threeNodesAlwaysSuspending`, `singleThreadSinglePartition`, `lazyState`, `lazyStateAlwaysSuspending`, `persistedTimers`).

The PHP SDK runs release **v2.3**, the one the official SDKs run, with its 322 test cases and no exclusions (`test-services/exclusions.yaml` is empty): every case passes. The test services are the PHP implementations of the suite's contracts ([test-services/README.md](../../test-services/README.md)).

- **Locally:** `just test upstream` ([TESTING.md](../../TESTING.md#upstream-sdk-test-suite) has the options, requirements and artifacts). The jar is pinned by SHA-256, the Restate image is `RestateContainer::DEFAULT_IMAGE` (1.8.1), and the service image is built from the checkout.
- **In CI:** `.github/workflows/upstream.yaml` runs the suite with Restate's own [`restatedev/e2e/sdk-tests@v2.3`](https://github.com/restatedev/e2e/blob/main/sdk-tests/action.yml) action, the way the official SDKs do, against the `upstream-extension-caddy` image (Caddy h2c in front of `php -S`), and `nightly-serving.yaml` against `upstream-fpm-nginx` (php-fpm behind nginx). The workflow fails when its Restate image differs from `RestateContainer::DEFAULT_IMAGE`. Unlike the local run, the action downloads the jar by release tag without a checksum ([TESTING.md](../../TESTING.md#ci)).

The suite needs Restate 1.8's admin API, so it has no 1.7 run; the integration suite with `RESTATE_PHP_RESTATE_IMAGE=docker.restate.dev/restatedev/restate:1.7.14` is the 1.7 check.

The repository's own integration suite (`just test integration php-cli`, [TESTING.md](../../TESTING.md#integration-tests)) covers what the upstream suite does not: the PHP API surface, serving under `php -S`, FrankenPHP and php-fpm, failure injection, and the packages around the SDK. [Status](../status.md#what-works) maps each feature to its tests in both suites.

## Official Conformance

Passing the upstream suite gives project-level confidence. It is **not an official Restate SDK conformance claim**, and the SDK makes none until every gate below is met:

- The Restate maintainers accept the suite and the command as official for PHP.
- The suite version, the Restate image and the PHP test-service image are pinned by immutable reference (the service image by digest) and reproducible.
- CI reproduces the run from a clean checkout and passes.
- Case-level evidence is kept for every test case, not only a pass/fail per command (the JUnit reports of each run).
- The packages are published and the supported platform matrix is documented ([Status](../status.md#supported-platforms), [RELEASING.md](../../RELEASING.md#before-the-first-release)).
- The prebuilt extension binaries (per PHP minor and thread-safety mode) install on the supported operating systems and architectures, with matching release and installation documentation ([Prebuilt Extension Binaries](../decisions/0030-prebuilt-extension-binaries.md)).

Full-duplex `BIDI_STREAM` serving is not among the gates (the upstream suite passes against a request/response deployment), but it is not shipped: discovery advertises `REQUEST_RESPONSE` ([Known Gaps and Limitations](../status.md#known-gaps-and-limitations)).
