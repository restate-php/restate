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

  # A profile that serves the integration suite's endpoint outside the test harness, with Restate
  # next to it (devenv-extras' services.restate): `devenv --profile <name> up` runs both, and
  # `devenv --profile <name> test` runs the suite against them (composer test:integration:external,
  # RESTATE_PHP_TEST_RESTATE=external). The shell exports what the suite connects to
  # (tests/Integration/Support/IntegrationEndpoint.php). The tests that start, stop or configure
  # Restate or the endpoint themselves (#[Group('managed')]) run under the harness instead:
  # docs/decisions/0039-external-test-environments.md.
  #
  # server: the RESTATE_PHP_TEST_SERVER value of what serves the endpoint; process: its devenv
  # process; url: where it listens; http2: whether Restate reaches it over h2c; tests: more test
  # directories for enterTest.
  externalSuite =
    {
      server,
      process,
      url,
      http2 ? false,
      tests ? [ ],
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
        composer test:integration:external${
          lib.optionalString (tests != [ ]) " -- ${lib.escapeShellArgs tests}"
        }
      '';
    };

  # FrankenPHP with the extension, rebuilt for its thread-safe PHP from the shell's PHP
  # (languages.php.version), serving docker/frankenphp/<caddyfile> from the working tree, as the
  # frankenphp-extension image serves it from /app. The extension is the Nix build (the overlay's),
  # not the cargo build the harness loads; the shell's PHP does not load it.
  frankenphpSuite =
    {
      server,
      caddyfile,
      tests ? [ ],
    }:
    { config, ... }:
    let
      url = "http://127.0.0.1:${toString config.processes.frankenphp.ports.http.value}";
    in
    {
      imports = [
        (externalSuite {
          inherit server url tests;
          process = "frankenphp";
        })
      ];

      languages.php.frankenphp = {
        enable = true;
        package = pkgs.frankenphp.override {
          php = config.languages.php.package.withExtensions ({ enabled, all }: enabled ++ [ all.restate ]);
        };
        serve = {
          enable = true;
          root = "tests/Integration/Support";
          caddyfile = "docker/frankenphp/${caddyfile}";
        };
      };

      processes.frankenphp.env = {
        RESTATE_PHP_TEST_APP_DIR = config.devenv.root;
        RESTATE_PHP_TEST_LISTEN = "http://:${toString config.processes.frankenphp.ports.http.value}";
      };
    };
in
{
  # The extension's Nix builds, from its flake (extension/flake.nix, the ext-restate input in
  # devenv.yaml), as `devenv build outputs.<name>` (TESTING.md, Nix builds).
  outputs = {
    # Built against the dev shell's PHP (pkgs.php, or languages.php.version), through the
    # flake's overlay.
    # Not loaded into the shell: the tests load the cargo build (composer build:extension).
    extension = config.languages.php.package.extensions.restate;

    # The 24 PIE archives of a release with SHA256SUMS, cross-compiled from any platform
    # (composer build:pie).
    inherit (extension.packages.${system}) pie;

    # The flake's checks: its .phpt tests with PHP 8.4, 8.5 and 8.6 (outputs.checks.<name>
    # for one).
    checks = extension.checks.${system};
  };

  # ext-php-rs: what ext-php-rs.nix's CI builds for this nixpkgs, the PHP headers of each target
  # and cargo-zigbuild, which the PIE archives would otherwise build first.
  # restate-php: what this repository's CI builds (the Rust toolchain, the extension's Nix
  # builds), pushed from every job with CACHIX_AUTH_TOKEN.
  cachix.pull = [
    "ext-php-rs"
    "restate-php"
  ];

  env = {
    LIBCLANG_PATH = "${pkgs.libclang.lib}/lib";
  };

  packages =
    with pkgs;
    [
      curl
      git
      # The upstream SDK test suite (restatedev/e2e sdk-tests.jar) is built for Java 25.
      jdk25_headless
      # Only the library bindgen loads (LIBCLANG_PATH): the `libclang` package's bin/ would put an
      # unwrapped clang ahead of the Nix cc wrapper, and the cc crate (CC=clang) would compile
      # ext-php-rs's C shim with it, failing on `stdlib.h`.
      libclang.lib
      config.languages.php.package.unwrapped.dev
      # composer check:scripts
      actionlint
      shellcheck
      # k6 for the load tests (composer load).
      k6
      # restate-server, for the integration suite with RESTATE_PHP_TEST_RESTATE=process (TESTING.md). Its version
      # must be RestateContainer::DEFAULT_IMAGE's; .github/workflows/ci.yaml checks it.
      restate-bin
    ]
    # Benchmarks (TESTING.md, Performance): callgrind instruction counts for the PHP
    # benchmarks' backstop and for gungraun (composer bench:extension). nixpkgs marks
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
    # FrankenPHP's worker mode: worker.php builds the endpoints once per worker. The worker suite's
    # WorkerModeTest too (WorkerIsolationTest is managed).
    frankenphp-worker.module = frankenphpSuite {
      server = "frankenphp-worker";
      caddyfile = "worker.Caddyfile";
      tests = [ "tests/Worker" ];
    };
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
