#!/usr/bin/env bash
# Run the integration suite against a PHP-FPM endpoint behind nginx or Caddy php_fastcgi.
#
# Usage: bin/test-fpm.sh [phpunit arguments...]
#   e.g. bin/test-fpm.sh --filter 'BasicServiceTest|LargeBodyTest'
#
# Builds the fpm-extension target of docker/Dockerfile (the extension compiled inside the
# official php-fpm image, with nginx and Caddy; docker/fpm/) and runs
# `phpunit --testsuite integration` once per server value, with RESTATE_PHP_TEST_SERVER set to it:
#   nginx-fpm       nginx, HTTP/1.1
#   nginx-fpm-h2c   nginx, h2c (registered with use_http_11: false)
#   caddy-fpm       Caddy php_fastcgi, h2c
# The endpoint container mounts the repository, so PHP changes need no rebuild; Rust changes
# (extension/) and changes under docker/fpm/ do.
#
# Environment:
#   RESTATE_PHP_TEST_SERVER        the server values to run, space-separated
#                                  (default: nginx-fpm nginx-fpm-h2c caddy-fpm)
#   RESTATE_PHP_FPM_BASE_IMAGE     php-fpm base image (Dockerfile PHP_FPM_IMAGE build arg),
#                                  e.g. docker.io/library/php:8.5-fpm-trixie
#   RESTATE_PHP_FPM_EXTENSION_PROFILE  debug (default) or release extension build
#   RESTATE_PHP_TEST_FPM_IMAGE     tag to build and test (default restate.local/restate-php-fpm:extension)
#   RESTATE_PHP_TEST_FPM_TARGET    Dockerfile target to build (default fpm-extension;
#                                  fpm-extension-apache adds Apache for the serving-hazards group)
#   RESTATE_PHP_TEST_SKIP_BUILD=1  reuse the existing image
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
IMAGE=${RESTATE_PHP_TEST_FPM_IMAGE:-restate.local/restate-php-fpm:extension}
TARGET=${RESTATE_PHP_TEST_FPM_TARGET:-fpm-extension}
read -r -a SERVERS <<< "${RESTATE_PHP_TEST_SERVER:-nginx-fpm nginx-fpm-h2c caddy-fpm}"

for server in "${SERVERS[@]}"; do
  case "$server" in
    nginx-fpm|nginx-fpm-h2c|caddy-fpm) ;;
    *)
      echo "RESTATE_PHP_TEST_SERVER must list nginx-fpm, nginx-fpm-h2c or caddy-fpm, got '$server'" >&2
      exit 64
      ;;
  esac
done

if [ "${RESTATE_PHP_TEST_SKIP_BUILD:-0}" != 1 ]; then
  build_args=(--build-arg "EXTENSION_PROFILE=${RESTATE_PHP_FPM_EXTENSION_PROFILE:-debug}")
  if [ -n "${RESTATE_PHP_FPM_BASE_IMAGE:-}" ]; then
    build_args+=(--build-arg "PHP_FPM_IMAGE=$RESTATE_PHP_FPM_BASE_IMAGE")
  fi
  docker build "${build_args[@]}" --target "$TARGET" -f "$ROOT_DIR/docker/Dockerfile" -t "$IMAGE" "$ROOT_DIR"
fi

cd "$ROOT_DIR"
status=0
for server in "${SERVERS[@]}"; do
  echo "== integration suite on $server ($IMAGE)" >&2
  RESTATE_PHP_TEST_SERVER=$server \
  RESTATE_PHP_TEST_FPM_IMAGE="$IMAGE" \
    vendor/bin/phpunit --testsuite integration "$@" || status=$?
done
exit "$status"
