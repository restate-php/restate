{
  pkgs,
  config,
  inputs,
  ...
}:

let
  inherit (pkgs.stdenv.hostPlatform) system;
  extension = inputs.ext-restate;
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
      inputs.nix-restate.packages.${pkgs.stdenv.hostPlatform.system}.restate-bin
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
