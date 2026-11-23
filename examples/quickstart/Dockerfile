# syntax=docker/dockerfile:1

# The image the PHP side of the quickstart is: base PHP with the extension, Composer dependencies and the code.
# compose.local.yaml sets PHP_RESTATE to an image built from a checkout of the SDK instead (for CI and
# contributors); you can ignore it.
ARG PHP_RESTATE=php-restate

# PHP with the Restate extension. PIE downloads the prebuilt restate.so for this PHP version, thread safety and
# CPU, installs it and enables it: nothing is compiled.
FROM php:8.4-cli-trixie AS php-restate
RUN --mount=type=bind,from=ghcr.io/php/pie:bin,source=/pie,target=/usr/local/bin/pie \
    pie install restate/ext-restate:~0.1.0 \
    && php --ri restate > /dev/null

FROM ${PHP_RESTATE} AS app

# Composer, and unzip for the package archives it downloads.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/zz-restate-endpoint.ini

WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --no-autoloader
COPY . .
RUN composer dump-autoload --optimize --no-dev

# PHP's built-in server, with several workers so that one slow attempt doesn't hold up every other request.
# Fine for development; see the serving guide for PHP-FPM and FrankenPHP.
ENV PHP_CLI_SERVER_WORKERS=4
EXPOSE 9080
CMD ["php", "-S", "0.0.0.0:9080", "index.php"]
