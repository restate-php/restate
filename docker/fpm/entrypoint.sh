#!/usr/bin/env bash
# Entrypoint of the fpm-extension image (docker/fpm/Dockerfile): php-fpm behind one front door, both in the
# foreground; the container stops when either exits. The configuration is rendered from docker/fpm/*.template
# at start, from these variables:
#
#   RESTATE_PHP_FPM_FRONT       nginx (HTTP/1.1), nginx-h2c (h2c and HTTP/1.1), caddy (h2c and HTTP/1.1), or apache
#                               (HTTP/1.1; the fpm-extension-apache image only). Default nginx.
#   RESTATE_PHP_FPM_PROFILE     recommended (docs/serving.md's body and timeout settings) or defaults (the front
#                               door's own defaults, for the serving-hazards tests). Default recommended.
#   RESTATE_PHP_FPM_PORT        port the front door listens on. Default 8080.
#   RESTATE_PHP_FPM_SCRIPT      the router script every request runs. Default the integration suite's endpoint.php.
#   RESTATE_PHP_FPM_READ_TIMEOUT                seconds the front door waits for php-fpm (recommended profile).
#                                               Default 610, above Restate's default abort timeout.
#   RESTATE_PHP_FPM_REQUEST_TERMINATE_TIMEOUT   php-fpm request_terminate_timeout. Default 0 (none).
#   RESTATE_PHP_FPM_CLEAR_ENV                   php-fpm clear_env. Default no, as in the official images.
#   RESTATE_PHP_FPM_PROCESS_CONTROL_TIMEOUT     php-fpm process_control_timeout: how long a graceful reload or
#                                               stop waits for requests in flight. Default 0 (php-fpm's: none).
set -euo pipefail

export RESTATE_PHP_FPM_FRONT=${RESTATE_PHP_FPM_FRONT:-nginx}
export RESTATE_PHP_FPM_PROFILE=${RESTATE_PHP_FPM_PROFILE:-recommended}
export RESTATE_PHP_FPM_PORT=${RESTATE_PHP_FPM_PORT:-8080}
export RESTATE_PHP_FPM_SCRIPT=${RESTATE_PHP_FPM_SCRIPT:-/app/tests/Integration/Support/endpoint.php}
export RESTATE_PHP_FPM_READ_TIMEOUT=${RESTATE_PHP_FPM_READ_TIMEOUT:-610}
export RESTATE_PHP_FPM_REQUEST_TERMINATE_TIMEOUT=${RESTATE_PHP_FPM_REQUEST_TERMINATE_TIMEOUT:-0}
export RESTATE_PHP_FPM_CLEAR_ENV=${RESTATE_PHP_FPM_CLEAR_ENV:-no}
export RESTATE_PHP_FPM_PROCESS_CONTROL_TIMEOUT=${RESTATE_PHP_FPM_PROCESS_CONTROL_TIMEOUT:-0}
RESTATE_PHP_FPM_ROOT=$(dirname "$RESTATE_PHP_FPM_SCRIPT")
RESTATE_PHP_FPM_SCRIPT_NAME=$(basename "$RESTATE_PHP_FPM_SCRIPT")
export RESTATE_PHP_FPM_ROOT RESTATE_PHP_FPM_SCRIPT_NAME

TEMPLATES=/etc/restate-fpm
CONFIG=/run/restate-fpm
mkdir -p "$CONFIG"

# Only these variables are substituted: nginx's own $variables stay as they are.
# shellcheck disable=SC2016 # envsubst's list of variable names, not an expansion
render() {
  envsubst '${RESTATE_PHP_FPM_PORT} ${RESTATE_PHP_FPM_SCRIPT} ${RESTATE_PHP_FPM_ROOT} ${RESTATE_PHP_FPM_SCRIPT_NAME} ${RESTATE_PHP_FPM_READ_TIMEOUT} ${RESTATE_PHP_FPM_REQUEST_TERMINATE_TIMEOUT} ${RESTATE_PHP_FPM_CLEAR_ENV} ${RESTATE_PHP_FPM_PROCESS_CONTROL_TIMEOUT} ${RESTATE_PHP_FPM_HTTP2} ${RESTATE_PHP_FPM_APACHE_SENDCL} ${RESTATE_PHP_FPM_APACHE_TIMEOUT}' < "$TEMPLATES/$1" > "$2"
}

