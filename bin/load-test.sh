#!/usr/bin/env bash
# Load tests (TESTING.md, Performance): a fresh Restate that signs its requests, the load endpoint
# in a container (FrankenPHP classic mode with the release ZTS extension and OPcache by default),
# and k6 on the host driving open-model arrival rates through the ingress.
#
# Usage: bin/load-test.sh <scenario|all|--list> [--quick] [tests/Load/run.php options...]
#   e.g. bin/load-test.sh noop --quick
#        bin/load-test.sh all --repetitions 3 --gate
#
# Scenarios: noop, state, runs, fanout, long-journal, overload (tests/Load/LoadScenario.php).
# --quick runs each at a small rate for about 20 s after a 5 s warm-up (a minute or two per
# scenario with startup); without it, 90 s windows after a 30 s warm-up, 3 repetitions.
#
# Environment:
#   LOAD_ENDPOINT_IMAGE      the endpoint image (default restate.local/restate-php-frankenphp:extension-zts-release,
#                            built here from the frankenphp-extension-release target). Any image that serves
#                            tests/Load/Support/endpoint.php on port 8080 with the repository at /app works,
#                            e.g. a FrankenPHP worker-mode or a php-fpm image; set LOAD_SKIP_BUILD=1 for it.
#   LOAD_SKIP_BUILD=1        reuse the existing image
#   LOAD_FRANKENPHP_BASE_IMAGE  FrankenPHP base image (Dockerfile FRANKENPHP_IMAGE build arg)
#   LOAD_RESULTS_DIR         where result JSON files accumulate (default var/load/results); the rolling
#                            median compares with the last 5 comparable files there
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
IMAGE=${LOAD_ENDPOINT_IMAGE:-restate.local/restate-php-frankenphp:extension-zts-release}

if [ $# -eq 0 ]; then
  sed -n '2,24p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
  exit 2
fi

if [ "$1" = --list ]; then
  exec php "$ROOT_DIR/tests/Load/run.php" --list
fi

if docker_bin=$(command -v docker); then :; elif docker_bin=$(command -v podman); then :; else
  echo "load-test: needs docker or podman on PATH" >&2
  exit 2
fi
command -v k6 >/dev/null || { echo "load-test: needs k6 on PATH (the dev shell has it)" >&2; exit 2; }

if [ "${LOAD_SKIP_BUILD:-0}" != 1 ]; then
  build_args=()
  if [ -n "${LOAD_FRANKENPHP_BASE_IMAGE:-}" ]; then
    build_args+=(--build-arg "FRANKENPHP_IMAGE=$LOAD_FRANKENPHP_BASE_IMAGE")
  fi
  "$docker_bin" build "${build_args[@]}" --target frankenphp-extension-release \
    -f "$ROOT_DIR/docker/Dockerfile" -t "$IMAGE" "$ROOT_DIR" >&2
fi

LOAD_ENDPOINT_IMAGE=$IMAGE exec php "$ROOT_DIR/tests/Load/run.php" "$@"
