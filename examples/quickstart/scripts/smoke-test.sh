#!/usr/bin/env bash
# Runs the whole quickstart and checks every step's output: CI runs it so the walkthrough doesn't rot.
#
#   ./scripts/smoke-test.sh          the released extension and packages (PIE and Packagist)
#   ./scripts/smoke-test.sh --local  this checkout of the SDK (compose.local.yaml)
set -euo pipefail
cd "$(dirname "$0")/.."

ingress="${RESTATE_INGRESS_URL:-http://localhost:8080}"
export COMPOSE_FILE=compose.yaml
if [[ "${1:-}" == "--local" ]]; then
    docker build -f ../../docker/Dockerfile --target quickstart-php-restate -t restate-php-quickstart/php-restate:local ../..
    export COMPOSE_FILE=compose.yaml:compose.local.yaml
fi

fail() {
    echo "FAIL: $*" >&2
    docker compose logs >&2 || true
    exit 1
}
cleanup() { docker compose down --volumes --remove-orphans > /dev/null 2>&1 || true; }
trap cleanup EXIT

docker compose up --build --detach

echo "Waiting for the registration"
for _ in $(seq 1 90); do
    state=$(docker compose ps --all --format '{{.State}} {{.ExitCode}}' register 2> /dev/null || true)
    case "$state" in
        "exited 0") break ;;
        exited*) fail "register exited: $state" ;;
    esac
    sleep 2
done
[[ "$state" == "exited 0" ]] || fail "register did not finish: $state"

# Capture before grepping: with pipefail, `grep -q` closing the pipe early would fail the pipeline.
services=$(curl -fsS "${RESTATE_ADMIN_URL:-http://localhost:9070}/services")
for service in Greeter Counter Signup; do
    grep -q "\"name\":\"$service\"" <<<"$services" || fail "Restate has no $service service: $services"
done
echo "ok: registered Greeter, Counter and Signup"
register_log=$(docker compose logs --no-log-prefix register)
grep -q '"name":"Greeter"' <<<"$register_log" || fail "docker compose logs register does not show the deployment: $register_log"
echo "ok: docker compose logs register shows the deployment"

expect() {
    local what="$1" expected="$2" actual="$3"
    [[ "$actual" == "$expected" ]] || fail "$what: expected $expected, got $actual"
    echo "ok: $what"
}

# The first call can meet a server that is still starting its partitions: retry it for a while.
for _ in $(seq 1 30); do
    greeting=$(./scripts/greet.sh Sarah 2> /dev/null) && break
    sleep 1
done
expect "greet Sarah" '{"message":"You said hi to Sarah!"}' "${greeting:-}"

expect "greet Alice (retries simulated failures)" '{"message":"You said hi to Alice!"}' "$(./scripts/greet.sh Alice)"

output=$(./scripts/kill-and-resume.sh Mary)
echo "$output"
grep -q 'You said hi to Mary!' <<<"$output" || fail "kill-and-resume: no result"
id=$(grep -o 'inv_[0-9A-Za-z]*' <<<"$output" | head -n 1)
log=$(docker compose logs --no-log-prefix greeter | grep -F "$id")
expect "kill-and-resume: notifications sent" 1 "$(grep -c 'Notification sent' <<<"$log")"
expect "kill-and-resume: reminders sent" 1 "$(grep -c 'Reminder sent' <<<"$log")"

expect "Counter add 1" 1 "$(curl -fsS "$ingress/restate/call/Counter/smoke/add" --json 1)"
expect "Counter add 2" 3 "$(curl -fsS "$ingress/restate/call/Counter/smoke/add" --json 2)"
expect "Counter get" 3 "$(curl -fsS -X POST "$ingress/restate/call/Counter/smoke/get")"

curl -fsS "$ingress/restate/send/Signup/smoke/run" --json '"smoke@example.com"' > /dev/null
secret=""
for _ in $(seq 1 30); do
    secret=$(docker compose logs --no-log-prefix greeter | grep -o 'Verification secret for smoke@example.com: [0-9a-f-]*' | awk '{print $NF}' | tail -n 1)
    [[ -n "$secret" ]] && break
    sleep 1
done
[[ -n "$secret" ]] || fail "Signup: no verification secret in the log"
curl -fsS "$ingress/restate/call/Signup/smoke/click" --json "\"$secret\"" > /dev/null
expect "Signup result" '"Welcome, smoke@example.com!"' "$(curl -fsS "$ingress/restate/workflow/Signup/smoke/attach")"

echo "The quickstart works."
