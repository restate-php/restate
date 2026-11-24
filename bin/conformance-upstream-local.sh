#!/usr/bin/env bash
# Run the upstream Restate SDK test suite (restatedev/e2e sdk-tests.jar) locally against a test-services image.
# CI runs the same suite through the restatedev/e2e/sdk-tests action (.github/workflows/upstream.yaml).
#
# Usage: bin/conformance-upstream-local.sh [target] [basic|full]
#
#   target  docker/Dockerfile target to build and test (default: upstream-extension-caddy)
#   mode    basic runs the `default` suite only, full runs every suite (default: full)
#
# Run it in the dev shell (it provides Java 25). Works with Docker and with rootless podman: under
# podman it starts a private podman API service for Testcontainers, disables Ryuk, and runs the
# suite sequentially. Artifacts (the jar, JUnit XML, container logs, the suite's output) go to
# var/conformance/upstream/<target>/<mode>/.
#
# Environment:
#   RESTATE_PHP_UPSTREAM_SKIP_BUILD=1     reuse an existing image instead of rebuilding
#   RESTATE_PHP_UPSTREAM_TEST_NAME        run only this test class (e.g. ProxyRequestSigning) in each suite
#   RESTATE_PHP_UPSTREAM_EXCLUSIONS_FILE  exclusions YAML (default test-services/exclusions.yaml; empty: none)
#   RESTATE_PHP_UPSTREAM_SEQUENTIAL=1     run test classes one at a time (default under podman)
#   RESTATE_PHP_UPSTREAM_OUTPUT_DIR       artifacts root (default var/conformance/upstream)
#   RESTATE_PHP_RESTATE_IMAGE             Restate image, as for the test harness (default RestateContainer::DEFAULT_IMAGE)
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
TARGET=${1:-upstream-extension-caddy}
MODE=${2:-full}

SUITE_VERSION=v2.3
JAR_URL="https://github.com/restatedev/e2e/releases/download/$SUITE_VERSION/sdk-tests.jar"
JAR_SHA256="f4452477a5f80a599da04175a7de1c1192d9165b46b445964df820ea1b117ffa"

case "$MODE" in
  basic|full) ;;
  *)
    echo "Usage: $0 [target] [basic|full]" >&2
    exit 64
    ;;
esac

if ! grep -Eq "^FROM .* AS ${TARGET}\$" "$ROOT_DIR/docker/Dockerfile"; then
  echo "Unknown docker/Dockerfile target: $TARGET" >&2
  exit 64
fi

# The jar is built for Java 25. An older java cannot load it, and the run then reports nothing
# instead of failing, so check up front.
REQUIRED_JAVA_MAJOR=25
if ! command -v java >/dev/null 2>&1; then
  echo "java not found; the upstream suite needs Java $REQUIRED_JAVA_MAJOR+. Run this inside \`devenv shell\`." >&2
  exit 1
fi
JAVA_MAJOR=$(java -XshowSettings:properties -version 2>&1 | sed -n 's/^ *java\.specification\.version = //p' | head -n 1)
JAVA_MAJOR=${JAVA_MAJOR#1.}
JAVA_MAJOR=${JAVA_MAJOR%%.*}
if ! [[ "$JAVA_MAJOR" =~ ^[0-9]+$ ]] || [ "$JAVA_MAJOR" -lt "$REQUIRED_JAVA_MAJOR" ]; then
  echo "java on PATH ($(command -v java)) is Java ${JAVA_MAJOR:-unknown}; the upstream suite needs Java $REQUIRED_JAVA_MAJOR+." >&2
  echo "Run this inside \`devenv shell\` (it provides JDK $REQUIRED_JAVA_MAJOR)." >&2
  exit 1
fi

OUTPUT_DIR=${RESTATE_PHP_UPSTREAM_OUTPUT_DIR:-"$ROOT_DIR/var/conformance/upstream"}
RUN_DIR="$OUTPUT_DIR/$TARGET/$MODE"
IMAGE="restate.local/restate-php-test-services:$TARGET"
EXCLUSIONS_FILE=${RESTATE_PHP_UPSTREAM_EXCLUSIONS_FILE-"$ROOT_DIR/test-services/exclusions.yaml"}
RESTATE_IMAGE=${RESTATE_PHP_RESTATE_IMAGE:-$(sed -n "s/.*DEFAULT_IMAGE = '\(.*\)';.*/\1/p" "$ROOT_DIR/packages/testing/src/RestateContainer.php")}
if [ -z "$RESTATE_IMAGE" ]; then
  echo "Could not read RestateContainer::DEFAULT_IMAGE; set RESTATE_PHP_RESTATE_IMAGE." >&2
  exit 1
fi

# The suite jar, pinned by checksum (the action CI uses downloads it by tag).
JAR="$OUTPUT_DIR/restate-e2e-sdk-tests-$SUITE_VERSION.jar"
mkdir -p "$OUTPUT_DIR"
if [ ! -r "$JAR" ]; then
  curl -L --fail --output "$JAR.part" "$JAR_URL"
  mv "$JAR.part" "$JAR"
fi
printf '%s  %s\n' "$JAR_SHA256" "$JAR" | sha256sum -c --quiet -

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

SEQUENTIAL=${RESTATE_PHP_UPSTREAM_SEQUENTIAL:-0}
if docker version 2>/dev/null | grep -q 'Podman Engine'; then
  if [ -z "${DOCKER_HOST:-}" ]; then
    # Run a private API service without an idle timeout. The socket-activated
    # podman.service exits after 5 s idle, which leaves Testcontainers' pooled
    # connections dead ("java.io.IOException: Broken pipe") between test classes.
    PODMAN_SOCKET="${XDG_RUNTIME_DIR:-/tmp}/restate-php-upstream-$$.sock"
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
  SEQUENTIAL=${RESTATE_PHP_UPSTREAM_SEQUENTIAL:-1}
fi

if [ "${RESTATE_PHP_UPSTREAM_SKIP_BUILD:-0}" != 1 ]; then
  docker build --target "$TARGET" -f "$ROOT_DIR/docker/Dockerfile" -t "$IMAGE" "$ROOT_DIR"
fi

args=(run "--restate-container-image=$RESTATE_IMAGE" --image-pull-policy=CACHED "--report-dir=$RUN_DIR")
if [ "$SEQUENTIAL" = 1 ]; then
  args+=(--sequential)
fi
if [ "$MODE" = basic ]; then
  args+=(--test-suite=default)
fi
if [ -n "${RESTATE_PHP_UPSTREAM_TEST_NAME:-}" ]; then
  # The suite tool only takes --test-name together with --test-suite.
  [ "$MODE" = full ] && args+=(--test-suite=all)
  args+=("--test-name=$RESTATE_PHP_UPSTREAM_TEST_NAME")
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
