# Extension Flake

## Status

Accepted. Decisions 4, 5 and 6 are amended by [Just Recipes](0041-just-recipes.md): the PIE archives are `just extension pie` (`nix build ./extension#pie`), so the dev shell no longer has `outputs.pie`, and it no longer pulls ext-php-rs.cachix.org.

## Context

The extension's Nix builds (the module for a PHP from nixpkgs, and the prebuilt binaries for PIE, [Prebuilt Extension Binaries](0030-prebuilt-extension-binaries.md)) used to be configured in `nix/extension.nix`, a module of the dev shell. It went through the devenv module of ext-php-rs.nix, which was vendored in `nix/ext-php-rs/` as a path input. ext-php-rs.nix now has its own repository ([sagikazarmark/ext-php-rs.nix](https://github.com/sagikazarmark/ext-php-rs.nix)) and is released (v0.1.0). Its project template is a flake: an overlay that adds the extension to every PHP of nixpkgs, PIE's archives, and `.phpt` checks. The ext-php-rs.nix repository is laid out the same way: the flake holds what it publishes, and its own dev environment and tests are devenv's, which takes the flake as an input (`git+file:.`) and follows its nixpkgs.

Only the dev shell could build the extension with Nix. Nix users had no way to get it: the build was an output of the shell, not of anything they could take as an input.

## Decision

1. **The extension has a flake, `extension/flake.nix`.** It lives in `extension/`, not at the root, because `extension/` is what the release splits into `restate-php/ext-restate` ([Release](0031-release.md)). The flake ships there, next to the `composer.json` PIE reads and the source the binaries are built from, at the tag they were built from. Nix users get it from the same repository PIE users do: `github:restate-php/ext-restate/<version>`. `extension/` already has the template's layout: a Cargo workspace, `composer.json` and `.cargo/`. Rejected: a root flake. It would describe the SDK's monorepo, and it would not be part of the split.
2. **What the flake has:**
   - **`overlays.default`** (`mkPhpExtensionsOverlay`): `restate` in every PHP of nixpkgs, each built against the PHP that loads it. Nix users load it with `php.withExtensions ({ enabled, all }: enabled ++ [ all.restate ])`, or `languages.php.extensions = [ "restate" ]` in devenv.
   - **`packages.default`**: the module for nixpkgs' default PHP.
   - **`packages.php`**: nixpkgs' default PHP with the module loaded.
   - **`packages.frankenphp`**: nixpkgs' FrankenPHP with the module, rebuilt for its thread-safe PHP.
   - **`packages.pie`**: the 24 archives of a release, with `SHA256SUMS`.
   - **`packages.load-test`**: ext-php-rs.nix's `loadPieArchives`, which loads archives into PHP's official images and runs `tests/*.phpt` there (Docker), for `.github/workflows/extension.yaml`.
   - **`checks`**, with ext-php-rs.nix's test functions (v0.2.0), none of which needs Docker or the network:
     - `php84`, `php85`, `php86`: `tests/*.phpt`, the extension loaded through the overlay.
     - `zts`: the same tests with a thread-safe PHP, FrankenPHP's (nixpkgs' default PHP version, from its binary cache).
     - `frankenphp` and `fpm`: `tests/server/`, which runs an invocation per request through the core (Restate's start message and the input, as precomputed frames; the output; the end; shared-core's logs captured). In FrankenPHP's workers, across worker restarts (the module shut down and started again in the same process) and with 50 requests at once on its threads; in PHP-FPM's children, which it forks after loading the module, replaced after 5 requests each, and after a reload. They test the module in those servers' lifecycles, not the protocol against Restate: the flake has neither Restate nor the SDK, and the Docker suites of restate-php/restate run the SDK in FrankenPHP and php-fpm against Restate.

   `phps` in the flake lists the PHP minors, which follow `composer.json`'s `php` constraint. The build's arguments come from the crate's `Cargo.toml` (`pname`, version) and `composer.json` (the module name), through `extensionArgs`. Its source is only what the build reads (the workspace manifests, `.cargo/`, both crates), so changing the flake, the tests or the docs rebuilds nothing. Every build uses the toolchain of `rust-toolchain.toml` (rust-overlay's `fromRustupToolchainFile`), the same one the dev shell and the Docker images use.
3. **The PIE targets stay Linux.** ext-php-rs.nix also builds macOS archives now, but its default `targets` would make 36 archives. The flake lists the four Linux targets, which keeps the 24 archives of decision 4 of [Prebuilt Extension Binaries](0030-prebuilt-extension-binaries.md). Adding macOS is that decision's to change.
4. **The dev shell takes the flake as an input** (`ext-restate` in `devenv.yaml`, `git+file:.?dir=extension`) and applies its overlay. `devenv.nix` exposes the flake's builds as outputs and defines none of its own. The shell's PHP is nixpkgs' default (`pkgs.php`):
   - `outputs.extension`: the module built against the shell's PHP, through the overlay
   - `outputs.pie`: the flake's `packages.pie`
   - `outputs.checks`: the flake's checks

   The shell does not load the module. The tests load the cargo build (`composer build:extension`), and loading both would load two copies. A `git+file` input sees only what git tracks, as with any flake in a git repository. It ignores `extension/target/`, which a `path:` input would copy into the store. The catch: a new file under `extension/` must be `git add`ed before Nix sees it.
5. **One nixpkgs, ext-php-rs.nix's.** The flake's nixpkgs follows ext-php-rs.nix's (`nixpkgs.follows = "ext-php-rs/nixpkgs"`), as its template recommends. The dev shell follows the flake's (`nixpkgs` and `rust-overlay` in `devenv.yaml` are `follows: ext-restate/…`), so the shell's PHP, the Nix builds and the CI jobs use one package set. `extension/flake.lock` is the only pin of nixpkgs, rust-overlay and ext-php-rs.nix, and `devenv.lock` copies it. With nixpkgs that matches exactly, [ext-php-rs.cachix.org](https://ext-php-rs.cachix.org) has the expensive parts of the portable builds: the PHP headers configured for each target, and cargo-zigbuild. The dev shell pulls from it (`cachix.pull` in `devenv.nix`). Rejected: keeping the shell on `cachix/devenv-nixpkgs/rolling`. Every Nix build would then evaluate a second nixpkgs, `outputs.extension` would not be built against the shell's PHP, and the cache would miss.
6. **Composer stays the task runner** (decision 2 of [Development Environment](0001-dev-environment.md)). `composer build:pie` runs `devenv build outputs.pie`. No devenv script or `enterTest` duplicates a Composer script.

## Consequences

- Nix users can install the extension without PIE: the split repository's flake, its overlay, `all.restate`. That works on macOS too, against nixpkgs' PHP.
- **Updating nixpkgs means updating ext-php-rs.nix:** `nix flake update ext-php-rs` in `extension/` (or a new tag in its URL), then `devenv update`. Updating nixpkgs on its own would mean overriding ext-php-rs.nix's nixpkgs in the flake, which loses the cache.
- The dev shell's nixpkgs is nixos-unstable, as ext-php-rs.nix pins it, instead of devenv's rolling set.
- `.github/workflows/extension.yaml` also runs `nix flake check ./extension`. It evaluates every system's outputs and runs the checks on the runner.
- The Docker-based suites (FrankenPHP, php-fpm, upstream) still build the extension from source inside the images, with rustup and `rust-toolchain.toml`. They do not use the flake.
- The first build of the extension in a fresh checkout needs every file under `extension/` tracked by git.
