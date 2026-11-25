{
  pkgs,
  lib,
  config,
  inputs,
  ...
}:

let
  inherit (pkgs.stdenv.hostPlatform) system;
  extension = inputs.ext-restate;
  restate-bin = inputs.nix-restate.packages.${system}.restate-bin;

  # The paths of the files a `cargo build` produced, from its JSON messages (just extension build).
  # Not in nixpkgs.
  cargo-artifacts = pkgs.rustPlatform.buildRustPackage rec {
    pname = "cargo-artifacts";
    version = "0.3.0";
    src = pkgs.fetchFromGitHub {
      owner = "sagikazarmark";
      repo = "cargo-artifacts";
      tag = "v${version}";
      hash = "sha256-FrcIhlVueEHbHXylf7b/bszpjru6efkHGUWACA2a/Os=";
    };
    cargoHash = "sha256-KFfQaH4jbfzsi5B6X32MV9CMsIChVBURQ37/uOQYOck=";
  };

  # A profile that serves the integration suite's endpoint outside the test harness, with Restate
  # next to it (devenv-extras' services.restate): `devenv --profile <name> up` runs both, and
  # `devenv --profile <name> test` runs the suite against them (`RESTATE_PHP_TEST_RESTATE=external
  # just test integration <server>`). The shell exports what the suite connects to
  # (tests/Integration/Support/IntegrationEndpoint.php). The tests that start, stop or configure
  # Restate or the endpoint themselves (#[Group('managed')]) run under the harness instead:
  # docs/decisions/0039-external-test-environments.md.
  #
  # server: the RESTATE_PHP_TEST_SERVER value of what serves the endpoint; process: its devenv
  # process; url: where it listens; http2: whether Restate reaches it over h2c.
  externalSuite =
    {
      server,
      process,
      url,
      http2 ? false,
    }:
    { config, ... }:
    let
      stateDir = "${config.devenv.state}/restate-php-test";
      # Where devenv's process manager writes a process's output.
      logs = "${config.devenv.runtime}/processes/logs";
    in
    {
      services.restate = {
        enable = true;
        package = restate-bin;
        # As the harness runs Restate: plain lines, for the log tails of failed tests.
        settings = {
          log-format = "compact";
          log-disable-ansi-codes = true;
        };
        # The harness registers the endpoint too (forced, the same URI); this one is for `devenv up`
        # on its own, with the services in the UI and the CLI.
        deployments.integration = {
          uri = url;
          after = [ "devenv:processes:${process}" ];
          http1 = !http2;
        };
      };

      tasks = {
        # Restate and the endpoint start empty, as the harness's do: the suite's tests use fixed keys
        # (Counter/alice, workflow ids), which the state of an earlier run would change. It runs when
        # the processes start, not when a shell is entered: one run of the suite per `devenv up`, and
        # every `devenv test` starts afresh.
        "restate-php:fresh-state" = {
          exec = ''
            rm -rf ${
              lib.escapeShellArgs [
                config.services.restate.stateDir
                stateDir
              ]
            }
            mkdir -p ${lib.escapeShellArg stateDir}
          '';
          before = [
            "devenv:restate:setup"
            "devenv:processes:restate"
            "devenv:processes:${process}"
          ];
        };
        # The scratch directory the endpoint shares with the tests (RESTATE_PHP_TEST_STATE_DIR), for
        # a shell whose processes run elsewhere. Not before the processes: fresh-state creates it
        # then, and a mkdir while it is being removed fails ("File exists" on macOS).
        "restate-php:state-dir" = {
          exec = "mkdir -p ${lib.escapeShellArg stateDir}";
          before = [ "devenv:enterShell" ];
        };
      };
      processes.${process}.env.RESTATE_PHP_TEST_STATE_DIR = stateDir;

      env = {
        RESTATE_PHP_TEST_ENDPOINT_URL = url;
        RESTATE_PHP_TEST_ENDPOINT_SERVER = server;
        RESTATE_PHP_TEST_STATE_DIR = stateDir;
        RESTATE_PHP_TEST_ENDPOINT_LOG = "${logs}/${process}.stderr.log";
        RESTATE_PHP_TEST_RESTATE_LOG = "${logs}/restate.stdout.log";
      };

      enterTest = ''
        RESTATE_PHP_TEST_RESTATE=external just test integration ${lib.escapeShellArg server}
      '';
    };

  # FrankenPHP with the extension, rebuilt for its thread-safe PHP from the shell's PHP
  # (languages.php.version), serving a Caddyfile from the working tree, as the images serve the
  # same file from /app (RESTATE_PHP_TEST_APP_DIR, RESTATE_PHP_TEST_LISTEN). The extension is the
  # Nix build (the overlay's, a release build), not the cargo build the harness loads; the shell's
  # PHP does not load it.
  frankenphpServing =
    { root, caddyfile }:
    { config, ... }:
    let
      # nixpkgs' thread-safe tokenizer extension of PHP 8.6 does not link on Darwin: PHP 8.6 exports
      # the scanner globals as a native thread-local variable, and the separately built extension
      # fails on it ("illegal thread local variable reference to regular symbol
      # _language_scanner_globals"). The endpoint does not tokenize PHP, so FrankenPHP goes without
      # it there; the shell's PHP keeps its own (not thread-safe) tokenizer for the dev tools.
      brokenTokenizer =
        extension:
        pkgs.stdenv.hostPlatform.isDarwin
        && lib.versionAtLeast config.languages.php.package.version "8.6"
        && (extension.extensionName or null) == "tokenizer";
    in
    {
      languages.php.frankenphp = {
        enable = true;
        package = pkgs.frankenphp.override {
          php = config.languages.php.package.withExtensions (
            { enabled, all }: lib.filter (extension: !brokenTokenizer extension) enabled ++ [ all.restate ]
          );
        };
        serve = {
          enable = true;
          inherit root caddyfile;
        };
      };

      processes.frankenphp.env = {
        RESTATE_PHP_TEST_APP_DIR = config.devenv.root;
        RESTATE_PHP_TEST_LISTEN = "http://:${toString config.processes.frankenphp.ports.http.value}";
      };
    };

  # The integration suite's endpoint (docker/frankenphp/<caddyfile>), as the frankenphp-extension
  # image serves it, with Restate next to it (externalSuite).
  frankenphpSuite =
    {
      server,
      caddyfile,
    }:
    { config, ... }:
    {
      imports = [
        (frankenphpServing {
          root = "tests/Integration/Support";
          caddyfile = "docker/frankenphp/${caddyfile}";
        })
        (externalSuite {
          inherit server;
          process = "frankenphp";
          url = "http://127.0.0.1:${toString config.processes.frankenphp.ports.http.value}";
        })
      ];
    };

  # The load tests (tests/load, TESTING.md) without Docker: Restate and the load endpoint as devenv
  # processes on loopback, with the settings tests/load/compose.yaml gives them (the fixed signing
  # key, 4 partitions, tests/load/Caddyfile and php.ini). `devenv --profile load up` runs both, and
  # the profile's shell exports the URLs k6 reads, so `k6 run -e LOAD_SCENARIO=noop
  # tests/load/k6.js` runs against them; `devenv --profile load test` runs every scenario but
  # overload, whose CPU cap only a container gives.
  loadSuite =
    { config, ... }:
    let
      url = "http://127.0.0.1:${toString config.processes.frankenphp.ports.http.value}";
    in
    {
      imports = [
        (frankenphpServing {
          root = "tests/load";
          caddyfile = "tests/load/Caddyfile";
        })
      ];

      languages.php.frankenphp.serve.ini = builtins.readFile ./tests/load/php.ini;
      # The request log, the effects ledger and where /load-stats counts from.
      processes.frankenphp.env.LOAD_STATE_DIR = config.devenv.runtime;

      services.restate = {
        enable = true;
        package = restate-bin;
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
    };
in
{
  # The extension's Nix builds, from its flake (extension/flake.nix, the ext-restate input in
  # devenv.yaml), as `devenv build outputs.<name>` (TESTING.md, Nix builds).
  outputs = {
    # Built against the dev shell's PHP (pkgs.php, or languages.php.version), through the
    # flake's overlay.
    # Not loaded into the shell: the tests load the cargo build (just extension build).
    extension = config.languages.php.package.extensions.restate;

    # The flake's checks: its .phpt tests with PHP 8.4, 8.5 and 8.6 (outputs.checks.<name>
    # for one).
    checks = extension.checks.${system};
  };

  # What this repository's CI builds (the Rust toolchain, the extension's Nix builds), pushed from
  # every job with CACHIX_AUTH_TOKEN. ext-php-rs.cachix.org is not pulled: it had none of the
  # paths the PIE archives need that cache.nixos.org lacks.
  cachix.pull = [ "restate-php" ];

  env = {
    LIBCLANG_PATH = "${pkgs.libclang.lib}/lib";
  };

  packages =
    with pkgs;
    [
      curl
      git
      # The task runner (justfile) and what its recipes use.
      just
      cargo-artifacts
      # The upstream SDK test suite (restatedev/e2e sdk-tests.jar) is built for Java 25.
      jdk25_headless
      # Only the library bindgen loads (LIBCLANG_PATH): the `libclang` package's bin/ would put an
      # unwrapped clang ahead of the Nix cc wrapper, and the cc crate (CC=clang) would compile
      # ext-php-rs's C shim with it, failing on `stdlib.h`.
      libclang.lib
      config.languages.php.package.unwrapped.dev
      # just lint
      actionlint
      shellcheck
      # k6 and jq for the load tests (just load run).
      k6
      jq
      # restate-server, for the integration suite with RESTATE_PHP_TEST_RESTATE=process (TESTING.md). Its version
      # must be RestateContainer::DEFAULT_IMAGE's; .github/workflows/ci.yaml checks it.
      restate-bin
    ]
    # Benchmarks (TESTING.md, Performance): callgrind instruction counts for the PHP
    # benchmarks' backstop and for gungraun (just extension bench). nixpkgs marks
    # valgrind broken on Darwin (it has no macOS/Apple Silicon support), so Linux only.
    ++ lib.optionals stdenv.isLinux [ pkgs.valgrind ];

  # bindgen (ext-php-rs) parses the PHP headers with libclang, which does not know the
  # Nix libc/compiler include paths on its own; without this the extension build fails
  # on `stdlib.h`. Reuse the flags the Nix cc wrapper would pass.
  enterShell = ''
    export BINDGEN_EXTRA_CLANG_ARGS="$(< "$NIX_CC/nix-support/libc-cflags") $(< "$NIX_CC/nix-support/cc-cflags") $NIX_CFLAGS_COMPILE"
  '';

  # The integration suite against an endpoint and a Restate that devenv runs (externalSuite above):
  # `devenv --profile frankenphp test`, with `-O languages.php.version:string 8.4` for another PHP.
  profiles = {
    # FrankenPHP's classic mode: endpoint.php per request (RESTATE_PHP_TEST_SERVER=frankenphp).
    frankenphp.module = frankenphpSuite {
      server = "frankenphp";
      caddyfile = "integration.Caddyfile";
    };
    # FrankenPHP's worker mode: worker.php builds the endpoints once per worker. The recipe adds
    # the worker suite's WorkerModeTest (WorkerIsolationTest is managed).
    frankenphp-worker.module = frankenphpSuite {
      server = "frankenphp-worker";
      caddyfile = "worker.Caddyfile";
    };
    # The load tests: Restate and the load endpoint, without Docker (loadSuite above).
    load.module = loadSuite;
  };

  languages = {
    # nixpkgs' default PHP (pkgs.php); another minor with
    # `devenv -O languages.php.version:string 8.4 shell`.
    php.enable = true;

    rust = {
      enable = true;
      toolchainFile = ./extension/rust-toolchain.toml;

      # The toolchain file installs no rust-analyzer; the editor keeps the nixpkgs one.
      lsp.package = pkgs.rust-analyzer;
    };
  };
}
