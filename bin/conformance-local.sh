#!/usr/bin/env bash
# Run the Restate SDK conformance suite (restatedev/e2e sdk-tests.jar) against a test-services image. CI runs
# it the same way (.github/workflows/runtime.yaml).
#
# Usage: bin/conformance-local.sh [server] [basic|full]
#
#   server  frankenphp (the default), frankenphp-worker, fpm-nginx or fpm-caddy: the test-services image
#           to build and test is conformance-<server> of docker-bake.hcl (bin/build-image.sh)
#   mode    basic runs the `default` suite only, full runs every suite (default: full)
#
# Run it in the dev shell: it provides the suite's jar (RESTATE_PHP_CONFORMANCE_JAR, devenv.nix, pinned by
# hash) and the Java it needs (languages.java). Works with Docker and with rootless podman: under
# podman it starts a private podman API service for Testcontainers, disables Ryuk, and runs the
# suite sequentially. Artifacts (JUnit XML, container logs, the suite's output) go to
# var/conformance/<server>/<mode>/.
#
# Environment:
#   RESTATE_PHP_TEST_PHP_VERSION             the image's PHP minor: 8.4 (the default), 8.5 or 8.6
#   RESTATE_PHP_TEST_EXTENSION_PROFILE       the image's module: release (the default here: what users
#                                            install) or debug
#   RESTATE_PHP_TEST_LIBC                    the image's C library: glibc (the default) or musl (Alpine)
#   RESTATE_PHP_CONFORMANCE_SKIP_BUILD=1     reuse an existing image instead of rebuilding
#   RESTATE_PHP_CONFORMANCE_TEST_NAME        run only this test class (e.g. ProxyRequestSigning) in each suite
#   RESTATE_PHP_CONFORMANCE_EXCLUSIONS_FILE  exclusions YAML (default test-services/exclusions.yaml; empty: none)
#   RESTATE_PHP_CONFORMANCE_SEQUENTIAL=1     run test classes one at a time (default under podman)
#   RESTATE_PHP_CONFORMANCE_OUTPUT_DIR       artifacts root (default var/conformance)
#   RESTATE_PHP_CONFORMANCE_JAR              the suite's jar (the dev shell sets it; another jar to try one)
#   RESTATE_PHP_RESTATE_IMAGE                Restate image, as for the test harness (default RestateContainer::DEFAULT_IMAGE)
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
SERVER=${1:-frankenphp}
TARGET=conformance-$SERVER
MODE=${2:-full}
PHP_VERSION=${RESTATE_PHP_TEST_PHP_VERSION:-8.4}
VARIANT=${RESTATE_PHP_TEST_EXTENSION_PROFILE:-release}
LIBC=${RESTATE_PHP_TEST_LIBC:-glibc}

case "$MODE" in
  basic|full) ;;
  *)
    echo "Usage: $0 [server] [basic|full]" >&2
    exit 64
    ;;
esac

if ! docker buildx bake -f "$ROOT_DIR/docker-bake.hcl" --print "$TARGET-${PHP_VERSION//./}-$VARIANT" >/dev/null 2>&1; then
  echo "Unknown server, PHP or variant: $SERVER, $PHP_VERSION, $VARIANT (servers: frankenphp, frankenphp-worker, fpm-nginx, fpm-caddy)" >&2
  exit 64
fi

JAR=${RESTATE_PHP_CONFORMANCE_JAR:-}
if [ -z "$JAR" ] || [ ! -r "$JAR" ]; then
  echo "RESTATE_PHP_CONFORMANCE_JAR is not set or not readable: run this inside \`devenv shell\`, which provides the suite's jar and Java." >&2
  exit 1
fi

OUTPUT_DIR=${RESTATE_PHP_CONFORMANCE_OUTPUT_DIR:-"$ROOT_DIR/var/conformance"}
RUN_DIR="$OUTPUT_DIR/$SERVER/$MODE"
IMAGE="restate.local/restate-php-test-services:$TARGET"
EXCLUSIONS_FILE=${RESTATE_PHP_CONFORMANCE_EXCLUSIONS_FILE-"$ROOT_DIR/test-services/exclusions.yaml"}
RESTATE_IMAGE=${RESTATE_PHP_RESTATE_IMAGE:-$(sed -n "s/.*DEFAULT_IMAGE = '\(.*\)';.*/\1/p" "$ROOT_DIR/packages/testing/src/RestateContainer.php")}
if [ -z "$RESTATE_IMAGE" ]; then
  echo "Could not read RestateContainer::DEFAULT_IMAGE; set RESTATE_PHP_RESTATE_IMAGE." >&2
  exit 1
fi

