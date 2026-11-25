# Test-service images for the upstream SDK test suite (bin/conformance-upstream-local.sh) and
# the FrankenPHP and PHP-FPM endpoint images for the integration, load and soak suites
# (just test integration <server>, just load run, just test soak).
#
# PHP_IMAGE is the NTS PHP the extension is built against and the upstream images run on;
# FRANKENPHP_IMAGE is the thread-safe (ZTS) runtime of the frankenphp-extension target;
# PHP_FPM_IMAGE is the NTS php-fpm of the fpm-extension targets (trixie: its nginx, 1.26, serves
# h2c and HTTP/1.1 on one port). Set any with --build-arg to build against another PHP minor;
# unset or empty, each is the *-default stage below.
#
# EXTENSION_PROFILE (debug or release; default debug) and EXTENSION_FEATURES (cargo features)
# choose the extension build of every target (docker/extension/build.sh).
ARG PHP_IMAGE
ARG FRANKENPHP_IMAGE
ARG PHP_FPM_IMAGE

# The default bases, as stages of their own so that Dependabot, which reads FROM lines only, keeps
# them up to date.
FROM docker.io/library/php:8.4-cli-bookworm AS php-default
FROM docker.io/dunglas/frankenphp:1.14.0-php8.4-bookworm AS frankenphp-default
FROM docker.io/library/php:8.4-fpm-trixie AS php-fpm-default

FROM ${PHP_IMAGE:-php-default} AS php-base
FROM ${FRANKENPHP_IMAGE:-frankenphp-default} AS frankenphp-base
FROM ${PHP_FPM_IMAGE:-php-fpm-default} AS php-fpm-base

FROM docker.io/library/composer:2 AS composer-bin

FROM docker.io/library/caddy:2.11.9 AS caddy-bin

