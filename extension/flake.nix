{
  # What restate-php/restate's CI builds: the PIE archives, the extension for each PHP, the Rust
  # toolchain. Nix uses it for trusted users who accept the flake's configuration (prompted, or
  # `accept-flake-config = true`); CI configures the cache itself.
  nixConfig = {
    extra-substituters = [ "https://restate-php.cachix.org" ];
    extra-trusted-public-keys = [
      "restate-php.cachix.org-1:qNXDDSIntOQKSr5gaRZpwUAQDOKdC2nuOr5Z6TBOUec="
    ];
  };

  inputs = {
    ext-php-rs.url = "github:sagikazarmark/ext-php-rs.nix/v0.4.1";
    # ext-php-rs's nixpkgs, as its template recommends. The dev shell of restate-php/restate
    # follows this one too (its devenv.yaml), so the shell and every Nix build share one nixpkgs.
    # TODO: use unstable?
    nixpkgs.follows = "ext-php-rs/nixpkgs";
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

      # The outputs of each system: `f system` returns { packages = …; checks = …; … }, which
      # becomes { packages.<system> = …; checks.<system> = …; … }.
      eachSystem =
        systems: f:
        lib.foldAttrs lib.mergeAttrs { } (
          map (system: lib.mapAttrs (_: value: { ${system} = value; }) (f system)) systems
        );

      # Every build uses the toolchain of rust-toolchain.toml, as cargo does. A function of the
      # package set: the overlay applies it to whichever nixpkgs it's used with.
      rustPhpPlatform =
        pkgs:
        ext-php-rs.lib.makeRustPhpPlatform {
          inherit pkgs;
          rustToolchain = (rust-overlay.lib.mkRustBin { } pkgs).fromRustupToolchainFile ./rust-toolchain.toml;
        };

      # buildRustPhpExtension's arguments: pname (restate) from the crate's Cargo.toml, the version it inherits from the workspace's,
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
    }
    // eachSystem [ "x86_64-linux" "aarch64-linux" "aarch64-darwin" ] (
      system:
      let
        # nixpkgs with this flake's overlay: the extension in every PHP.
        pkgs = nixpkgs.legacyPackages.${system}.extend self.overlays.default;
        platform = rustPhpPlatform pkgs;

        # A PHP of each minor of composer.json's `php` constraint.
        phps = with pkgs; [
          php84
          php85
          php86
        ];

        # <php>.<nts|zts>.<target>.<variant>: the extension for each PHP minor, thread safety,
        # Linux target of the PIE archives and build, cross-compiled from any of the systems. The
        # variants are the profile, and the soak suite's diagnostics feature.
        extensions = platform.buildRustPhpExtensions {
          inherit args;
          php = phps;
          targets = with platform.targets; [
            x86_64-linux-glibc
            aarch64-linux-glibc
            x86_64-linux-musl
            aarch64-linux-musl
          ];
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
        };

        # nixpkgs' default PHP with the extension, and FrankenPHP with it, rebuilt for its
        # thread-safe PHP.
        php = pkgs.php.withExtensions ({ enabled, all }: enabled ++ [ all.${extensionName} ]);
        frankenphp = pkgs.frankenphp.override { inherit php; };
      in
      {
        packages = {
          # lib/php/extensions/restate.so, for nixpkgs' default PHP.
          default = pkgs.php.extensions.${extensionName};

          # `nix run .#php -- --ri restate`;
          # `nix run .#frankenphp -- php-server --root tests/server --worker tests/server/worker.php`.
          inherit php frankenphp;

          # Loads the Linux archives for this machine's CPU into PHP's official Docker images and
          # runs tests/*.phpt there: `nix build .#pie && nix run .#load-test -- result tests`. PHP
          # 8.6's NTS archives go into its nts images (php:8.6-nts-*), its ZTS ones into php:8.6-zts-*.
          load-test = platform.loadPieArchives;

          # PIE's prebuilt binaries for a release (docs/decisions/0030-prebuilt-extension-binaries.md in
          # restate-php/restate): every PHP minor × NTS and ZTS × x86_64 and arm64 × Linux with
          # glibc 2.28 or newer and with musl, 24 archives with SHA256SUMS. Cross-compiled on any
          # of the systems.
          pie = platform.mkPieRelease {
            extensions = platform.collectExtensions extensions { variant = "release"; };
          };
        };

        # Each extension of packages.pie on its own, and the debug and diagnostics builds the
        # Docker-based suites use (extension/Dockerfile):
        #
        #   nix build .#extensions.php84.zts.x86_64-linux-glibc.debug
        #
        # Here rather than in packages, which `nix flake show` and `nix flake check` list.
        legacyPackages = {
          inherit extensions;

          # Everything the glibc extensions build with but their source (the toolchain, the PHP
          # headers, the vendored crates), for extension/Dockerfile to fetch before it copies
          # the source in. The variants of an extension share their inputs.
          extension-inputs = platform.mkExtensionInputs {
            name = "restate-extension-inputs";
            extensions = platform.collectExtensions extensions {
              variant = "debug";
              libc = "glibc";
            };
          };
        };

        checks =
          # tests/*.phpt, run with each PHP minor with the extension loaded through the overlay, as
          # Nix users load it: php84, … as the extensions are keyed.
          lib.listToAttrs (
            map (
              php:
              lib.nameValuePair "php${lib.replaceStrings [ "." ] [ "" ] (lib.versions.majorMinor php.version)}" (
                platform.runPhpTests {
                  inherit php;
                  extensions = [ php.extensions.${extensionName} ];
                  src = ./tests;
                }
              )
            ) phps
          )
          // {
            # tests/*.phpt with a thread-safe (ZTS) PHP, as FrankenPHP and PHP 8.6's default official
            # images have: FrankenPHP's (nixpkgs' default PHP version, from its binary cache).
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
          };

        formatter = pkgs.nixfmt-tree;
      }
    );
}
