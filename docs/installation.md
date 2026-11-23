# Installation

This page covers what the Restate PHP SDK needs, which Composer packages it is made of, and how to install the native extension and the packages. It ends with pointers to your first service.

## Requirements

| Component | PHP | Restate PHP extension | Restate server |
| --- | --- | --- | --- |
| `restate/sdk`: your services and endpoint | 8.4, 8.5 or 8.6, NTS or ZTS | Required, of the SDK's own minor version | 1.8 or newer |
| `restate/ingress`: calling services from other code | 8.4, 8.5 or 8.6 | Not needed | 1.8 or newer |
| `restate/testing`: the test harness | 8.4, 8.5 or 8.6 | Not needed in the test process. The endpoint it serves needs it. | Starts its own (1.8.1 by default), in Docker or podman or as a local `restate-server` |

Restate 1.7 works best-effort: a few features that need 1.8 fail there with a message saying so ([Compatibility](compatibility.md#restate-17)).

The extension has prebuilt binaries for Linux on x86_64 and arm64, with glibc 2.28 or newer or with musl (Alpine). Everywhere else, [build it from source](#building-from-source) or [use Nix](#with-nix). [Compatibility](compatibility.md) has the full matrix.

## Packages

The SDK is split into Composer packages, released together at one version:

| Package | Install | What it is |
| --- | --- | --- |
| `restate/sdk` | `composer require restate/sdk` | Services, handlers and the endpoint (namespace `Restate\`). Requires the extension (`ext-restate`), so Composer refuses to install it without. |
| `restate/ingress` | `composer require restate/ingress` | The [ingress client](ingress-client.md) (`Restate\Ingress\`), for code that calls services without serving any. No extension needed. |
| `restate/testing` | `composer require --dev restate/testing` | The [test harness](develop/testing.md) (`Restate\Testing\`). No extension needed in the test process. |
| `restate/common` | installed with the others | The types they share (`Serde`, `Target`, `TerminalError`, the JSON value mapping, …) |

Class names do not depend on the package: `Restate\Target` is `Restate\Target` whichever package brought it.

## Installing the extension

Every endpoint runs on the Restate PHP extension. Its module is `restate`, its file `restate.so`, and Composer knows it as `ext-restate`. It carries [`restate-sdk-shared-core`](https://crates.io/crates/restate-sdk-shared-core), the Rust core the official TypeScript, Python and Rust SDKs are built on. There is no pure-PHP fallback.

`restate/sdk` requires the extension of its own minor version. Building an endpoint (`Endpoint::serve()`, `Endpoint::psr()`, `Endpoint::builder()->build()`) without the extension loaded, or with one that does not match the package, throws `Restate\BackendUnavailableException`. Its message says what to install ([Compatibility](compatibility.md), [Troubleshooting](troubleshooting.md)).

### With PIE

Install it with [PIE](https://github.com/php/pie), the PHP Foundation's extension installer:

```bash
pie install restate/ext-restate
```

PIE downloads the prebuilt `restate.so` for your PHP from the GitHub releases of [restate-php/ext-restate](https://github.com/restate-php/ext-restate). It picks the build for your PHP version (8.4, 8.5 or 8.6), thread safety (NTS, or ZTS for FrankenPHP), CPU (x86_64 or arm64) and libc (glibc 2.28 or newer, or musl).

PIE copies the module into PHP's extension directory, enables it (`extension=restate`) and checks that PHP loads it. If it cannot enable it, add `extension=restate` to php.ini or to a file in its scan directory yourself (`php --ini` shows both).

Nothing is compiled, so you need no Rust toolchain or build tools.

To install a specific version, for example one that matches an older `restate/sdk`: `pie install restate/ext-restate:<version>`.

### In a Docker image

In an image based on the official PHP images, run PIE from its own image:

```dockerfile
FROM php:8.4-cli
RUN --mount=type=bind,from=ghcr.io/php/pie:bin,source=/pie,target=/usr/local/bin/pie \
    pie install restate/ext-restate
```

On Alpine, the module needs `libgcc`, which a minimal image may lack: add `apk add libgcc`.

### Checking that it loads

```bash
php -m | grep restate   # lists the module
php --ri restate        # prints the extension version
```

Check under the same PHP and SAPI that serves your endpoint: php-fpm and the CLI can read different php.ini files. From PHP code, `Restate\Extension\version()` returns the version.

What can go wrong:

| Problem | Cause | Fix |
| --- | --- | --- |
| PIE finds no prebuilt archive | Your platform has no prebuilt binary: macOS, Windows, a debug build of PHP, or glibc older than 2.28 | [Build from source](#building-from-source), or [use Nix](#with-nix) on macOS |
| PIE installs it but cannot enable it ("Expected extension restate to be loaded … but it was not detected"), or PHP reports ``version `GLIBC_2.xx' not found`` or a missing `libgcc_s.so.1` | The system's glibc is older than 2.28, or a minimal Alpine lacks `libgcc` | On Alpine, `apk add libgcc`. With an older glibc, build from source on that system. |
| PHP refuses to load it (build ID or API mismatch) | The module was built for another PHP minor version or thread safety, for example an NTS build loaded into FrankenPHP's ZTS PHP | Install it with PIE into the PHP that serves, which picks the matching build. From source, build against that PHP with `--php-config`. |

More failures and their fixes are in [Troubleshooting](troubleshooting.md). [Serving](serving.md#php-extension) has more on loading the module.

### Verifying release archives

Each release lists the SHA-256 checksums of its archives in `SHA256SUMS`. Each archive also has a build provenance attestation, recorded by the release run in this repository. Verify one with the [GitHub CLI](https://cli.github.com/):

```bash
gh attestation verify <archive> --repo restate-php/restate
```

PIE itself checks nothing beyond HTTPS for prebuilt archives.

### Building from source

There is no prebuilt binary for macOS (untested), Windows (not supported), debug builds of PHP, or glibc older than 2.28.

You need:

- the Rust toolchain pinned in `extension/rust-toolchain.toml` (rustup installs it automatically),
- libclang,
- the headers and `php-config` of the PHP that will load the module.

In a checkout of this repository:

```bash
bash bin/build-extension.sh --release   # prints the module's path: extension/target/release/restate.so
```

That builds for the PHP whose `php-config` is on `PATH`. To build for another PHP, for example a ZTS one for FrankenPHP, pass its `php-config`:

```bash
bash bin/build-extension.sh --release --php-config /path/to/zts/bin/php-config
# builds into extension/target/php<minor>-<nts|zts>/release/restate.so
```

A module loads only into the PHP minor version and thread safety (NTS or ZTS) it was built for.

Then install it:

1. Copy `restate.so` into PHP's extension directory: `php -r 'echo ini_get("extension_dir");'` prints it.
2. Add `extension=restate` to php.ini or to a file in its scan directory (`php --ini` shows both).
3. Check that `php -m` lists `restate`.

Or skip both steps and load it per command: `php -d extension=/path/to/restate.so …`.

Serve with a release build (`--release`, about 3 MB). A debug build (about 42 MB, with debug assertions) is for developing the extension.

If the build fails with `'stdlib.h' file not found`, bindgen cannot find the C headers. In a checkout, run the build inside the dev shell (`devenv shell`), which sets `BINDGEN_EXTRA_CLANG_ARGS`; elsewhere, set that variable to your compiler's include flags. [CONTRIBUTING.md](../CONTRIBUTING.md#building-the-extension) has more on building it for development.

### With Nix

The extension is a flake (`extension/flake.nix`, published as `github:restate-php/ext-restate`). Its overlay adds `restate` to every PHP of nixpkgs, built against the PHP that loads it. That works on macOS too.

To try it, run nixpkgs' PHP with the extension:

```bash
nix run github:restate-php/ext-restate#php -- --ri restate
```

To pin a version, put it in the reference: `github:restate-php/ext-restate/0.1.0`. The [extension's README](../extension/README.md#with-nix) shows how to add the overlay to your own flake or to a [devenv](https://devenv.sh) shell.

## Installing the SDK

Once the extension loads in the PHP that runs Composer, install the SDK:

```bash
composer require restate/sdk
```

Composer checks `ext-restate` against the PHP that runs it. If you install dependencies with a different PHP than the one that serves (for example in a separate Docker build stage), pass `--ignore-platform-req=ext-restate`, and make sure the serving PHP loads the extension.

For code that only calls services, install `restate/ingress` instead, which needs no extension. For tests, add `restate/testing` as a dev dependency.

## Next steps

- [Quickstart](../examples/quickstart/README.md): a Docker Compose setup with Restate and a PHP service.
- [Services](develop/services.md): define Basic Services, Virtual Objects and Workflows.
- [Serving](serving.md): serve your endpoint with `php -S`, PHP-FPM, FrankenPHP or a PSR-15 framework, and register it with Restate.

## Design notes

- [Single Native Backend](decisions/0012-single-native-backend.md): why there is no pure-PHP fallback
- [Package Split](decisions/0029-package-split.md)
- [Prebuilt Extension Binaries](decisions/0030-prebuilt-extension-binaries.md)
- [Release](decisions/0031-release.md)
