# syntax=docker/dockerfile:1
# Test-service images for the upstream SDK test suite (bin/conformance-upstream-local.sh) and
# the FrankenPHP and PHP-FPM endpoint images for the integration, load and soak suites
# (just test integration <server>, just load run, just test soak).
#
# Build them with docker-bake.hcl (bin/build-image.sh, which the recipes run): it picks each
# target's base for a PHP minor and gives it the extension module built for that PHP minor and
# thread safety as the restate-extension context (extension/Dockerfile, the extension flake's
# portable build). No target compiles the extension.
#
# PHP_IMAGE is the NTS PHP of the quickstart image (ZTS for PHP 8.6, whose official images are
# ZTS only); FRANKENPHP_IMAGE is the thread-safe (ZTS) runtime of the frankenphp-extension target;
# PHP_FPM_IMAGE is the php-fpm of the fpm-extension targets (trixie: its nginx, 1.26, serves h2c
# and HTTP/1.1 on one port). Each is one of the base stages below, by name, or any image.
ARG PHP_IMAGE=php-cli-8.4
ARG FRANKENPHP_IMAGE=frankenphp-8.4
ARG PHP_FPM_IMAGE=php-fpm-8.4

# The bases of each PHP minor, as stages of their own so that Dependabot, which reads FROM lines
# only, keeps them up to date. docker-bake.hcl names them for each minor of its PHP_VERSIONS: a
# new minor needs a line of each here.
FROM docker.io/library/php:8.4-cli-bookworm AS php-cli-8.4
FROM docker.io/library/php:8.5-cli-bookworm AS php-cli-8.5
FROM docker.io/library/php:8.6-cli-bookworm AS php-cli-8.6
FROM docker.io/dunglas/frankenphp:1.14.0-php8.4-bookworm AS frankenphp-8.4
FROM docker.io/dunglas/frankenphp:1.14.0-php8.5-bookworm AS frankenphp-8.5
FROM docker.io/dunglas/frankenphp:1.14.0-php8.6-bookworm AS frankenphp-8.6
FROM docker.io/library/php:8.4-fpm-trixie AS php-fpm-8.4
FROM docker.io/library/php:8.5-fpm-trixie AS php-fpm-8.5
FROM docker.io/library/php:8.6-fpm-trixie AS php-fpm-8.6

FROM ${PHP_IMAGE} AS php-base
FROM ${FRANKENPHP_IMAGE} AS frankenphp-base
FROM ${PHP_FPM_IMAGE} AS php-fpm-base

FROM docker.io/library/composer:2 AS composer-bin

FROM docker.io/library/caddy:2.11.9 AS caddy-bin

# restate.so for the target's PHP: docker-bake.hcl replaces this stage with the restate-extension
# context. Without it, docker/extension/install.sh stops the build.
FROM scratch AS restate-extension

# The PHP base of the Docker quickstart when it runs against this checkout instead of the released
# extension and packages (examples/quickstart/compose.local.yaml): PHP_IMAGE with the extension
# module built from this checkout, and a global Composer configuration that installs restate/*
# from packages/ at the extension's version, so the quickstart's own Dockerfile and composer.json
# work unchanged.
FROM php-base AS quickstart-php-restate

RUN --mount=from=restate-extension,target=/mnt/restate-extension \
    --mount=type=bind,source=docker/extension/install.sh,target=/usr/local/bin/install-restate-extension \
    install-restate-extension \
    && echo 'extension=restate' > /usr/local/etc/php/conf.d/restate.ini \
    && php --ri restate > /dev/null
COPY packages /opt/restate/packages
COPY extension/extension/Cargo.toml /tmp/restate-extension-Cargo.toml
ENV COMPOSER_HOME=/opt/composer

RUN version=$(sed -n 's/^version = "\(.*\)"/\1/p' /tmp/restate-extension-Cargo.toml) \
    && mkdir -p "$COMPOSER_HOME" \
    && printf '{"repositories": {"restate": {"type": "path", "url": "/opt/restate/packages/*", "options": {"symlink": false, "versions": {"restate/sdk": "%s", "restate/common": "%s", "restate/ingress": "%s", "restate/testing": "%s"}}}}}\n' \
        "$version" "$version" "$version" "$version" > "$COMPOSER_HOME/config.json" \
    && rm /tmp/restate-extension-Cargo.toml

