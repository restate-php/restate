# FrankenPHP with the Restate extension, rebuilt for its thread-safe PHP from the shell's PHP
# (languages.php.version), serving a Caddyfile from the working tree, which reads the repository
# and the site address from RESTATE_PHP_TEST_APP_DIR and RESTATE_PHP_TEST_LISTEN (the load
# profile's endpoint, nix/modules/load.nix). The extension is the Nix build (the overlay's, a
# release build), not the cargo build the harness loads; the shell's PHP does not load it.
{
  config,
  lib,
  pkgs,
  ...
}:

let
  cfg = config.restate-php.frankenphp;

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
  options.restate-php.frankenphp = {
    enable = lib.mkEnableOption "FrankenPHP with the Restate extension, serving a Caddyfile from the working tree";

    root = lib.mkOption {
      type = lib.types.str;
      description = "The directory FrankenPHP serves, relative to the repository.";
    };

    caddyfile = lib.mkOption {
      type = lib.types.str;
      description = "The Caddyfile, relative to the repository.";
    };
  };

  config = lib.mkIf cfg.enable {
    languages.php.frankenphp = {
      enable = true;
      package = pkgs.frankenphp.override {
        php = config.languages.php.package.withExtensions (
          { enabled, all }: lib.filter (extension: !brokenTokenizer extension) enabled ++ [ all.restate ]
        );
      };
      serve = {
        enable = true;
        inherit (cfg) root caddyfile;
      };
    };

    processes.frankenphp.env = {
      RESTATE_PHP_TEST_APP_DIR = config.devenv.root;
      RESTATE_PHP_TEST_LISTEN = "http://:${toString config.processes.frankenphp.ports.http.value}";
    };
  };
}
