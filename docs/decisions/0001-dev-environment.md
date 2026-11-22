# Development Environment

## Status

Accepted.

## Context

Working on the SDK needs PHP with its development headers, Composer, a pinned Rust toolchain, libclang (`ext-php-rs` runs bindgen over the PHP headers), Java for the upstream conformance suite, and linters for the shell scripts and workflows. The integration, FrankenPHP, php-fpm and upstream suites also run Restate and the endpoint in containers. Contributors and CI should get the same tools, at the same versions, without installing them by hand.

## Decision

1. **[devenv](https://devenv.sh) provides the toolchain** (`devenv.nix`, `devenv.yaml`), from the nixpkgs of the extension's flake: nixpkgs' default PHP (`pkgs.php`, 8.5 now) with its `php-config` (`devenv -O languages.php.version:string 8.4 shell` for another minor, so the extension builds against that PHP too), Composer, the Rust toolchain read from `extension/rust-toolchain.toml` (the same file the container images install from), libclang with the `BINDGEN_EXTRA_CLANG_ARGS` the extension build needs, Java 25, curl, git, `actionlint`, `shellcheck`, valgrind, k6 and `restate-server` (nix-restate's `restate-bin`, for the test harness's `RestateProcess`, [Native Restate for the Test Harness](0038-native-restate-for-the-test-harness.md)). Every command runs in it: `devenv shell -- <command>`.
2. **Every task is a Composer script** (`composer run --list`): `composer check` before every commit, `composer build:extension`, the test suites. The dev shell defines no tasks of its own, so there is one list of tasks, readable without Nix. What the dev shell does define are **outputs**, things Nix builds (`devenv build outputs.<name>`): the extension against the shell's PHP (`outputs.extension`), the prebuilt binaries of a release (`outputs.pie`, run through `composer build:pie`) and the extension's checks (`outputs.checks`). They come from the extension's flake (`extension/flake.nix`, the `ext-restate` input in `devenv.yaml`), which builds them with [ext-php-rs.nix](https://github.com/sagikazarmark/ext-php-rs.nix), and whose nixpkgs the shell follows ([Extension Flake](0037-extension-flake.md)).
3. **CI runs the same shell.** [`sagikazarmark/setup-devenv`](https://github.com/sagikazarmark/setup-devenv) installs Nix and devenv, and jobs run their steps through `devenv shell`, so a CI failure reproduces locally with the same command.
4. **The container runtime stays on the host.** Docker, or rootless podman as `docker` or `podman`, is a prerequisite, not part of the shell: a daemon, its sockets, permissions and cgroup setup are machine configuration that a project shell cannot own. The test harness only runs the container CLI ([Test Harness](0023-test-harness.md)), so either runtime works without extra configuration.

## Consequences

- A contributor needs Nix, devenv and a container runtime; everything else comes from the shell. [TESTING.md](../../TESTING.md) has the commands.
- Bumping a tool is a change to `devenv.nix` (or `extension/rust-toolchain.toml` for Rust), reviewed like code, and CI picks it up.
- The Nix store is not cached in CI, so each job downloads the toolchain. The dev shell pulls the PHP headers and cargo-zigbuild of the prebuilt binaries from ext-php-rs.cachix.org ([Extension Flake](0037-extension-flake.md)); the extension workflow still builds the 24 modules from scratch. A cache of this project's own (Cachix) would make that a download.
