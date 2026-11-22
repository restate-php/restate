#!/usr/bin/env bash
# Run the soak suite (tests/Soak) against FrankenPHP worker-mode endpoints built with the
# extension's `diagnostics` cargo feature (TESTING.md, "Soak suite").
#
# Usage: bin/test-soak.sh [--quick] [nightly|frames|concurrency|bailout|restate|all] [phpunit arguments...]
#   nightly      frames, concurrency and bailout (the default)
#   frames       FrameReplaySoakTest: direct frame replay, about a million requests, per request class then mixed
#   concurrency  ConcurrencySoakTest: many PHP threads in one process: log attribution, panic containment, throughput
#   bailout      BailoutSoakTest: fatal errors across Rust frames and worker restarts
#   restate      RestateSoakTest: about 300,000 invocations through Restate (RESTATE_PHP_SOAK_INVOCATIONS)
#   all          everything
#   --quick      a few percent of the requests, for a local run (RSS is reported, not gated)
#
# Builds the frankenphp-worker-release and frankenphp-worker-debug targets of
# docker/Dockerfile (the ones the chosen tests need). The containers mount the repository,
# so PHP changes need no rebuild; Rust changes do. Reports go to var/soak/.
#
# Environment:
#   RESTATE_PHP_SOAK_RELEASE_IMAGE     release image tag (default restate.local/restate-php-frankenphp:worker-release)
#   RESTATE_PHP_SOAK_DEBUG_IMAGE       debug image tag (default restate.local/restate-php-frankenphp:worker-debug)
#   RESTATE_PHP_SOAK_SKIP_BUILD=1      reuse the existing images
#   RESTATE_PHP_FRANKENPHP_BASE_IMAGE  FrankenPHP base image (Dockerfile FRANKENPHP_IMAGE build arg)
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
RELEASE_IMAGE=${RESTATE_PHP_SOAK_RELEASE_IMAGE:-restate.local/restate-php-frankenphp:worker-release}
DEBUG_IMAGE=${RESTATE_PHP_SOAK_DEBUG_IMAGE:-restate.local/restate-php-frankenphp:worker-debug}

quick=0
if [ "${1:-}" = --quick ]; then
  quick=1
  shift
fi
suite=nightly
case "${1:-}" in
  nightly|frames|concurrency|bailout|restate|all) suite=$1; shift ;;
esac

case "$suite" in
  nightly) filter='FrameReplaySoakTest|ConcurrencySoakTest|BailoutSoakTest'; images=(release debug) ;;
  frames) filter='FrameReplaySoakTest'; images=(release) ;;
  concurrency) filter='ConcurrencySoakTest'; images=(release debug) ;;
  bailout) filter='BailoutSoakTest'; images=(debug) ;;
  restate) filter='RestateSoakTest'; images=(release) ;;
  all) filter='SoakTest'; images=(release debug) ;;
esac

if [ "${RESTATE_PHP_SOAK_SKIP_BUILD:-0}" != 1 ]; then
  build_args=()
  if [ -n "${RESTATE_PHP_FRANKENPHP_BASE_IMAGE:-}" ]; then
    build_args+=(--build-arg "FRANKENPHP_IMAGE=$RESTATE_PHP_FRANKENPHP_BASE_IMAGE")
  fi
  for image in "${images[@]}"; do
    if [ "$image" = release ]; then tag=$RELEASE_IMAGE; else tag=$DEBUG_IMAGE; fi
    docker build "${build_args[@]}" --target "frankenphp-worker-$image" -f "$ROOT_DIR/docker/Dockerfile" -t "$tag" "$ROOT_DIR"
  done
fi

cd "$ROOT_DIR"
RESTATE_PHP_SOAK_QUICK=$quick \
RESTATE_PHP_SOAK_RELEASE_IMAGE="$RELEASE_IMAGE" \
RESTATE_PHP_SOAK_DEBUG_IMAGE="$DEBUG_IMAGE" \
  exec vendor/bin/phpunit --testsuite soak --filter "$filter" "$@"
