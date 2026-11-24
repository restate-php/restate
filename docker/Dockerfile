# Test-service images for the upstream SDK test suite (bin/conformance-upstream-local.sh) and
# the FrankenPHP and PHP-FPM endpoint images for the integration, load and soak suites
# (bin/test-frankenphp.sh, bin/test-fpm.sh, bin/load-test.sh, bin/test-soak.sh).
#
# PHP_IMAGE is the NTS PHP the extension is built against and the upstream images run on;
# FRANKENPHP_IMAGE is the thread-safe (ZTS) runtime for the frankenphp-extension target;
# PHP_FPM_IMAGE is the NTS php-fpm of the fpm-extension targets (trixie: its nginx, 1.26, serves
# h2c and HTTP/1.1 on one port). Override any with --build-arg to build against another PHP minor.
ARG PHP_IMAGE=docker.io/library/php:8.4-cli-bookworm
ARG FRANKENPHP_IMAGE=docker.io/dunglas/frankenphp:1.13.5-php8.4-bookworm
ARG PHP_FPM_IMAGE=docker.io/library/php:8.4-fpm-trixie

FROM docker.io/library/composer:2 AS composer-bin

FROM docker.io/library/caddy:2.11.9 AS caddy-bin

FROM ${PHP_IMAGE} AS extension-bridge

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential ca-certificates clang curl git libclang-dev pkg-config \
    && rm -rf /var/lib/apt/lists/*

# rustup installs the toolchain pinned in extension/rust-toolchain.toml (the dev shell uses the
# same file), in a layer of its own so source changes do not reinstall it.
RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain none
ENV PATH="/root/.cargo/bin:${PATH}"
COPY extension/rust-toolchain.toml extension/rust-toolchain.toml
RUN cd extension && rustup toolchain install && rustc --version

# The whole Cargo workspace (extension/target is excluded by .dockerignore).
COPY extension extension

# Built with plain cargo (the dev shell's `just extension build` does the same plus bookkeeping
# an image does not need): for the PHP whose php-config is on PATH, the image's. The module is
# staged under /out at the path an install puts it, restate.so in this PHP's extension_dir, so
# the image stages copy /out/ to / and enable it with `extension=restate`.
RUN php-config --version \
    && (cd extension && cargo build -p restate) \
    && extension_dir=$(php -r 'echo ini_get("extension_dir");') \
    && mkdir -p "/out$extension_dir" \
    && cp extension/target/debug/librestate.so "/out$extension_dir/restate.so"

# The PHP base of the Docker quickstart when it runs against this checkout instead of the released
# extension and packages (examples/quickstart/compose.local.yaml): PHP_IMAGE with the extension built
# from source, and a global Composer configuration that installs restate/* from packages/ at the
# extension's version, so the quickstart's own Dockerfile and composer.json work unchanged.
FROM ${PHP_IMAGE} AS quickstart-php-restate

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

FROM ${PHP_IMAGE} AS upstream-base

WORKDIR /app
COPY . /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer

RUN composer install --no-interaction

FROM upstream-base AS upstream-extension-caddy

COPY --from=caddy-bin /usr/bin/caddy /usr/bin/caddy
COPY --from=extension-bridge /out/ /
COPY docker/caddy/Caddyfile docker/caddy/Caddyfile
COPY docker/caddy/entrypoint.sh /usr/local/bin/upstream-extension-caddy-entrypoint.sh
RUN chmod +x /usr/local/bin/upstream-extension-caddy-entrypoint.sh \
    && echo 'extension=restate' > /usr/local/etc/php/conf.d/restate.ini \
    && php -r 'if (!extension_loaded("restate")) { fwrite(STDERR, "Restate PHP extension did not load\n"); exit(1); } require "/app/vendor/autoload.php"; new Restate\Internal\Backend\Extension\Backend();'

CMD ["/usr/local/bin/upstream-extension-caddy-entrypoint.sh"]

# The extension built against the thread-safe (ZTS) PHP inside the FrankenPHP image. An NTS
# module does not load into a ZTS runtime, so FrankenPHP needs its own build.
FROM ${FRANKENPHP_IMAGE} AS extension-bridge-zts

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential ca-certificates clang curl libclang-dev pkg-config \
    && rm -rf /var/lib/apt/lists/*

RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain none
ENV PATH="/root/.cargo/bin:${PATH}"
COPY extension/rust-toolchain.toml extension/rust-toolchain.toml
RUN cd extension && rustup toolchain install && rustc --version

COPY extension extension

# Built and staged under /out like the NTS module above.
RUN php -r 'exit(PHP_ZTS ? 0 : 1);' \
    && (cd extension && cargo build -p restate) \
    && extension_dir=$(php -r 'echo ini_get("extension_dir");') \
    && mkdir -p "/out$extension_dir" \
    && cp extension/target/debug/librestate.so "/out$extension_dir/restate.so"

# FrankenPHP endpoint for the integration suite (RESTATE_PHP_TEST_SERVER=frankenphp, see
# bin/test-frankenphp.sh). Only the ZTS extension and the Caddyfile are baked in: the harness
# mounts the repository at /app, so the endpoint always runs the working-tree PHP code.
FROM ${FRANKENPHP_IMAGE} AS frankenphp-extension

COPY --from=extension-bridge-zts /out/ /
COPY docker/frankenphp/integration.Caddyfile /etc/frankenphp/Caddyfile

RUN echo 'extension=restate' > /usr/local/etc/php/conf.d/restate.ini \
    && php -r 'if (!PHP_ZTS || !extension_loaded("restate")) { fwrite(STDERR, "ZTS Restate extension did not load\n"); exit(1); }'

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# The ZTS extension as a release build: the load tests' endpoint (bin/load-test.sh). The same
# setup as extension-bridge-zts (identical instructions, so the toolchain layers are shared from
# the build cache), with --release, so building this target never compiles the debug module.
FROM ${FRANKENPHP_IMAGE} AS extension-bridge-zts-release

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential ca-certificates clang curl libclang-dev pkg-config \
    && rm -rf /var/lib/apt/lists/*

RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain none
ENV PATH="/root/.cargo/bin:${PATH}"
COPY extension/rust-toolchain.toml extension/rust-toolchain.toml
RUN cd extension && rustup toolchain install && rustc --version

COPY extension extension

RUN php -r 'exit(PHP_ZTS ? 0 : 1);' \
    && (cd extension && cargo build -p restate --release) \
    && extension_dir=$(php -r 'echo ini_get("extension_dir");') \
    && mkdir -p "/out$extension_dir" \
    && cp extension/target/release/librestate.so "/out$extension_dir/restate.so"

# FrankenPHP classic mode with the release ZTS extension, OPcache on and JIT off
# (docker/frankenphp/load.ini): the default load-test endpoint. Like frankenphp-extension
# it bakes in no PHP code: the runner mounts the repository at /app, and the Caddyfile sends every
# request to tests/Load/Support/endpoint.php. Another endpoint image (FrankenPHP worker mode,
# php-fpm behind nginx) plugs in with `bin/load-test.sh --image <tag>` when it serves that router
# on port 8080 with the repository at /app.
FROM ${FRANKENPHP_IMAGE} AS frankenphp-extension-release

COPY --from=extension-bridge-zts-release /out/ /
COPY docker/frankenphp/load.Caddyfile /etc/frankenphp/Caddyfile
COPY docker/frankenphp/load.ini /usr/local/etc/php/conf.d/zz-load.ini

RUN echo 'extension=restate' > /usr/local/etc/php/conf.d/restate.ini \
    && php -r 'if (!PHP_ZTS || !extension_loaded("restate") || !function_exists("opcache_get_status")) { fwrite(STDERR, "ZTS Restate extension or OPcache did not load\n"); exit(1); }'

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# The extension built against the NTS PHP inside the php-fpm image, as the stages above do for
# theirs. --build-arg EXTENSION_PROFILE=release builds the release module (production-like runs,
# load tests); debug, the default, keeps the debug assertions the integration suite runs with.
FROM ${PHP_FPM_IMAGE} AS extension-bridge-fpm

ARG EXTENSION_PROFILE=debug

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential ca-certificates clang curl libclang-dev pkg-config \
    && rm -rf /var/lib/apt/lists/*

RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain none
ENV PATH="/root/.cargo/bin:${PATH}"
COPY extension/rust-toolchain.toml extension/rust-toolchain.toml
RUN cd extension && rustup toolchain install && rustc --version

COPY extension extension

RUN case "$EXTENSION_PROFILE" in debug) profile=dev ;; release) profile=release ;; *) echo "EXTENSION_PROFILE must be debug or release" >&2; exit 1 ;; esac \
    && (cd extension && cargo build -p restate --profile "$profile") \
    && extension_dir=$(php -r 'echo ini_get("extension_dir");') \
    && mkdir -p "/out$extension_dir" \
    && cp "extension/target/$EXTENSION_PROFILE/librestate.so" "/out$extension_dir/restate.so"

# PHP-FPM endpoint for the integration suite (RESTATE_PHP_TEST_SERVER=nginx-fpm, nginx-fpm-h2c,
# caddy-fpm; see bin/test-fpm.sh): php-fpm behind nginx or Caddy php_fastcgi, chosen when the
# container starts (docker/fpm/entrypoint.sh). php.ini-production with
# docker/fpm/php.ini on top (display_errors Off, enable_post_data_reading 0, OPcache on).
# Like the FrankenPHP image it bakes in only the extension and the configuration: the harness
# mounts the repository at /app.
FROM ${PHP_FPM_IMAGE} AS fpm-extension

RUN apt-get update \
    && apt-get install -y --no-install-recommends gettext-base nginx \
    && rm -rf /var/lib/apt/lists/*

COPY --from=caddy-bin /usr/bin/caddy /usr/bin/caddy
COPY --from=extension-bridge-fpm /out/ /
COPY docker/fpm/*.template /etc/restate-fpm/
COPY docker/fpm/php.ini /usr/local/etc/php/conf.d/zz-restate.ini
COPY docker/fpm/entrypoint.sh /usr/local/bin/fpm-entrypoint.sh

RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini \
    && chmod +x /usr/local/bin/fpm-entrypoint.sh \
    && php-fpm -m | grep -qx restate \
    && php-fpm -i | grep -q '^opcache.enable => On' \
    && php -r 'if (!extension_loaded("restate") || PHP_ZTS) { fwrite(STDERR, "NTS Restate extension did not load\n"); exit(1); }' \
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

WORKDIR /app
COPY . /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer

RUN composer install --no-interaction \
    && php -r 'require "/app/vendor/autoload.php"; new Restate\Internal\Backend\Extension\Backend();'

ENV RESTATE_PHP_FPM_FRONT=nginx-h2c \
    RESTATE_PHP_FPM_PORT=9080 \
    RESTATE_PHP_FPM_SCRIPT=/app/test-services/index.php

EXPOSE 9080

# The thread-safe extension with the `diagnostics` cargo feature (Restate\Extension\diagnostics():
# live objects and a counting allocator) for the soak suite (tests/Soak, bin/test-soak.sh), each on
# top of a ZTS build stage above, so only the extension crate is compiled again. Release for the
# resource soaks, debug for the panic and bailout probes (which release builds compile out).
FROM extension-bridge-zts-release AS extension-bridge-zts-release-diagnostics

RUN (cd extension && cargo build -p restate --release --features diagnostics) \
    && extension_dir=$(php -r 'echo ini_get("extension_dir");') \
    && mkdir -p "/out-diagnostics$extension_dir" \
    && cp extension/target/release/librestate.so "/out-diagnostics$extension_dir/restate.so"

FROM extension-bridge-zts AS extension-bridge-zts-debug-diagnostics

RUN (cd extension && cargo build -p restate --features diagnostics) \
    && extension_dir=$(php -r 'echo ini_get("extension_dir");') \
    && mkdir -p "/out-diagnostics$extension_dir" \
    && cp extension/target/debug/librestate.so "/out-diagnostics$extension_dir/restate.so"

# FrankenPHP worker mode for the soak suite: the integration endpoint's worker script
# (tests/Integration/Support/worker.php, from the repository mounted at /app, as for
# frankenphp-extension) under docker/frankenphp/worker.Caddyfile on port 8080, with the
# diagnostics build. It serves the integration services and the /__worker probe, not the load
# router, so it is no drop-in for bin/load-test.sh --image. GODEBUG=madvdontneed=1 makes Go return
# freed memory at once, so RSS follows the heaps.
FROM ${FRANKENPHP_IMAGE} AS frankenphp-worker-release

COPY --from=extension-bridge-zts-release-diagnostics /out-diagnostics/ /
COPY docker/frankenphp/worker.Caddyfile /etc/frankenphp/Caddyfile
ENV GODEBUG=madvdontneed=1

RUN echo 'extension=restate' > /usr/local/etc/php/conf.d/restate.ini \
    && php -r 'if (!PHP_ZTS || !function_exists("Restate\\Extension\\diagnostics")) { fwrite(STDERR, "ZTS Restate extension with diagnostics did not load\n"); exit(1); }'

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# The same with the debug diagnostics build: the panic and bailout probes work.
FROM ${FRANKENPHP_IMAGE} AS frankenphp-worker-debug

COPY --from=extension-bridge-zts-debug-diagnostics /out-diagnostics/ /
COPY docker/frankenphp/worker.Caddyfile /etc/frankenphp/Caddyfile
ENV GODEBUG=madvdontneed=1

RUN echo 'extension=restate' > /usr/local/etc/php/conf.d/restate.ini \
    && php -r 'if (!PHP_ZTS || !function_exists("Restate\\Extension\\diagnostics")) { fwrite(STDERR, "ZTS Restate extension with diagnostics did not load\n"); exit(1); }'

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
