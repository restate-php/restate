# Docker Images

`docker/` holds the images the test suites serve PHP endpoints with, and the server configuration baked into them or read from the mounted repository. The endpoints they serve live elsewhere: the upstream suite's [test services](../test-services/README.md), and the router scripts of the integration, load and soak suites under `tests/`. How the suites run is in [TESTING.md](../TESTING.md).

Every image builds from the repository root as the build context (`docker build -f docker/Dockerfile --target <target> .`), so the root `.dockerignore` applies.

## Dockerfile

One file, so the targets share the stages that compile the extension inside each PHP (NTS, ZTS, php-fpm):

| Target | Serves | Built by |
| --- | --- | --- |
| `upstream-extension-caddy` | `test-services/index.php` under `php -S`, behind Caddy over h2c (the repository baked in) | `composer test:upstream`, `upstream.yaml` |
| `upstream-fpm-nginx` | `test-services/index.php` under php-fpm behind nginx (the repository baked in) | `composer test:upstream -- upstream-fpm-nginx`, `nightly-serving.yaml` |
| `frankenphp-extension` | the integration router, FrankenPHP classic mode (repository mounted at `/app`) | `composer test:frankenphp` |
| `frankenphp-extension-release` | the load-test router, release build with OPcache | `composer load` |
| `fpm-extension`, `fpm-extension-apache` | any router script under php-fpm behind nginx, Caddy or Apache | `composer test:fpm`, `composer test:serving-hazards` |
| `frankenphp-worker-release`, `frankenphp-worker-debug` | the integration worker script with the `diagnostics` build | `composer test:soak` |

## Configuration

- **`caddy/`**: the Caddyfile and entrypoint of `upstream-extension-caddy` (Caddy `reverse_proxy` over h2c in front of `php -S`).
- **`fpm/`**: the php-fpm images. `entrypoint.sh` renders the `*.template` files from `RESTATE_PHP_FPM_*` variables and runs php-fpm behind nginx, Caddy `php_fastcgi` or Apache `mod_proxy_fcgi`, with the settings [SERVING.md](../docs/SERVING.md#php-fpm-and-fastcgi) recommends or the front door's defaults ([TESTING.md](../TESTING.md#serving-large-bodies-php-fpm-and-front-door-hazards)). `php.ini` goes on top of `php.ini-production`.
- **`frankenphp/`**: the FrankenPHP Caddyfiles (`integration`, `worker`, `load`) and the load tests' ini settings. `worker.Caddyfile` is also read from the mounted repository at run time, so changing it needs no rebuild.