# rustup and the toolchain pinned in extension/rust-toolchain.toml (the dev shell uses the same
# file), which the extension-bridge stages copy: its binaries run on any of the Debian bases.
FROM docker.io/library/debian:bookworm-slim AS rust-toolchain

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl \
    && rm -rf /var/lib/apt/lists/*

RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain none
ENV PATH="/root/.cargo/bin:${PATH}"
COPY extension/rust-toolchain.toml /app/extension/rust-toolchain.toml
RUN cd /app/extension && rustup toolchain install && rustc --version

# The extension built against the NTS PHP of PHP_IMAGE (docker/extension/build.sh), for the
# upstream and quickstart images. extension-bridge-zts and extension-bridge-fpm below do the same
# inside the FrankenPHP and php-fpm images: a module loads only into the PHP it was built against
# (an NTS one does not load into a ZTS runtime).
FROM php-base AS extension-bridge

RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential ca-certificates clang libclang-dev pkg-config \
    && rm -rf /var/lib/apt/lists/*
COPY --from=rust-toolchain /root/.rustup /root/.rustup
COPY --from=rust-toolchain /root/.cargo /root/.cargo
ENV PATH="/root/.cargo/bin:${PATH}"

WORKDIR /app
COPY docker/extension/build.sh /usr/local/bin/build-extension
# The whole Cargo workspace (extension/target is excluded by .dockerignore).
COPY extension extension

ARG EXTENSION_PROFILE
ARG EXTENSION_FEATURES
RUN --mount=type=cache,id=restate-cargo-registry,target=/root/.cargo/registry \
    --mount=type=cache,id=restate-extension-target,target=/cache/target,sharing=locked \
    build-extension

FROM frankenphp-base AS extension-bridge-zts

RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential ca-certificates clang libclang-dev pkg-config \
    && rm -rf /var/lib/apt/lists/*
COPY --from=rust-toolchain /root/.rustup /root/.rustup
COPY --from=rust-toolchain /root/.cargo /root/.cargo
ENV PATH="/root/.cargo/bin:${PATH}"

WORKDIR /app
COPY docker/extension/build.sh /usr/local/bin/build-extension
COPY extension extension

ARG EXTENSION_PROFILE
ARG EXTENSION_FEATURES
RUN --mount=type=cache,id=restate-cargo-registry,target=/root/.cargo/registry \
    --mount=type=cache,id=restate-extension-target,target=/cache/target,sharing=locked \
    build-extension

FROM php-fpm-base AS extension-bridge-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential ca-certificates clang libclang-dev pkg-config \
    && rm -rf /var/lib/apt/lists/*
COPY --from=rust-toolchain /root/.rustup /root/.rustup
COPY --from=rust-toolchain /root/.cargo /root/.cargo
ENV PATH="/root/.cargo/bin:${PATH}"

WORKDIR /app
COPY docker/extension/build.sh /usr/local/bin/build-extension
COPY extension extension

ARG EXTENSION_PROFILE
ARG EXTENSION_FEATURES
RUN --mount=type=cache,id=restate-cargo-registry,target=/root/.cargo/registry \
    --mount=type=cache,id=restate-extension-target,target=/cache/target,sharing=locked \
    build-extension

# The PHP base of the Docker quickstart when it runs against this checkout instead of the released
# extension and packages (examples/quickstart/compose.local.yaml): PHP_IMAGE with the extension built
# from source, and a global Composer configuration that installs restate/* from packages/ at the
# extension's version, so the quickstart's own Dockerfile and composer.json work unchanged.
FROM php-base AS quickstart-php-restate

COPY --from=extension-bridge /out/ /
COPY packages /opt/restate/packages
COPY extension/extension/Cargo.toml /tmp/restate-extension-Cargo.toml
ENV COMPOSER_HOME=/opt/composer

RUN echo 'extension=restate' > /usr/local/etc/php/conf.d/restate.ini \
    && php --ri restate > /dev/null \
    && version=$(sed -n 's/^version = "\(.*\)"/\1/p' /tmp/restate-extension-Cargo.toml) \
    && mkdir -p "$COMPOSER_HOME" \
    && printf '{"repositories": {"restate": {"type": "path", "url": "/opt/restate/packages/*", "options": {"symlink": false, "versions": {"restate/sdk": "%s", "restate/common": "%s", "restate/ingress": "%s", "restate/testing": "%s"}}}}}\n' \
        "$version" "$version" "$version" "$version" > "$COMPOSER_HOME/config.json" \
    && rm /tmp/restate-extension-Cargo.toml

FROM php-base AS upstream-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app
RUN composer install --no-interaction

# test-services/index.php under php -S, behind Caddy over h2c (docker/caddy/).
FROM upstream-base AS upstream-extension-caddy

COPY --from=caddy-bin /usr/bin/caddy /usr/bin/caddy
COPY --from=extension-bridge /out/ /
RUN echo 'extension=restate' > /usr/local/etc/php/conf.d/restate.ini \
    && php -r 'require "/app/vendor/autoload.php"; new Restate\Internal\Backend\Extension\Backend();'

CMD ["/app/docker/caddy/entrypoint.sh"]

# FrankenPHP with the ZTS extension, serving the repository the harness mounts at /app (the
# working-tree PHP code) with the integration suite's Caddyfile (RESTATE_PHP_TEST_SERVER=frankenphp,
# just test integration frankenphp). The worker-mode and soak runs start it with worker.Caddyfile from the
# repository instead; the load tests (tests/load/compose.yaml, EXTENSION_PROFILE=release) mount
# their own over this one. The soak images are this target with EXTENSION_FEATURES=diagnostics.
FROM frankenphp-base AS frankenphp-extension

COPY --from=extension-bridge-zts /out/ /
COPY docker/frankenphp/integration.Caddyfile /etc/frankenphp/Caddyfile
RUN echo 'extension=restate' > /usr/local/etc/php/conf.d/restate.ini \
    && php --ri restate > /dev/null

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

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
COPY --from=extension-bridge-fpm /out/ /
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
