# syntax=docker/dockerfile:1
# The PHP base of the Docker quickstart when it runs against this checkout instead of the released
# extension and packages (examples/quickstart/compose.local.yaml; just test quickstart local):
# PHP's official CLI image with the extension module built from this checkout, and a global
# Composer configuration that installs restate/* from packages/ at the extension's version, so the
# quickstart's own Dockerfile and composer.json work unchanged.
#
# Build it with docker-bake.hcl (bin/build-image.sh quickstart-php-restate <tag>), from the
# repository root: it picks the base for a PHP minor and gives it the extension module built for
# that PHP minor and thread safety as the restate-extension context (extension/Dockerfile, the
# extension flake's portable build).
#
# BASE_IMAGE is PHP's official CLI image (NTS, except PHP 8.6's, which is ZTS: what a user of
# php:8.6-cli gets, not the NTS variant php:8.6-nts-*): one of the base stages below, by name, or
# any image.
ARG BASE_IMAGE=php-cli-8.4

# The bases of each PHP minor, as stages of their own so that Dependabot, which reads FROM lines
# only, keeps them up to date. docker-bake.hcl names them for each minor of its PHP_VERSIONS: a
# new minor needs a line here.
FROM docker.io/library/php:8.4-cli-bookworm AS php-cli-8.4
FROM docker.io/library/php:8.5-cli-bookworm AS php-cli-8.5
FROM docker.io/library/php:8.6-cli-bookworm AS php-cli-8.6

FROM ${BASE_IMAGE} AS base

# restate.so for the target's PHP: docker-bake.hcl replaces this stage with the restate-extension
# context. Without it, the COPY of restate.so fails.
FROM scratch AS restate-extension

FROM base AS quickstart-php-restate

# Into extension_dir, which depends on the PHP version and thread safety; php --ri fails on a
# module built for another PHP.
COPY --from=restate-extension /restate.so /tmp/
RUN mv /tmp/restate.so "$(php -r 'echo ini_get("extension_dir");')/" \
    && docker-php-ext-enable restate \
    && php --ri restate > /dev/null
COPY packages /opt/restate/packages
COPY extension/extension/Cargo.toml /tmp/restate-extension-Cargo.toml

ENV COMPOSER_HOME=/opt/composer

RUN version=$(sed -n 's/^version = "\(.*\)"/\1/p' /tmp/restate-extension-Cargo.toml) \
    && mkdir -p "$COMPOSER_HOME" \
    && printf '{"repositories": {"restate": {"type": "path", "url": "/opt/restate/packages/*", "options": {"symlink": false, "versions": {"restate/sdk": "%s", "restate/common": "%s", "restate/ingress": "%s", "restate/testing": "%s"}}}}}\n' \
        "$version" "$version" "$version" "$version" > "$COMPOSER_HOME/config.json" \
    && rm /tmp/restate-extension-Cargo.toml