# `docker --version` from podman's docker shim does not say podman; `docker version` does.
PODMAN_SERVICE_PID=""
PODMAN_SOCKET=""
# shellcheck disable=SC2329 # called by the EXIT trap
cleanup() {
  if [ -n "$PODMAN_SERVICE_PID" ]; then
    kill "$PODMAN_SERVICE_PID" >/dev/null 2>&1 || true
    wait "$PODMAN_SERVICE_PID" >/dev/null 2>&1 || true
    rm -f "$PODMAN_SOCKET"
  fi
}
trap cleanup EXIT

SEQUENTIAL=${RESTATE_PHP_CONFORMANCE_SEQUENTIAL:-0}
if docker version 2>/dev/null | grep -q 'Podman Engine'; then
  if [ -z "${DOCKER_HOST:-}" ]; then
    # Run a private API service without an idle timeout. The socket-activated
    # podman.service exits after 5 s idle, which leaves Testcontainers' pooled
    # connections dead ("java.io.IOException: Broken pipe") between test classes.
    PODMAN_SOCKET="${XDG_RUNTIME_DIR:-/tmp}/restate-php-conformance-$$.sock"
    podman system service --time=0 "unix://$PODMAN_SOCKET" &
    PODMAN_SERVICE_PID=$!
    for _ in $(seq 1 50); do
      [ -S "$PODMAN_SOCKET" ] && break
      sleep 0.1
    done
    if [ ! -S "$PODMAN_SOCKET" ]; then
      echo "podman system service did not create $PODMAN_SOCKET" >&2
      exit 1
    fi
    export DOCKER_HOST="unix://$PODMAN_SOCKET"
  fi
  export TESTCONTAINERS_RYUK_DISABLED=${TESTCONTAINERS_RYUK_DISABLED:-true}
  SEQUENTIAL=${RESTATE_PHP_CONFORMANCE_SEQUENTIAL:-1}
fi

if [ "${RESTATE_PHP_CONFORMANCE_SKIP_BUILD:-0}" != 1 ]; then
  bash "$ROOT_DIR/bin/build-image.sh" --php "$PHP_VERSION" --variant "$VARIANT" --libc "$LIBC" "$TARGET" "$IMAGE"
fi

args=(run "--restate-container-image=$RESTATE_IMAGE" --image-pull-policy=CACHED "--report-dir=$RUN_DIR")
if [ "$SEQUENTIAL" = 1 ]; then
  args+=(--sequential)
fi
if [ "$MODE" = basic ]; then
  args+=(--test-suite=default)
fi
if [ -n "${RESTATE_PHP_CONFORMANCE_TEST_NAME:-}" ]; then
  # The suite tool only takes --test-name together with --test-suite.
  [ "$MODE" = full ] && args+=(--test-suite=all)
  args+=("--test-name=$RESTATE_PHP_CONFORMANCE_TEST_NAME")
fi
if [ -n "$EXCLUSIONS_FILE" ]; then
  args+=("--exclusions-file=$EXCLUSIONS_FILE")
fi
args+=("--service-container-image=$IMAGE")

rm -rf "$RUN_DIR"
mkdir -p "$RUN_DIR"
echo "java -jar $JAR ${args[*]}" >&2

status=0
java -jar "$JAR" "${args[@]}" 2>&1 | tee "$RUN_DIR/sdk-tests.log" || status=$?

echo
echo "Image: $IMAGE ($(docker image inspect --format '{{.Id}}' "$IMAGE"))"
echo "Restate: $RESTATE_IMAGE"
echo "Artifacts: $RUN_DIR"
# One row per suite from the JUnit XML; a run that executed nothing fails.
summary_status=0
# shellcheck disable=SC2016 # PHP source, not shell
php -r '
$totals = [0, 0, 0, 0];
printf("%-36s %6s %8s %6s %7s\n", "suite", "tests", "failures", "errors", "skipped");
foreach (glob($argv[1] . "/*/TEST-*.xml") ?: [] as $file) {
    $xml = simplexml_load_file($file);
    if ($xml === false) {
        continue;
    }
    $row = [(int) $xml["tests"], (int) $xml["failures"], (int) $xml["errors"], (int) $xml["skipped"]];
    printf("%-36s %6d %8d %6d %7d\n", basename(dirname($file)), ...$row);
    foreach ($row as $i => $n) {
        $totals[$i] += $n;
    }
    foreach ($xml->testcase as $case) {
        if (isset($case->failure) || isset($case->error)) {
            printf("  FAIL %s :: %s\n", $case["classname"], $case["name"]);
        }
    }
}
printf("%-36s %6d %8d %6d %7d\n", "total", ...$totals);
if ($totals[0] === 0) {
    fwrite(STDERR, "No tests were executed (no JUnit results under " . $argv[1] . "); treating the run as failed.\n");
    exit(1);
}
' "$RUN_DIR" || summary_status=$?

if [ "$status" -eq 0 ] && [ "$summary_status" -ne 0 ]; then
  status=$summary_status
fi

exit "$status"
