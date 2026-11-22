{
  description = "The Restate PHP extension (module restate): for every PHP of nixpkgs, and PIE's prebuilt binaries";

  inputs = {
    ext-php-rs.url = "github:sagikazarmark/ext-php-rs.nix/v0.2.0";
    # ext-php-rs's nixpkgs, so its binary cache (ext-php-rs.cachix.org) has the expensive parts of
    # the portable builds: the PHP headers for each target and cargo-zigbuild. The dev shell of
    # restate-php/restate follows this one too (its devenv.yaml).
    nixpkgs.follows = "ext-php-rs/nixpkgs";
    # The toolchain rust-toolchain.toml pins, which portable builds add each target's standard
    # library to.
    rust-overlay = {
      url = "github:oxalica/rust-overlay";
      inputs.nixpkgs.follows = "nixpkgs";
    };
  };

  outputs =
    {
      self,
      nixpkgs,
      rust-overlay,
      ext-php-rs,
    }:
    let
      inherit (nixpkgs) lib;

      # nixpkgs with this flake's overlay: the extension in every PHP.
      forAllSystems =
        f:
        lib.genAttrs [ "x86_64-linux" "aarch64-linux" "aarch64-darwin" ] (
          system: f (nixpkgs.legacyPackages.${system}.extend self.overlays.default)
        );

      # Every build uses the toolchain of rust-toolchain.toml, as cargo and the Docker images do.
      rustPhpPlatform =
        pkgs:
        ext-php-rs.lib.makeRustPhpPlatform {
          inherit pkgs;
          rustToolchain = (rust-overlay.lib.mkRustBin { } pkgs).fromRustupToolchainFile ./rust-toolchain.toml;
        };

      # buildRustPhpExtension's arguments: pname (restate) and version from the crate's Cargo.toml,
      # the module name from composer.json, and only the files the build reads, so that changing
      # anything else (this flake, tests, docs) rebuilds nothing.
      args = ext-php-rs.lib.extensionArgs {
        root = ./extension;
        workspace = ./.;
        src = lib.fileset.toSource {
          root = ./.;
          fileset = lib.fileset.unions [
            ./Cargo.toml
            ./Cargo.lock
            ./.cargo
            ./core
            ./extension
          ];
        };
      };
      inherit (args) extensionName;

      # The PHP minors of composer.json's `php` constraint.
      phps = [
        "php84"
        "php85"
        "php86"
      ];
    in
    {
      # The extension for every PHP of nixpkgs, built against the PHP that loads it:
      #
      #   pkgs.php.withExtensions ({ enabled, all }: enabled ++ [ all.restate ])
      #   devenv: languages.php.extensions = [ "restate" ];
      overlays.default = ext-php-rs.lib.mkPhpExtensionsOverlay {
        extensions = [ args ];
        inherit rustPhpPlatform;
      };

      packages = forAllSystems (pkgs: {
        # lib/php/extensions/restate.so, for nixpkgs' default PHP.
        default = pkgs.php.extensions.${extensionName};

        # nixpkgs' default PHP with the extension: `nix run .#php -- --ri restate`.
        php = pkgs.php.withExtensions ({ enabled, all }: enabled ++ [ all.${extensionName} ]);

        # FrankenPHP with the extension, rebuilt for its thread-safe PHP:
        # `nix run .#frankenphp -- php-server --root tests/server --worker tests/server/worker.php`.
        frankenphp = pkgs.frankenphp.override {
          php = pkgs.php.withExtensions ({ enabled, all }: enabled ++ [ all.${extensionName} ]);
        };

        # Loads the Linux archives for this machine's CPU into PHP's official Docker images and
        # runs tests/*.phpt there: `nix build .#pie && nix run .#load-test -- result tests`. PHP
        # 8.6's official images are ZTS only, so it skips the 8.6 NTS archives.
        load-test = (rustPhpPlatform pkgs).loadPieArchives;

        # PIE's prebuilt binaries for a release (docs/decisions/0030-prebuilt-extension-binaries.md in
        # restate-php/restate): every PHP minor × NTS and ZTS × x86_64 and arm64 × Linux with
        # glibc 2.28 or newer and with musl, 24 archives with SHA256SUMS. Cross-compiled on any
        # of the systems.
        pie = (rustPhpPlatform pkgs).buildPieRelease {
          inherit args;
          php = map (php: pkgs.${php}) phps;
          targets = with (rustPhpPlatform pkgs).targets; [
            x86_64-linux-glibc
            aarch64-linux-glibc
            x86_64-linux-musl
            aarch64-linux-musl
          ];
        };
      });

      checks = forAllSystems (
        pkgs:
        let
          platform = rustPhpPlatform pkgs;
          inherit (self.packages.${pkgs.stdenv.hostPlatform.system}) php frankenphp;
        in
        # tests/*.phpt, run with each PHP minor with the extension loaded through the overlay, as
        # Nix users load it.
        lib.genAttrs phps (
          php:
          platform.runPhpTests {
            php = pkgs.${php};
            extensions = [ pkgs.${php}.extensions.${extensionName} ];
            src = ./tests;
          }
        )
        // {
          # tests/*.phpt with a thread-safe (ZTS) PHP, as FrankenPHP and PHP 8.6's official images
          # have: FrankenPHP's (nixpkgs' default PHP version, from its binary cache).
          zts = platform.runPhpTests {
            name = "php-tests-zts";
            inherit (pkgs.frankenphp) php;
            extensions = [ pkgs.frankenphp.php.extensions.${extensionName} ];
            src = ./tests;
          };

          # An invocation per request in FrankenPHP's workers (tests/server/worker.php): across
          # requests, after the workers restart (the module shut down and started again in the
          # same process), and with requests at once on PHP's threads.
          frankenphp = platform.runFrankenphpTests {
            inherit frankenphp;
            src = ./tests/server;
            worker = "worker.php";
            script = ''
              expect "/?name=Nix" 'inv-1 "Mary" => "Hello, Nix!"'
              for i in 1 2 3; do
                restart_workers
                expect "/?name=restart$i" "inv-1 \"Mary\" => \"Hello, restart$i!\""
              done
              concurrently 50 "/?name=Nix"
              expect "/?name=after" 'inv-1 "Mary" => "Hello, after!"'
            '';
          };

          # An invocation per request in PHP-FPM's children (tests/server/index.php), which it forks
          # after loading the extension: across requests, in the children it forks to replace the
          # ones that exit after 5 requests (each shutting the module down), and after FPM reloads.
          fpm = platform.runFpmTests {
            inherit php;
            src = ./tests/server;
            maxRequests = 5;
            script = ''
              expect "/?name=Nix" 'inv-1 "Mary" => "Hello, Nix!"'
              concurrently 50 "/?name=Nix"
              reload
              expect "/?name=reload" 'inv-1 "Mary" => "Hello, reload!"'
              concurrently 50 "/?name=Nix"
            '';
          };
        }
      );

      formatter = forAllSystems (pkgs: pkgs.nixfmt-tree);
    };
}
