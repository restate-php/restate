#!/usr/bin/env bash
# Calls Greeter/greet through Restate's ingress and waits for the result.
#
#   ./scripts/greet.sh [name]
set -euo pipefail

name="${1:-Sarah}"
ingress="${RESTATE_INGRESS_URL:-http://localhost:8080}"

curl --fail-with-body --silent --show-error "$ingress/Greeter/greet" --json "{\"name\": \"$name\"}"
echo
