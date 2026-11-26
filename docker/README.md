# Docker Images for the Test Suites

> [!NOTE]
> These images are test infrastructure, not images to deploy. To run a service of your own in Docker, start from the [Docker quickstart](../examples/quickstart/README.md) and the [serving guide](../docs/serving.md).

`docker/` holds the images the test suites serve PHP endpoints with, and the server configuration baked into them or read from the mounted repository. The endpoints they serve live elsewhere: the upstream suite's [test services](../test-services/README.md), and the router scripts of the integration, load and soak suites under `tests/`. How the suites run is in [TESTING.md](../TESTING.md).

Every image builds from the repository root as the build context (`docker build -f docker/Dockerfile --target <target> .`), so the root `.dockerignore` applies.

## Dockerfile

One file, so the targets share the stages that compile the extension inside each PHP (NTS, ZTS, php-fpm; `extension/build.sh`). The build arguments `EXTENSION_PROFILE` (debug or release) and `EXTENSION_FEATURES` choose the extension build of any target, and `PHP_IMAGE`, `FRANKENPHP_IMAGE` and `PHP_FPM_IMAGE` replace the default bases ([Image Builds and CI Caches](../docs/decisions/0042-image-builds-and-ci-caches.md)).

| Target | Serves | Built by |
| --- | --- | --- |
| `upstream-extension-caddy` | `test-services/index.php` under `php -S`, behind Caddy over h2c (the repository baked in) | `just test upstream`, `upstream.yaml` |
| `upstream-fpm-nginx` | `test-services/index.php` under php-fpm behind nginx (the repository baked in) | `just test upstream upstream-fpm-nginx`, `nightly-serving.yaml` |
| `frankenphp-extension` | the integration router, FrankenPHP classic mode (repository mounted at `/app`); the worker-mode and soak runs start it with `worker.Caddyfile`, and the load tests mount their own Caddyfile | `just test integration frankenphp`, `just test integration frankenphp-worker`; `just load run` (release build); `just test soak` (the `diagnostics` build, release and debug) |
| `fpm-extension`, `fpm-extension-apache` | any router script under php-fpm behind nginx, Caddy or Apache | `just test integration <fpm server>`, `just test serving-hazards` |
| `quickstart-php-restate` | nothing: the PHP base of [`examples/quickstart`](../examples/quickstart/README.md) with the extension and packages from this checkout | `just test quickstart local`, `quickstart.yaml` |

## Configuration

- **`extension/`**: `build.sh`, the extension build of the Dockerfile's `extension-bridge*` stages.
- **`caddy/`**: the Caddyfile and entrypoint of `upstream-extension-caddy` (Caddy `reverse_proxy` over h2c in front of `php -S`).
- **`fpm/`**: the php-fpm images. `entrypoint.sh` renders the `*.template` files from `RESTATE_PHP_FPM_*` variables and runs php-fpm behind nginx, Caddy `php_fastcgi` or Apache `mod_proxy_fcgi`, with the settings [docs/serving.md](../docs/serving.md#php-fpm-and-fastcgi) recommends or the front door's defaults ([TESTING.md](../TESTING.md#serving-large-bodies-php-fpm-and-front-door-hazards)). `php.ini` goes on top of `php.ini-production`.
- **`frankenphp/`**: the FrankenPHP Caddyfiles of the integration suite (`integration`, `worker`). The load tests' Caddyfile and PHP settings are in `tests/load/`, which mounts them. `integration.Caddyfile` is baked into `frankenphp-extension`; `worker.Caddyfile` is read from the mounted repository at run time, so changing it needs no rebuild.
