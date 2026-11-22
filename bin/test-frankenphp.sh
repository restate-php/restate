#!/usr/bin/env bash
# Run the integration suite against a FrankenPHP endpoint that loads the thread-safe (ZTS)
# build of the extension.
#
# Usage: bin/test-frankenphp.sh [--worker] [phpunit arguments...]
#   e.g. bin/test-frankenphp.sh --filter 'BasicServiceTest|VirtualObjectTest'
#        bin/test-frankenphp.sh --worker
#
# Builds the frankenphp-extension target of docker/Dockerfile (the extension compiled
# inside the FrankenPHP image, against its ZTS PHP) and runs `phpunit --testsuite integration`
# with RESTATE_PHP_TEST_SERVER=frankenphp: classic mode, the router script runs per request.
# With --worker the endpoint runs in worker mode instead (docker/frankenphp/worker.Caddyfile,
# tests/Integration/Support/worker.php builds the endpoints once per worker), with
# RESTATE_PHP_TEST_SERVER=frankenphp-worker, and the worker suite (tests/Worker) runs after
# the integration suite. The endpoint container mounts the repository, so PHP changes need no
# rebuild; Rust changes (extension/) do.
#
# Environment:
#   RESTATE_PHP_FRANKENPHP_BASE_IMAGE  FrankenPHP base image (Dockerfile FRANKENPHP_IMAGE build arg),
#                                      e.g. docker.io/dunglas/frankenphp:<version>-php8.5-bookworm
#   RESTATE_PHP_TEST_FRANKENPHP_IMAGE  tag to build and test (default restate.local/restate-php-frankenphp:extension-zts)
#   RESTATE_PHP_TEST_SKIP_BUILD=1      reuse the existing image
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
IMAGE=${RESTATE_PHP_TEST_FRANKENPHP_IMAGE:-restate.local/restate-php-frankenphp:extension-zts}

server=frankenphp
suites=integration
if [ "${1:-}" = --worker ]; then
  shift
  server=frankenphp-worker
  suites=integration,worker
fi

if [ "${RESTATE_PHP_TEST_SKIP_BUILD:-0}" != 1 ]; then
  build_args=()
  if [ -n "${RESTATE_PHP_FRANKENPHP_BASE_IMAGE:-}" ]; then
    build_args+=(--build-arg "FRANKENPHP_IMAGE=$RESTATE_PHP_FRANKENPHP_BASE_IMAGE")
  fi
  docker build "${build_args[@]}" --target frankenphp-extension -f "$ROOT_DIR/docker/Dockerfile" -t "$IMAGE" "$ROOT_DIR"
fi

cd "$ROOT_DIR"
RESTATE_PHP_TEST_SERVER=$server \
RESTATE_PHP_TEST_FRANKENPHP_IMAGE="$IMAGE" \
  exec vendor/bin/phpunit --testsuite "$suites" "$@"
