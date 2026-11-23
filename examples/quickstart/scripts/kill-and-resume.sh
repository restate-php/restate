#!/usr/bin/env bash
# Durable execution, demonstrated: stop the PHP service while a greeting sleeps, start it again, and watch Restate
# finish the greeting without sending the notification twice.
#
#   ./scripts/kill-and-resume.sh [name]
set -euo pipefail
cd "$(dirname "$0")/.."

name="${1:-Mary}"
ingress="${RESTATE_INGRESS_URL:-http://localhost:8080}"

step() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

step "Send a greeting for $name without waiting for the result"
response=$(curl --fail-with-body --silent --show-error "$ingress/restate/send/Greeter/greet" --json "{\"name\": \"$name\"}")
echo "$response"
id=$(sed -E 's/.*"invocationId" *: *"([^"]+)".*/\1/' <<<"$response")

step "The handler sent the notification and now sleeps for 5 seconds; no PHP code runs meanwhile"
sleep 2
docker compose logs --no-log-prefix greeter | grep -F "$id" || true

step "Kill the PHP service"
docker compose kill greeter

step "The timer fires, Restate cannot reach the service, and keeps retrying"
sleep 6
docker compose exec -T restate restate invocations describe "$id" || true

step "Start the PHP service again"
docker compose start greeter

step "Wait for the result"
curl --fail-with-body --silent --show-error "$ingress/restate/invocation/$id/attach"
echo

step "The service's log for this invocation: one notification, one reminder, the same greeting id"
docker compose logs --no-log-prefix greeter | grep -F "$id"
