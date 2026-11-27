# Contributing

This repository holds the whole SDK: the Composer packages in `packages/`, the Restate PHP extension in `extension/` (Rust, on [`restate-sdk-shared-core`](https://crates.io/crates/restate-sdk-shared-core) and `ext-php-rs`), the tests, the services the SDK conformance suite drives ([`test-services/`](test-services/README.md)), and the images and server configuration the suites serve endpoints with ([`docker/`](docker/README.md)).

## Setting up

You need [devenv](https://devenv.sh) and a container runtime: Docker, or rootless podman with its `docker` CLI. The dev shell provides everything else: nixpkgs' default PHP (8.5 now; `devenv -O languages.php.version:string 8.4 shell` for another supported minor) with its headers and `php-config`, Composer, the Rust toolchain pinned in `extension/rust-toolchain.toml`, libclang and the flags the extension build needs, Java 25 for the conformance suite, just, k6 and jq, and the linters ([Development Environment](docs/decisions/0001-dev-environment.md)). The integration tests start Restate in a container, so the runtime stays on the host.

```bash
devenv shell
composer install   # all packages at once, through the root restate/monorepo package (development only)
```

Run everything inside the shell (`devenv shell`, or prefix a command with `devenv shell --`). Every task is a just recipe (`just --list`): the repository-wide ones (`just check`, `just cs`, `just analyse`, `just lint`, `just serve`, `just bench`, …) in `justfile`, the extension's build, checks, PIE archives and benchmarks (`just extension <recipe>`, `extension/justfile`), the PHPUnit suites (`just test <recipe>`, `tests/justfile`) and the load tests (`just load <recipe>`, `tests/load/justfile`); see [Just for Every Task](docs/decisions/0043-just-for-every-task.md).

## Building the extension

```bash
just extension build                                           # debug build: extension/target/debug/restate.so
just extension build --release                                 # release build: extension/target/release/restate.so
just extension build --php-config /path/to/zts/bin/php-config  # for another PHP, e.g. a ZTS one for FrankenPHP
```

The test recipes call it first; `just --list extension` lists the module's other recipes.

A module loads only into the PHP minor version and thread safety (NTS or ZTS) it was built for; `--php-config` builds into a target directory of its own per PHP (`extension/target/php8.4-zts/…`), so builds never overwrite each other. Outside the dev shell bindgen can fail with `'stdlib.h' file not found`: the shell sets the flags it needs.

To serve every test service on port 9080 with the debug build loaded:

```bash
just serve  # builds it, then php -d extension=… -S 0.0.0.0:9080 serving test-services/index.php
```

## Checks and tests

```bash
just check                     # before every commit: code style, static analysis, workflow and shell lint,
                               # unit tests, the packages' composer.json files, cargo fmt/clippy/test
just test integration php-cli  # builds the extension, then runs the integration suite against a real Restate server
```

[TESTING.md](TESTING.md) describes every suite (unit, extension, integration, FrankenPHP, PHP-FPM, scale, soak, conformance), the benchmarks and load tests, and CI.

## Documentation

User documentation lives in [`docs/`](docs/README.md): the guides for writing services in `docs/develop/`, and serving, installation and compatibility next to them. The [README](README.md) stays short: highlights, the quickstart, and links. When you change behaviour, update the guide that describes it; when you change the [Docker quickstart](examples/quickstart/README.md), run `just test quickstart` (CI runs it too). Write for PHP developers who may not know Restate, use Restate's vocabulary (Basic Service, Virtual Object, Workflow, durable step…), keep the design reasoning in decision records, and link those only from a page's "Design notes".

## Further reading

- [CONTEXT.md](CONTEXT.md): the glossary, the terms the code and docs use.
- [docs/decisions/](docs/decisions/README.md): why the SDK is built the way it is; read the record of an area before changing it.
- [Backend Architecture](docs/internals/architecture.md): how the packages, the extension and shared-core fit together.
- [Conformance](docs/internals/conformance.md): how the SDK conformance suite runs against the PHP test services.
- [Public API Boundary](docs/decisions/0016-public-api-boundary.md): what is public API and what is internal (`Restate\Internal`).
- [Bumping versions](RELEASING.md#bumping-versions): the version and contract rules for changes to the extension's surface.
- [RELEASING.md](RELEASING.md): for maintainers, how a release is cut.
