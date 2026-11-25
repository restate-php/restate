# External Test Environments

## Status

Accepted. Amends decisions 5 and 6 of [Native Restate for the Test Harness](0038-native-restate-for-the-test-harness.md): the harness can connect to a Restate and an endpoint it does not run, and the repository uses devenv-extras' services for a part of the integration suite.

## Context

The integration suite covers each server it supports with a job of its own: the default `php -S`, FrankenPHP (classic and worker mode) and php-fpm behind nginx or Caddy, in images built from `docker/Dockerfile`. Each server image pins its PHP, so covering another PHP minor means another base image. FrankenPHP is tested on PHP 8.4, 8.5 and 8.6, and worker mode and php-fpm on 8.4 only. GitHub's macOS runners have no Docker, so macOS only runs `php -S` ([0038](0038-native-restate-for-the-test-harness.md)).

devenv-extras now has `languages.php.frankenphp`: FrankenPHP built from the shell's PHP (`languages.php.version`), with the shell's extensions rebuilt for its thread-safe PHP, served by `devenv up` from a Caddyfile. With its `services.restate`, a devenv profile can run the whole environment, the endpoint and Restate, for any PHP minor, on Linux and macOS, without a container. devenv's [profiles](https://devenv.sh/profiles/) select one, and `devenv --profile <name> test` runs the processes and then the tests.

0038 rejected connecting the suite to a devenv Restate: such a Restate has no restart, pause or log tail, keeps the state of earlier runs, and takes no configuration from the tests. All of that is just as true for an endpoint devenv runs. But most of the suite needs none of it. Of the 53 integration test classes, 35 only send invocations through the ingress and read Restate's admin API: the SDK's behaviour (services, objects, workflows, timers, retries, state, awakeables, serving). 18 need control of their environment:

- the fault-injection and failure tests (`FaultInjectionTestCase`, `ProxiedTestCase`): killed workers, a restarted or frozen Restate, graceful shutdown, php-fpm's drain, OOM, truncated responses;
- tests that start an environment of their own with other settings: request identity keys (`RequestIdentityTest`, `ClockSkewTest`), the serving hazards' front doors, the wide-state scale tests' Restate configuration, the worker suite's single worker thread.

The behaviour tests are the ones worth running on every server, PHP minor and OS.

## Decision

1. **`restate/testing` connects to an environment it does not run: `ExternalRestate` and `ExternalEndpoint`.**
   - `ExternalRestate::at($adminUrl, $ingressUrl)`, or `ExternalRestate::fromEnvironment()`, which reads `RESTATE_ADMIN_URL` and `RESTATE_INGRESS_URL` (devenv-extras exports them, as the `restate` CLI reads them). `withTestHost()` is where Restate reaches the test host (`127.0.0.1` by default), for an endpoint the harness serves next to it. `withLogFile()` names a file for the Restate log tails.
   - `ExternalEndpoint::at($url)`: the endpoint as the tests reach it, registered with Restate at the same URL unless `withRestateUri()` says otherwise, over HTTP/1.1 unless `withHttp2()`. `withLogFile()` is read as the endpoint log.
   - `RestateTestEnvironment::start()` and `shared()`, and the PHPUnit trait, accept either in place of the kinds the harness runs, in any combination. The harness waits until they are ready and registers the endpoint (forced, as always). It refuses every lifecycle call for them with a `TestEnvironmentException` (`restartRestate()`, `pauseRestate()`, `unpauseRestate()`, `killEndpoint()`, `startEndpoint()`, `restartEndpoint()`, `signalEndpoint()`), and `stop()` leaves them running.
   - Choosing them is code, as in 0038 decision 3: the harness never connects to a running Restate on its own.