# FrankenPHP with the ZTS extension, serving the repository the harness mounts at /app (the
# working-tree PHP code) with the integration suite's Caddyfile (RESTATE_PHP_TEST_SERVER=frankenphp,
# just test integration frankenphp). The worker-mode and soak runs start it with worker.Caddyfile from the
# repository instead; the load tests (tests/load/compose.yaml, the release build) mount their own
# over this one. The soak images are this target with the diagnostics builds.
FROM frankenphp-base AS frankenphp-extension

RUN --mount=from=restate-extension,target=/mnt/restate-extension \
    --mount=type=bind,source=docker/extension/install.sh,target=/usr/local/bin/install-restate-extension \
    install-restate-extension \
    && echo 'extension=restate' > /usr/local/etc/php/conf.d/restate.ini \
    && php --ri restate > /dev/null
COPY docker/frankenphp/integration.Caddyfile /etc/frankenphp/Caddyfile

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# The upstream suite against FrankenPHP classic mode: test-services/index.php from the baked-in
# repository on :9080, over h2c or HTTP/1.1 (docker/frankenphp/upstream.Caddyfile).
FROM frankenphp-extension AS upstream-frankenphp

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app
RUN composer install --no-interaction \
    && php -r 'require "/app/vendor/autoload.php"; new Restate\Internal\Backend\Extension\Backend();'
COPY docker/frankenphp/upstream.Caddyfile /etc/frankenphp/Caddyfile

EXPOSE 9080

# PHP-FPM endpoint for the integration suite (RESTATE_PHP_TEST_SERVER=nginx-fpm, nginx-fpm-h2c,
# caddy-fpm; see just test integration): php-fpm behind nginx or Caddy php_fastcgi, chosen when the
# container starts (docker/fpm/entrypoint.sh). php.ini-production with
# docker/fpm/php.ini on top (display_errors Off, enable_post_data_reading 0, OPcache on).
# Like the FrankenPHP image it bakes in only the extension and the configuration: the harness
# mounts the repository at /app.
FROM php-fpm-base AS fpm-extension

RUN apt-get update \
    && apt-get install -y --no-install-recommends gettext-base nginx \
    && rm -rf /var/lib/apt/lists/*

COPY --from=caddy-bin /usr/bin/caddy /usr/bin/caddy
RUN --mount=from=restate-extension,target=/mnt/restate-extension \
    --mount=type=bind,source=docker/extension/install.sh,target=/usr/local/bin/install-restate-extension \
    install-restate-extension
COPY docker/fpm/*.template /etc/restate-fpm/
COPY docker/fpm/php.ini /usr/local/etc/php/conf.d/zz-restate.ini
COPY docker/fpm/entrypoint.sh /usr/local/bin/fpm-entrypoint.sh

RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini \
    && php-fpm -m | grep -qx restate \
    && php-fpm -i | grep -q '^opcache.enable => On' \
    && nginx -v && caddy version

EXPOSE 8080
CMD ["/usr/local/bin/fpm-entrypoint.sh"]

# fpm-extension with Apache httpd (mod_proxy_fcgi) as a third front door, for the serving-hazards
# tests only (RESTATE_PHP_FPM_FRONT=apache).
FROM fpm-extension AS fpm-extension-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends apache2 \
    && rm -rf /var/lib/apt/lists/* \
    && apache2 -v

# The upstream suite against php-fpm behind nginx (h2c and HTTP/1.1 on :9080, the port the suite
# uses), serving test-services/index.php from the baked-in repository. clear_env = no forwards
# E2E_REQUEST_SIGNING, which the suite's ProxyRequestSigning case sets.
FROM fpm-extension AS upstream-fpm-nginx

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app
RUN composer install --no-interaction \
    && php -r 'require "/app/vendor/autoload.php"; new Restate\Internal\Backend\Extension\Backend();'

ENV RESTATE_PHP_FPM_FRONT=nginx-h2c \
    RESTATE_PHP_FPM_PORT=9080 \
    RESTATE_PHP_FPM_SCRIPT=/app/test-services/index.php

EXPOSE 9080
