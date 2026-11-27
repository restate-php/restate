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
in
{
  imports = [
    ./nix/modules/extension.nix
    ./nix/modules/frankenphp.nix
  ];

  # The repository's own packages (nix/packages): cargo-artifacts, restate-sdk-tests.
  overlays = [ (import ./nix/overlay.nix) ];

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

  packages =
    with pkgs;
    [
      curl
      git
      # The task runner (justfile) and what its recipes use.
      just
      cargo-artifacts
      # The SDK conformance suite, with its Java (just test conformance).
      restate-sdk-tests
      # just lint
      actionlint
      shellcheck
      # k6 and jq for the load tests (just load run).
      k6
      jq
      # restate-server (nix-restate's overlay), for the integration suite with
      # RESTATE_PHP_TEST_RESTATE=process (TESTING.md). Its version must be
      # RestateContainer::DEFAULT_IMAGE's; .github/workflows/ci.yaml checks it.
      restate-bin
    ]
    # Benchmarks (TESTING.md, Performance): callgrind instruction counts for the PHP
    # benchmarks' backstop and for gungraun (just extension bench). nixpkgs marks
    # valgrind broken on Darwin (it has no macOS/Apple Silicon support), so Linux only.
    ++ lib.optionals stdenv.isLinux [ pkgs.valgrind ];

  profiles = {
    # The load tests: Restate and the load endpoint, without Docker.
    load.module = ./nix/modules/load.nix;
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