case "$RESTATE_PHP_FPM_PROFILE" in
  recommended) suffix= ;;
  defaults) suffix=.defaults ;;
  *) echo "RESTATE_PHP_FPM_PROFILE must be recommended or defaults, got '$RESTATE_PHP_FPM_PROFILE'" >&2; exit 64 ;;
esac

if [ ! -r "$RESTATE_PHP_FPM_SCRIPT" ]; then
  echo "The router script $RESTATE_PHP_FPM_SCRIPT does not exist (is the repository mounted at /app?)" >&2
  exit 66
fi

render php-fpm.conf.template /usr/local/etc/php-fpm.d/zz-restate.conf

case "$RESTATE_PHP_FPM_FRONT" in
  nginx|nginx-h2c)
    export RESTATE_PHP_FPM_HTTP2=off
    [ "$RESTATE_PHP_FPM_FRONT" = nginx-h2c ] && RESTATE_PHP_FPM_HTTP2=on
    render "nginx$suffix.conf.template" "$CONFIG/nginx.conf"
    front=(nginx -c "$CONFIG/nginx.conf")
    ;;
  caddy)
    render "Caddyfile$suffix.template" "$CONFIG/Caddyfile"
    front=(caddy run --adapter caddyfile --config "$CONFIG/Caddyfile")
    ;;
  apache)
    if ! command -v apache2 >/dev/null; then
      echo "RESTATE_PHP_FPM_FRONT=apache needs the fpm-extension-apache image" >&2
      exit 64
    fi
    if [ "$RESTATE_PHP_FPM_PROFILE" = recommended ]; then
      export RESTATE_PHP_FPM_APACHE_SENDCL='SetEnv proxy-sendcl 1'
      export RESTATE_PHP_FPM_APACHE_TIMEOUT="ProxyTimeout $RESTATE_PHP_FPM_READ_TIMEOUT"
    else
      export RESTATE_PHP_FPM_APACHE_SENDCL='# proxy-sendcl not set'
      export RESTATE_PHP_FPM_APACHE_TIMEOUT='# ProxyTimeout not set'
    fi
    render apache.conf.template "$CONFIG/apache.conf"
    front=(apache2 -f "$CONFIG/apache.conf" -DFOREGROUND)
    ;;
  *)
    echo "RESTATE_PHP_FPM_FRONT must be nginx, nginx-h2c, caddy or apache, got '$RESTATE_PHP_FPM_FRONT'" >&2
    exit 64
    ;;
esac

php-fpm --test --allow-to-run-as-root >/dev/null

FPM_PID=""
FRONT_PID=""
# shellcheck disable=SC2329 # called by the EXIT trap
cleanup() {
  for pid in "$FRONT_PID" "$FPM_PID"; do
    if [ -n "$pid" ]; then
      kill "$pid" >/dev/null 2>&1 || true
      wait "$pid" >/dev/null 2>&1 || true
    fi
  done
}
# shellcheck disable=SC2329 # called by the QUIT trap
stop_gracefully() {
  # php-fpm finishes the requests it is serving, then the front door goes.
  kill -QUIT "$FPM_PID" 2>/dev/null || true
  wait "$FPM_PID" 2>/dev/null || true
  FPM_PID=""
  exit 0
}
trap cleanup EXIT
trap 'exit 143' TERM INT
# The php images' stop signal (`docker stop`): a graceful stop.
trap stop_gracefully QUIT
# A signal to the container (RestateTestEnvironment::signalEndpoint()) reaches this script; USR1 (reopen logs) and
# USR2 (graceful reload) are meant for the php-fpm master.
trap 'kill -USR1 "$FPM_PID" 2>/dev/null || true' USR1
trap 'kill -USR2 "$FPM_PID" 2>/dev/null || true' USR2

php-fpm --nodaemonize --allow-to-run-as-root &
FPM_PID=$!

"${front[@]}" &
FRONT_PID=$!

while true; do
  status=0
  wait -n "$FPM_PID" "$FRONT_PID" || status=$?
  # A trapped signal interrupts the wait while both still run.
  if kill -0 "$FPM_PID" 2>/dev/null && kill -0 "$FRONT_PID" 2>/dev/null; then
    continue
  fi
  break
done
if [ "$status" -eq 0 ]; then
  exit 1
fi
exit "$status"
