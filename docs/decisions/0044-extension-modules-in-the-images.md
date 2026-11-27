# Extension Modules in the Images

## Status

Accepted. Supersedes decisions 1 and 3 of [Image Builds and CI Caches](0042-image-builds-and-ci-caches.md) (the extension builds in the images and their Cargo cache mounts), and amends its decision 4 (the base images) and the last consequence of [Extension Flake](0037-extension-flake.md) (the Docker-based suites did not use the flake).

## Context

`docker/Dockerfile` compiled the extension inside each PHP image, with rustup and Cargo cache mounts ([Image Builds and CI Caches](0042-image-builds-and-ci-caches.md)), while the PIE archives and the Nix users' modules came from the extension flake ([Extension Flake](0037-extension-flake.md)). The same module had two builds to keep working, and CI's image jobs compiled it from scratch every run, since the cache mounts last only as long as a runner.

The flake's portable builds load into any PHP of their minor and thread safety on Linux with glibc 2.28 or newer, which every base image is. The extension workflow already builds every release module, on an x86_64 and an arm64 runner, and pushes them to restate-php.cachix.org, with the debug and diagnostics modules the suites use. Nix caches a build per system it runs on, so each CPU needs its own runner ([#23](https://github.com/restate-php/restate/pull/23)).

Measured on an Apple silicon Mac (Docker Desktop), for the PHP 8.4 ZTS debug FrankenPHP image:

| | Cargo in the image (cache mounts) | the flake's module |
| --- | --- | --- |
| Nothing changed | 1 s | 1–2 s |
| A one-line change in the core | 2 s (incremental) | 33 s (the whole crate graph) |
| Empty caches, the source built by CI | 21 s of Cargo | no compile: a 10 MiB download, after the ~1 GiB inputs layer once |
| The other CPU (amd64) | 53 s of Cargo under Rosetta | 32 s, cross-compiled natively |

A full compile costs about the same either way. What the cache mounts gave was rustc's incremental compilation of the workspace's own crates, which a Nix build does not keep. That matters while working on the extension, which `just extension build` covers. The images are for the integration, load, soak and conformance suites, which run less often.

## Decision

1. **The images install a module, they don't build one.** Every image target (`docker/*/Dockerfile`) copies `restate.so` from the `restate-extension` context into its PHP's `extension_dir` (which depends on the PHP version and thread safety) and checks that it loads. Nothing in them compiles the extension: no Rust toolchain, no `extension-bridge*` stages.
2. **The module is the flake's** `legacyPackages.extensions.<php>.<nts|zts>.<target>.<variant>`, the same derivation as the PIE archive's for release builds. The variants are `debug`, `release`, `debug-diagnostics` and `release-diagnostics`.
3. **`docker-bake.hcl` builds the images.** Its targets are `<Dockerfile target>-<minor>-<variant>`, one matrix per target over the PHP minors and variants. Each image's `restate-extension` context is the extension target of the same PHP minor, thread safety and variant, from one function, so the module and the base cannot disagree. PHP 8.6's official images are ZTS only, so their images take the ZTS module.
4. **`extension/Dockerfile` builds a module in a container,** for anyone without Nix: Nix on the build platform, cross-compiling for the target platform. A first layer fetches what every module builds with (the flake's `extension-inputs`) from the lock files, the toolchain file and the manifests only. It needs only `extension/`, so it goes with the split to `restate-php/ext-restate`.
5. **`RESTATE_PHP_IMAGE_EXTENSION=host` builds the module with Nix on the host** and hands it to bake as `EXTENSION_DIR`. CI does: for any source CI has built, the runner downloads the module from Cachix, and never builds the container's ~1 GiB inputs layer. `bin/build-image.sh [--php <minor>] [--variant <variant>] <target> <tag>` is the one way the recipes and workflows build an image, either way.
6. **One base stage per PHP minor.** `FROM docker.io/dunglas/frankenphp:1.14.0-php8.5-bookworm AS frankenphp-8.5`, and likewise `php-cli-<minor>` and `php-fpm-<minor>`. Bake passes the stage's name (`BASE_IMAGE=frankenphp-8.5`). Dependabot reads the `FROM` lines and bumps every minor's FrankenPHP in one pull request; nothing is bumped by hand. `RESTATE_PHP_TEST_PHP_VERSION` chooses the minor of the suites' images, replacing `RESTATE_PHP_TEST_FRANKENPHP_BASE_IMAGE` and `RESTATE_PHP_TEST_FPM_BASE_IMAGE`.
7. **One Dockerfile per server,** next to its configuration: `docker/frankenphp/Dockerfile` (`frankenphp-extension`, `conformance-frankenphp`), `docker/fpm/Dockerfile` (`fpm-extension`, `fpm-extension-apache`, `conformance-fpm-nginx`) and `docker/quickstart/Dockerfile` (`quickstart-php-restate`), each with its own base stages and one `BASE_IMAGE` argument. One file shared the stages that compiled the extension; with none left, the targets share nothing but the `restate-extension` context, which bake sets.

### Rejected

- **Waiting for compiled dependencies in Nix builds** ([ext-php-rs.nix#7](https://github.com/sagikazarmark/ext-php-rs.nix/issues/7)). It would save about 6 s of the 33 s, since most of a rebuild is the workspace's own two crates.
- **A Cargo stage next to the Nix one, for working on the extension.** Two builds of the module again; `just extension build` is the loop for that.
- **Base image tags in the bake file.** Dependabot does not read HCL.
- **Running the Nix stage as `linux/amd64` on Apple silicon** to use the x86_64 runner's cache: Rosetta compiles about half as fast when the cache misses, and arm64 Linux machines would need QEMU. The arm64 runner builds their modules instead.

## Consequences

- An image build after a change to `extension/` compiles the module in about 30 s on a laptop; with no change, it takes a download or nothing. CI's image jobs download the module instead of compiling it, except for a source CI has not built yet.
- The first local build without `RESTATE_PHP_IMAGE_EXTENSION=host` fetches about 1 GiB into the inputs layer of `extension/Dockerfile` (about 7 GiB unpacked), again when the lock files or the toolchain change.
- Image builds need `docker buildx bake`. Podman has no bake, so `bin/build-image.sh` does not work with podman as `docker`.
- `nix build` sees the files git tracks only: a new file under `extension/` needs `git add` before `RESTATE_PHP_IMAGE_EXTENSION=host` builds it. `extension/Dockerfile` copies the working tree.
- A new PHP minor is a `FROM` line in each of `docker/*/Dockerfile`, a `PHP_VERSIONS` entry in `docker-bake.hcl` and a `phps` entry in the flake.
