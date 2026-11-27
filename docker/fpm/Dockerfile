# syntax=docker/dockerfile:1
# The PHP-FPM images: the endpoint of the integration suite behind nginx or Caddy (fpm-extension;
# just test integration nginx-fpm, nginx-fpm-h2c, caddy-fpm), with Apache as well for the
# serving-hazards tests (fpm-extension-apache; just test serving-hazards), and the test services
# of the SDK conformance suite behind nginx (conformance-fpm-nginx; just test conformance fpm-nginx).
#
# Build them with docker-bake.hcl (bin/build-image.sh, which the recipes run), from the repository
# root: it picks the base for a PHP minor and gives it the extension module built for that PHP
# minor and thread safety as the restate-extension context (extension/Dockerfile, the extension
# flake's portable build). No target compiles the extension.
#
# BASE_IMAGE is PHP's official php-fpm image (trixie: its nginx, 1.26, serves h2c and HTTP/1.1 on
# one port; NTS, except PHP 8.6's, which is ZTS: its NTS images, php:8.6-nts-*, are CLI only):
# one of the base stages below, by name, or any image.
ARG BASE_IMAGE=php-fpm-8.4

# The bases of each PHP minor, as stages of their own so that Dependabot, which reads FROM lines
# only, keeps them up to date. docker-bake.hcl names them for each minor of its PHP_VERSIONS: a
# new minor needs a line here.
FROM docker.io/library/php:8.4-fpm-trixie AS php-fpm-8.4
FROM docker.io/library/php:8.5-fpm-trixie AS php-fpm-8.5
FROM docker.io/library/php:8.6-fpm-trixie AS php-fpm-8.6

FROM ${BASE_IMAGE} AS base

FROM docker.io/library/composer:2 AS composer-bin

FROM docker.io/library/caddy:2.11.9 AS caddy-bin

# restate.so for the target's PHP: docker-bake.hcl replaces this stage with the restate-extension
# context. Without it, the COPY of restate.so fails.
FROM scratch AS restate-extension

# PHP-FPM endpoint for the integration suite (RESTATE_PHP_TEST_SERVER=nginx-fpm, nginx-fpm-h2c,
# caddy-fpm; see just test integration): php-fpm behind nginx or Caddy php_fastcgi, chosen when the
# container starts (docker/fpm/entrypoint.sh). php.ini-production with
# docker/fpm/php.ini on top (display_errors Off, enable_post_data_reading 0, OPcache on).
# Like the FrankenPHP image it bakes in only the extension and the configuration: the harness
# mounts the repository at /app.
FROM base AS fpm-extension

RUN apt-get update \
    && apt-get install -y --no-install-recommends gettext-base nginx \
    && rm -rf /var/lib/apt/lists/*

COPY --from=caddy-bin /usr/bin/caddy /usr/bin/caddy
# Into extension_dir, which depends on the PHP version and thread safety; php.ini (below) enables
# it, and php-fpm -m fails on a module built for another PHP.
COPY --from=restate-extension /restate.so /tmp/
RUN mv /tmp/restate.so "$(php -r 'echo ini_get("extension_dir");')/"

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

# The conformance suite against php-fpm behind nginx (h2c and HTTP/1.1 on :9080, the port the suite
# uses), serving test-services/index.php from the baked-in repository. clear_env = no forwards
# E2E_REQUEST_SIGNING, which the suite's ProxyRequestSigning case sets.
FROM fpm-extension AS conformance-fpm-nginx

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
