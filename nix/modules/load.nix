# The load tests (tests/load, TESTING.md) without Docker, the `load` profile: Restate and the load
# endpoint as devenv processes on loopback, with the settings tests/load/compose.yaml gives them
# (the fixed signing key, 4 partitions, tests/load/Caddyfile and php.ini). `devenv --profile load
# up` runs both, and the profile's shell exports the URLs k6 reads, so `k6 run -e
# LOAD_SCENARIO=noop tests/load/k6.js` runs against them; `devenv --profile load test` runs every
# scenario but overload, whose CPU cap only a container gives.
{ config, pkgs, ... }:

let
  url = "http://127.0.0.1:${toString config.processes.frankenphp.ports.http.value}";
in
{
  restate-php.frankenphp = {
    enable = true;
    root = "tests/load";
    caddyfile = "tests/load/Caddyfile";
  };

  languages.php.frankenphp.serve.ini = builtins.readFile ../../tests/load/php.ini;
  # The request log, the effects ledger and where /load-stats counts from.
  processes.frankenphp.env.LOAD_STATE_DIR = config.devenv.runtime;

  services.restate = {
    enable = true;
    package = pkgs.restate-bin;
    settings = {
      default-num-partitions = 4;
      log-format = "compact";
      log-disable-ansi-codes = true;
      worker.invoker.request-identity-private-key-pem-file = "${config.devenv.root}/tests/load/request-identity.pem";
    };
  };

  env = {
    LOAD_INGRESS_URL = config.env.RESTATE_INGRESS_URL;
    LOAD_ADMIN_URL = config.env.RESTATE_ADMIN_URL;
    LOAD_ENDPOINT_URL = url;
    # Restate runs next to it, on loopback too.
    LOAD_ENDPOINT_URI = url;
  };

  enterTest = ''
    status=0
    for name in $(jq -r 'to_entries[] | select(.value.overload | not) | .key' tests/load/scenarios.json); do
      k6 run --quiet --no-usage-report -e LOAD_SCENARIO="$name" tests/load/k6.js || status=1
    done
    exit $status
  '';
}
