{
  description = "The Restate PHP extension (module restate): for every PHP of nixpkgs, and PIE's prebuilt binaries";

  # What restate-php/restate's CI builds: the PIE archives, the modules for each PHP, the Rust
  # toolchain. Nix uses it for trusted users who accept the flake's configuration (prompted, or
  # `accept-flake-config = true`); CI configures the cache itself.
  nixConfig = {
    extra-substituters = [ "https://restate-php.cachix.org" ];
    extra-trusted-public-keys = [
      "restate-php.cachix.org-1:qNXDDSIntOQKSr5gaRZpwUAQDOKdC2nuOr5Z6TBOUec="
    ];
  };

  inputs = {
    ext-php-rs.url = "github:sagikazarmark/ext-php-rs.nix/v0.2.0";
    # ext-php-rs's nixpkgs, as its template recommends. The dev shell of restate-php/restate
    # follows this one too (its devenv.yaml), so the shell and every Nix build share one nixpkgs.
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

      # The Linux targets of the PIE archives.
      pieTargets = [
        "x86_64-linux-glibc"
        "aarch64-linux-glibc"
        "x86_64-linux-musl"
        "aarch64-linux-musl"
      ];

      # The builds of each module: the profile, and the soak suite's diagnostics feature.
      variants = {
        debug = {
          buildType = "debug";
        };
        release = { };
        debug-diagnostics = {
          buildType = "debug";
          buildFeatures = [ "diagnostics" ];
        };
        release-diagnostics = {
          buildFeatures = [ "diagnostics" ];
        };
      };

      # modules.<php>.<nts|zts>.<target>.<variant>: one portable module, cross-compiled from
      # any of the systems.
      modules =
        pkgs:
        let
          platform = rustPhpPlatform pkgs;
        in
        lib.genAttrs phps (
          php:
          lib.genAttrs [ "nts" "zts" ] (
            ts:
            lib.genAttrs pieTargets (
              target:
              lib.mapAttrs (
                _: variant:
                platform.buildRustPhpExtension (
                  args
                  // variant
                  // {
                    php = pkgs.${php};
                    target = platform.targets.${target};
                    zts = ts == "zts";
                  }
                )
              ) variants
            )
          )
        );
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
        pie = (rustPhpPlatform pkgs).mkPieRelease {
          extensions = lib.concatMap (
            php:
            lib.concatMap (ts: map (target: (modules pkgs).${php}.${ts}.${target}.release) pieTargets) [
              "nts"
              "zts"
            ]
          ) phps;
        };
      });

      # Each module of packages.pie on its own, and the debug and diagnostics builds the
      # Docker-based suites use (extension/Dockerfile):
      #
      #   nix build .#modules.php84.zts.x86_64-linux-glibc.debug
      #
      # Here rather than in packages, which `nix flake show` and `nix flake check` list.
      legacyPackages = forAllSystems (
        pkgs:
        let
          all = modules pkgs;
        in
        {
          modules = all;

          # Everything the glibc modules build with but their source (the toolchain, the PHP
          # headers, the vendored crates), for extension/Dockerfile to fetch before it copies
          # the source in: what each module's derivation is given. Not inputDerivation, which
          # leaves out `env` (the PHP headers and libclang are in PHP_CONFIG and
          # LIBCLANG_PATH). The variants of a module share their inputs.
          moduleInputs = pkgs.writeText "restate-module-inputs" (
            builtins.toJSON (
              lib.concatMap (
                php:
                lib.concatMap
                  (
                    ts:
                    map (target: removeAttrs all.${php}.${ts}.${target}.debug.drvAttrs [ "src" ]) [
                      "x86_64-linux-glibc"
                      "aarch64-linux-glibc"
                    ]
                  )
                  [
                    "nts"
                    "zts"
                  ]
              ) phps
            )
          );
        }
      );

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