2. **The suite's tests that need a managed environment are in the PHPUnit group `managed`.** The group is on each class: PHPUnit 13 deprecates group attributes on parent classes, and the suite fails on deprecations. The suite's factories (`IntegrationRestate`, `IntegrationEndpoint`) throw in external mode when a test asks for something only a managed environment can give: Restate configuration, an endpoint with other settings, the fault injector, a `php -S` or php-fpm endpoint of its own. The message names the group. A test that needs control but lacks the group therefore fails in the external run instead of skipping. The harness's own refusals (1) catch a test that calls a lifecycle method directly.
3. **External mode in the suite: `RESTATE_PHP_TEST_RESTATE=external`.** Restate is `ExternalRestate::fromEnvironment()` and the endpoint is `ExternalEndpoint::at(RESTATE_PHP_TEST_ENDPOINT_URL)`, over h2c for the servers that are registered that way (`nginx-fpm-h2c`, `caddy-fpm`). `RESTATE_PHP_TEST_ENDPOINT_SERVER` says what serves it, and stands in for `RESTATE_PHP_TEST_SERVER` in the tests' server-specific branches. `RESTATE_PHP_TEST_STATE_DIR` is the scratch directory the endpoint shares with the tests, which the suite then neither creates nor removes. One switch covers both sides: an external endpoint with a Restate the harness runs (or the reverse) works in the harness, but the suite has no use for it. `RESTATE_PHP_TEST_RESTATE=external just test integration <server>` runs `tests/Integration` without the `managed` group and the groups the `integration` suite leaves out. `examples/testing` stays out: it shows a user the default, a container.
4. **devenv profiles run the environment: `frankenphp` and `frankenphp-worker`** (`devenv.nix`). Each one does four things:
   - It serves the integration endpoint with devenv-extras' FrankenPHP, from `docker/frankenphp/integration.Caddyfile` or `worker.Caddyfile`. The image and the profile share these files: the repository directory and the site address are Caddyfile environment placeholders (`RESTATE_PHP_TEST_APP_DIR`, `RESTATE_PHP_TEST_LISTEN`), defaulting to the image's `/app` and `:8080`.
   - It runs `services.restate` with nix-restate's `restate-bin`, registering the endpoint too, so that `devenv --profile frankenphp up` on its own gives a usable environment.
   - It exports the variables of decision 3, and creates the state directory before the endpoint starts.
   - Its `enterTest` is `RESTATE_PHP_TEST_RESTATE=external just test integration <server>`, with the profile's server. For `frankenphp-worker` the recipe adds the worker suite (`tests/Worker`), whose `WorkerIsolationTest` is `managed`.

   FrankenPHP is `pkgs.frankenphp` built from the shell's PHP with the overlay's `restate` extension (`withExtensions … all.restate`). FrankenPHP rebuilds that PHP thread-safe, and the extension with it. The shell's own PHP does not load the extension: the harness and the unit tests load the cargo build, as before. The PHP minor comes from the shell, as for `just check`: `devenv --profile frankenphp -O languages.php.version:string 8.4 test`.
5. **CI: a matrix job, `external`** (`.github/workflows/ci.yaml`), runs `devenv --profile <profile> -O languages.php.version:string <php> test` for each profile and PHP minor, on Linux and macOS. On macOS, FrankenPHP's PHP 8.6 goes without the tokenizer extension. nixpkgs builds it separately, and on Darwin it fails to link against PHP 8.6's scanner globals, which are now a native thread-local variable. The endpoint does not tokenize PHP. The jobs that build the images keep running every test, the `managed` group included, against the servers users deploy: the official FrankenPHP and php-fpm images with the extension built the way users build it.

### Rejected

- **Selecting the external tests by directory or a test suite of their own.** Moving 18 test classes would be churn, and the next test added to the wrong place would run nowhere. A group can be checked: a test without it that needs control fails (decision 2).
- **Skipping the managed tests in external mode** (`markTestSkipped`). A green run would then not say what it covered, and a misconfigured job would pass by skipping. The worker suite already fails rather than skips against the wrong server.
- **Driving the lifecycle through devenv** (`devenv processes restart`, signals to the PIDs it reports). Restarting a process is possible, but freezing Restate (SIGSTOP), signalling php-fpm's master, and starting an endpoint with per-test settings are not. That would be a second, partial lifecycle implementation beside the harness's.
- **Native processes as harness kinds** (an endpoint like `RestateProcess` that runs `frankenphp` or `php-fpm` from `PATH`). This would give macOS the managed tests too, but it means a process kind per server and its configuration in PHP, and it duplicates what devenv-extras already does. It remains possible later, and nothing here stands in its way.
- **Loading the extension into the shell's PHP** (`languages.php.extensions = [ "restate" ]`), which devenv-extras' FrankenPHP would also rebuild for its PHP. The harness, the unit tests and the extension suite load the cargo build with `-d extension=…`, and PHP refuses to load a module twice.

## Consequences

- The behaviour tests run on FrankenPHP in classic and worker mode, for each PHP minor, on Linux and on macOS, without Docker. The same profile gives a developer the environment with `devenv up`.
- The FrankenPHP and PHP used there are Nix's, not the official images'. The image jobs stay, so both builds are tested.
- `restate/testing` has two more public classes and a lifecycle that may refuse. A user with a Restate already running in CI (a service container) can use them too.
- A new test that needs control of its environment must be marked `managed`, or it fails in the external jobs, which says so.
- An external Restate keeps its state for as long as it runs, and the suite's tests use fixed keys (`Counter/alice`, workflow ids) that assume an empty Restate. So the profiles empty Restate's data and the endpoint's state directory whenever their processes start (`devenv up`, `devenv test`), not when a shell is entered: the suite runs once per start of the environment.
- Each profile and PHP minor builds FrankenPHP and a thread-safe PHP with the extension in Nix. Cachix keeps them, so a job builds again only when the extension or the PHP changes.
- On macOS, `max_execution_time` never fires in FrankenPHP: a thread-safe PHP there arms it with a process-wide `ITIMER_REAL`, not a per-thread timer. `ChaosServicesTest` asserts that behaviour there, and the time limit on Linux.
