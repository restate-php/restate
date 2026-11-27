# Docker Images for the Test Suites

> [!NOTE]
> These images are test infrastructure, not images to deploy. To run a service of your own in Docker, start from the [Docker quickstart](../examples/quickstart/README.md) and the [serving guide](../docs/serving.md).

`docker/` holds the images the test suites serve PHP endpoints with, and the server configuration baked into them or read from the mounted repository. The endpoints they serve live elsewhere: the conformance suite's [test services](../test-services/README.md), and the router scripts of the integration, load and soak suites under `tests/`. How the suites run is in [TESTING.md](../TESTING.md).

Every image builds from the repository root as the build context, through [`docker-bake.hcl`](../docker-bake.hcl): `bin/build-image.sh [--php <minor>] [--variant <variant>] <target> <tag>` builds one and loads it, and the recipes and workflows run it. The root `.dockerignore` applies.

## Images

One Dockerfile per server, in its directory, next to its configuration. None of them compiles the extension ([Extension Modules in the Images](../docs/decisions/0044-extension-modules-in-the-images.md)). Each target copies the module of its PHP from the `restate-extension` context into its PHP's `extension_dir`, and checks that it loads; bake sets the context for each image: the extension flake's portable build of that PHP minor, thread safety and variant (`debug`, `release`, and for FrankenPHP the soak suite's `debug-diagnostics` and `release-diagnostics`), built by [`extension/Dockerfile`](../extension/Dockerfile) or, with `RESTATE_PHP_IMAGE_EXTENSION=host` as in CI, by Nix on the host. Each Dockerfile's bases are stages of their own, one per PHP minor (`frankenphp-8.4`, `php-fpm-8.4`, `php-cli-8.4`, …), so that Dependabot updates them; `BASE_IMAGE` names the one a target builds on. Bake targets are `<target>-<minor without the dot>-<variant>`, such as `frankenphp-extension-84-debug`. The FrankenPHP and php-fpm images build on Debian, or on Alpine with the musl module when bake's `LIBC` is `musl` (`bin/build-image.sh --libc musl`, `RESTATE_PHP_TEST_LIBC=musl` for the recipes): their `-alpine` base stages (`frankenphp-8.4-alpine`, `php-fpm-8.4-alpine`, …).

| Dockerfile | Target | Serves | Built by |
| --- | --- | --- | --- |
| `frankenphp/` | `frankenphp-extension` | the integration router, FrankenPHP classic mode (repository mounted at `/app`); the worker-mode and soak runs start it with `worker.Caddyfile`, and the load tests mount their own Caddyfile | `just test integration frankenphp`, `just test integration frankenphp-worker`; `just load run` (release build); `just test soak` (the `diagnostics` builds, release and debug) |
| | `conformance-frankenphp`, `conformance-frankenphp-worker` | `test-services/index.php` in FrankenPHP classic mode, or `test-services/worker.php` in worker mode, over h2c (the repository baked in; `frankenphp/conformance.Caddyfile`, `conformance-worker.Caddyfile`) | `just test conformance [frankenphp-worker]`, `runtime.yaml` |
| `fpm/` | `fpm-extension`, `fpm-extension-apache` | any router script under php-fpm behind nginx, Caddy or Apache | `just test integration <fpm server>`, `just test serving-hazards` |
| | `conformance-fpm-nginx`, `conformance-fpm-caddy` | `test-services/index.php` under php-fpm behind nginx or Caddy (the repository baked in) | `just test conformance fpm-nginx`, `fpm-caddy`, `runtime.yaml` |
| `quickstart/` | `quickstart-php-restate` | nothing: the PHP base of [`examples/quickstart`](../examples/quickstart/README.md) with the extension and packages from this checkout | `just test quickstart local`, `quickstart.yaml` |

## Configuration

- **`fpm/`**: the php-fpm images. `entrypoint.sh` renders the `*.template` files from `RESTATE_PHP_FPM_*` variables and runs php-fpm behind nginx, Caddy `php_fastcgi` or Apache `mod_proxy_fcgi`, with the settings [docs/serving.md](../docs/serving.md#php-fpm-and-fastcgi) recommends or the front door's defaults ([TESTING.md](../TESTING.md#serving-large-bodies-php-fpm-and-front-door-hazards)). `php.ini` goes on top of `php.ini-production`.
- **`frankenphp/`**: the FrankenPHP Caddyfiles of the integration suite (`integration`, `worker`) and of `conformance-frankenphp` (`conformance`, baked in). The load tests' Caddyfile and PHP settings are in `tests/load/`, which mounts them. `integration.Caddyfile` is baked into `frankenphp-extension`; `worker.Caddyfile` is read from the mounted repository at run time, so changing it needs no rebuild.
