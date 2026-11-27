# syntax=docker/dockerfile:1
# The FrankenPHP images: the endpoint of the integration, load and soak suites
# (frankenphp-extension; just test integration frankenphp, just load run, just test soak) and the
# test services of the SDK conformance suite in classic and worker mode (conformance-frankenphp,
# conformance-frankenphp-worker; just test conformance).
#
# Build them with docker-bake.hcl (bin/build-image.sh, which the recipes run), from the repository
# root: it picks the base for a PHP minor and gives it the extension module built for that PHP
# minor, ZTS, as the restate-extension context (extension/Dockerfile, the extension flake's
# portable build). No target compiles the extension.
#
# BASE_IMAGE is FrankenPHP's thread-safe (ZTS) runtime: one of the base stages below, by name, or
# any image. The -alpine bases are musl (docker-bake.hcl's LIBC=musl).
ARG BASE_IMAGE=frankenphp-8.4

# The bases of each PHP minor and C library, as stages of their own so that Dependabot, which reads
# FROM lines only, keeps them up to date. docker-bake.hcl names them for each minor of its
# PHP_VERSIONS: a new minor needs two lines here.
FROM docker.io/dunglas/frankenphp:1.14.0-php8.4-bookworm AS frankenphp-8.4
FROM docker.io/dunglas/frankenphp:1.14.0-php8.5-bookworm AS frankenphp-8.5
FROM docker.io/dunglas/frankenphp:1.14.0-php8.6-bookworm AS frankenphp-8.6
FROM docker.io/dunglas/frankenphp:1.14.0-php8.4-alpine AS frankenphp-8.4-alpine
FROM docker.io/dunglas/frankenphp:1.14.0-php8.5-alpine AS frankenphp-8.5-alpine
FROM docker.io/dunglas/frankenphp:1.14.0-php8.6-alpine AS frankenphp-8.6-alpine

FROM ${BASE_IMAGE} AS base

FROM docker.io/library/composer:2 AS composer-bin

# restate.so for the target's PHP: docker-bake.hcl replaces this stage with the restate-extension
# context. Without it, the COPY of restate.so fails.
FROM scratch AS restate-extension

# FrankenPHP with the ZTS extension, serving the repository the harness mounts at /app (the
# working-tree PHP code) with the integration suite's Caddyfile (RESTATE_PHP_TEST_SERVER=frankenphp,
# just test integration frankenphp). The worker-mode and soak runs start it with worker.Caddyfile from the
# repository instead; the load tests (tests/load/compose.yaml, the release build) mount their own
# over this one. The soak images are this target with the diagnostics builds.
FROM base AS frankenphp-extension

# Into extension_dir, which depends on the PHP version and thread safety; php --ri fails on a
# module built for another PHP.
COPY --from=restate-extension /restate.so /tmp/
RUN mv /tmp/restate.so "$(php -r 'echo ini_get("extension_dir");')/" \
    && docker-php-ext-enable restate \
    && php --ri restate > /dev/null

COPY docker/frankenphp/integration.Caddyfile /etc/frankenphp/Caddyfile

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# The conformance suite against FrankenPHP classic mode: test-services/index.php from the baked-in
# repository on :9080, over h2c or HTTP/1.1 (docker/frankenphp/conformance.Caddyfile).
FROM frankenphp-extension AS conformance-frankenphp

RUN if command -v apk >/dev/null; then \
        apk add --no-cache git unzip; \
    else \
        apt-get update \
        && apt-get install -y --no-install-recommends git unzip \
        && rm -rf /var/lib/apt/lists/*; \
    fi
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

RUN composer install --no-interaction \
    && php -r 'require "/app/vendor/autoload.php"; new Restate\Internal\Backend\Extension\Backend();'

COPY docker/frankenphp/conformance.Caddyfile /etc/frankenphp/Caddyfile

EXPOSE 9080

# The same in worker mode: test-services/worker.php builds the endpoint once per worker
# (docker/frankenphp/conformance-worker.Caddyfile).
FROM conformance-frankenphp AS conformance-frankenphp-worker

COPY docker/frankenphp/conformance-worker.Caddyfile /etc/frankenphp/Caddyfile
