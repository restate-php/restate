#!/usr/bin/env bash
set -euo pipefail

# The image enables the extension in php.ini (`extension=restate`), so plain `php` loads it.

PHP_SERVER_PID=""
CADDY_PID=""

# shellcheck disable=SC2329 # called by the EXIT trap
cleanup() {
  if [ -n "$PHP_SERVER_PID" ]; then
    kill "$PHP_SERVER_PID" >/dev/null 2>&1 || true
    wait "$PHP_SERVER_PID" >/dev/null 2>&1 || true
  fi
  if [ -n "$CADDY_PID" ]; then
    kill "$CADDY_PID" >/dev/null 2>&1 || true
    wait "$CADDY_PID" >/dev/null 2>&1 || true
  fi
}

trap cleanup EXIT

php -r '
if (!extension_loaded("restate")) {
    fwrite(STDERR, "Restate PHP extension did not load\n");
    exit(1);
}
require "/app/vendor/autoload.php";
new Restate\Internal\Backend\Extension\Backend();
'

php -S 127.0.0.1:9081 -t test-services test-services/index.php &
PHP_SERVER_PID=$!

caddy run --config /app/docker/caddy/Caddyfile &
CADDY_PID=$!

status=0
wait -n "$PHP_SERVER_PID" "$CADDY_PID" || status=$?
if [ "$status" -eq 0 ]; then
  exit 1
fi
exit "$status"
