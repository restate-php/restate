# Image Builds and CI Caches

## Status

Accepted. Amends [Worker-Mode and Soak Testing](0035-worker-soak-testing.md) (the soak images) and [Load Tests in Compose](0040-load-tests-in-compose.md) (the endpoint image). Decisions 1 and 3 are superseded and decision 4 amended by [Extension Modules in the Images](0044-extension-modules-in-the-images.md): the images install the extension flake's module instead of compiling it, and each PHP minor has base stages of its own. Decision 6 (the reusable conformance workflow) is superseded by [Test Matrix](0045-test-matrix.md): the conformance suite runs in the runtime cells.

## Context

`docker/Dockerfile` compiled the extension in five stages for FrankenPHP's ZTS PHP alone: a debug build, a release build, and a `diagnostics` build of each for the soak suite. Each fed an image target of its own, and three of those images differed only in the module they copied. Every stage installed rustup and the toolchain again, every build compiled every crate from scratch, and `COPY . /app` came before `apt-get` in the conformance images, so any source change installed the packages again.

The base images were `ARG` defaults (`FROM ${FRANKENPHP_IMAGE}`). Dependabot reads `FROM` lines only and never expands an `ARG`, so it had never proposed an update to the PHP, FrankenPHP or php-fpm bases. The recipes passed a base image as a build argument only when its setting was set, since an empty argument replaced the default.

In CI, fifteen copies of a ten-line `actions/cache` step cached `extension/target/` under seven keys. Every pull request saved its own copies, so the caches went over the repository's 10 GB quota and evicted each other. Two nightly jobs that build the extension had no cache at all.

## Decision

1. **One extension build per PHP base.** `extension-bridge` (PHP_IMAGE, NTS), `extension-bridge-zts` (FRANKENPHP_IMAGE) and `extension-bridge-fpm` (PHP_FPM_IMAGE) each run `docker/extension/build.sh`. `EXTENSION_PROFILE` (debug or release) and `EXTENSION_FEATURES` (cargo features) choose the build, and the script checks that the module loads into the stage's PHP. The toolchain is installed once, in a `rust-toolchain` stage the three copy.
2. **One FrankenPHP target.** `frankenphp-extension` serves the integration suite. The load tests build it with `EXTENSION_PROFILE=release`, and the soak suite with `EXTENSION_FEATURES=diagnostics` in release and debug. The soak harness starts it in worker mode with `GODEBUG=madvdontneed=1`, which the soak images used to set.
3. **Cache mounts for Cargo.** The build mounts Cargo's registry and a target directory as BuildKit caches. The target directory has a subdirectory per Debian release, PHP version and thread safety, because ext-php-rs generates its bindings again only when `PHP`, `PHP_CONFIG` or `PATH` change, and those are the same in every PHP image. A local Rust change recompiles only the extension's crates.
4. **Default bases as stages.** Each default base is a stage of its own (`FROM docker.io/dunglas/frankenphp:… AS frankenphp-default`), which Dependabot reads. `FROM ${FRANKENPHP_IMAGE:-frankenphp-default}` picks an override when one is set, so the recipes always pass their setting, empty or not. Dependabot ignores minor and major updates of `php`: 8.4, the oldest supported minor, is the default on purpose.
5. **One setup action in CI.** `.github/actions/setup` runs setup-devenv, restores Composer's download cache, and, with `cargo-cache: <key>`, restores Cargo's through `Swatinem/rust-cache`. rust-cache runs `cargo` and `rustc` in the job's dev shell (`cmd-format`), drops the workspace's own crates before saving, and saves on `main` only. Jobs that build alike share a key: `check-php<minor>`, `integration`, `bench`, `bench-extension`.
6. **The conformance workflow is reusable per target.** `conformance.yaml` takes a `target` input and reads its Restate image from `RestateContainer::DEFAULT_IMAGE`. The nightly php-fpm run calls it instead of repeating its steps.

### Rejected

- **A layer cache in CI** (`cache-from`/`cache-to` with the GitHub cache). The images are rebuilt only in their own jobs, and a layer cache of each toolchain and target directory would take back the quota that decision 5 frees.
- **`${FRANKENPHP_IMAGE:-docker.io/dunglas/frankenphp:…}` inside `FROM`.** It is shorter, but Dependabot does not read it.
- **Saving the Cargo caches on pull requests too.** Each pull request would add its own seven caches to the quota again.

## Consequences

- A cold image build takes as long as before: the cache mounts speed up repeated local builds, and CI runners start without them.
- The integration, load and soak images are tags of one target; `just test integration frankenphp`, `just load run` and `just test soak` each choose their build arguments.
- A pull request that changes `Cargo.lock` builds its dependencies from scratch until `main` has saved a cache for the new lock.
- The extension benchmarks install the gungraun runner in each run, since rust-cache's cleanup removes it from `extension/target/`.
- Dependabot now proposes base image updates, which run the whole CI.
