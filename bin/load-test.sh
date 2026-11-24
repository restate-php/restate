#!/usr/bin/env bash
# Load tests (TESTING.md, Load tests): starts tests/load/compose.yaml, runs k6 (tests/load/k6.js)
# for each scenario with the endpoint limits it asks for, and removes the project at the end.
#
# Usage: bin/load-test.sh <scenario|all> [--full] [--keep]
#        bin/load-test.sh down
#
# --full: 90 s windows after a 30 s warm-up instead of about 20 s after 5 s. --keep leaves the
# project running (later runs reuse Restate); `down` removes it. Exit 1 when a scenario's checks
# fail. Results: var/load/results/<scenario>/<UTC time>-<commit>.json, k6's log next to it
# (.log). LOAD_SKIP_BUILD=1 reuses the endpoint image; compose.yaml lists the other settings.
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
SCENARIOS=$ROOT_DIR/tests/load/scenarios.json
compose() { docker compose --file "$ROOT_DIR/tests/load/compose.yaml" "$@"; }

case ${1:-} in
  '') sed -n '2,11p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2 && exit 2 ;;
  down) exec compose down --volumes ;;
  all) mapfile -t names < <(jq -r 'keys_unsorted[]' "$SCENARIOS") ;;
  *) names=("$1") ;;
esac
shift
mode=quick keep=0
for option in "$@"; do
  case $option in
    --full) mode=full ;;
    --keep) keep=1 ;;
    *) echo "load-test: unknown option $option" >&2 && exit 2 ;;
  esac
done

if [ "${LOAD_SKIP_BUILD:-0}" != 1 ]; then compose build endpoint; fi
[ $keep = 1 ] || trap 'compose down --volumes' EXIT
commit=$(git -C "$ROOT_DIR" rev-parse --short=12 HEAD)
status=0
for name in "${names[@]}"; do
  # The endpoint is recreated when the limits change (overload: 1 CPU, 4 PHP threads).
  LOAD_ENDPOINT_CPUS=$(jq -r --arg name "$name" '.[$name].endpoint_cpus // 0' "$SCENARIOS") \
    LOAD_NUM_THREADS=$(jq -r --arg name "$name" '.[$name].endpoint_threads // 0' "$SCENARIOS") \
    compose up --detach --no-build
  result=$ROOT_DIR/var/load/results/$name/$(date -u +%Y%m%dT%H%M%SZ)-$commit
  mkdir -p "$(dirname "$result")"
  # k6's log (a warning per timed-out call: thousands under overload) goes next to the result.
  k6 run --quiet --no-usage-report -e LOAD_SCENARIO="$name" -e LOAD_MODE="$mode" -e LOAD_COMMIT="$commit" \
    -e LOAD_SUMMARY="$result.json" "$ROOT_DIR/tests/load/k6.js" 2>"$result.log" || status=1
done
exit $status
