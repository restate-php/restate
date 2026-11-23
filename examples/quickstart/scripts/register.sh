#!/usr/bin/env bash
# Registers a PHP endpoint with Restate's admin API. compose.yaml's register service does this for the greeter
# container; use this script when PHP runs on your machine and Restate in Docker (README: "Run PHP on your machine").
#
#   ./scripts/register.sh [endpoint URL]
set -euo pipefail

uri="${1:-http://host.docker.internal:9080}"
admin="${RESTATE_ADMIN_URL:-http://localhost:9070}"

curl --fail-with-body --silent --show-error "$admin/deployments" --json "{\"uri\": \"$uri\", \"use_http_11\": true, \"force\": true}"
echo
