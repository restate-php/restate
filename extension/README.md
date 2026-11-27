# Restate PHP extension

The Restate PHP extension (module `restate`, file `restate.so`, `ext-restate` to Composer) is the native core of the [Restate](https://restate.dev) PHP SDK. It embeds [`restate-sdk-shared-core`](https://crates.io/crates/restate-sdk-shared-core), the Rust core the official TypeScript, Python and Rust SDKs are built on, through [`ext-php-rs`](https://github.com/extphprs/ext-php-rs): every protocol frame, journal entry and suspension of a handler goes through it. You need it to serve services with [`restate/sdk`](https://github.com/restate-php/sdk), which requires it; there is no pure-PHP fallback. The ingress client and the test harness do not load it in their own process.

> [!NOTE]
> A community-maintained SDK: not an official Restate SDK, and not supported by Restate.

## Installation

Install it with [PIE](https://github.com/php/pie), the PHP Foundation's extension installer:

```bash
pie install restate/ext-restate
```

PIE downloads a prebuilt `restate.so` for your PHP from the GitHub releases of [restate-php/ext-restate](https://github.com/restate-php/ext-restate): PHP 8.4, 8.5 or 8.6, NTS or ZTS (FrankenPHP), x86_64 or arm64, Linux with glibc 2.28 or newer or with musl (Alpine). It copies it into PHP's extension directory and enables it (`extension=restate`); if it cannot, add that line to php.ini or a file in its scan directory (`php --ini` shows both). Nothing is compiled. In an image based on the official PHP images:

```dockerfile
FROM php:8.4-cli
RUN --mount=type=bind,from=ghcr.io/php/pie:bin,source=/pie,target=/usr/local/bin/pie \
    pie install restate/ext-restate
```

Check that PHP loads it:

```bash
php --ri restate   # prints the extension version
```

Each release lists SHA-256 checksums of its archives (`SHA256SUMS`) and has build provenance attestations: `gh attestation verify <archive> --repo restate-php/restate`.

### With Nix

This repository is a flake (`flake.nix`, built with [ext-php-rs.nix](https://github.com/sagikazarmark/ext-php-rs.nix)). Its overlay adds `restate` to every PHP of nixpkgs, built against the PHP that loads it. That works on macOS too:

```nix
{
  inputs.ext-restate.url = "github:restate-php/ext-restate/0.1.0";

  outputs = { ext-restate, ... }:
    let
      pkgs = ext-restate.inputs.nixpkgs.legacyPackages.aarch64-darwin.extend ext-restate.overlays.default;
    in
    {
      packages.aarch64-darwin.php = pkgs.php.withExtensions ({ enabled, all }: enabled ++ [ all.restate ]);
    };
}
```

In [devenv](https://devenv.sh), add the input with its overlay to `devenv.yaml`:

```yaml
inputs:
  ext-restate:
    url: github:restate-php/ext-restate/0.1.0
    overlays:
      - default
```

Then load it in `devenv.nix` with `languages.php.extensions = [ "restate" ];`.

`nix run github:restate-php/ext-restate/0.1.0#php -- --ri restate` runs nixpkgs' PHP with it. The flake also builds the PIE archives of a release (`nix build .#pie`), and each build on its own, for any PHP minor, thread safety, target and build: `nix build .#extensions.php84.zts.x86_64-linux-glibc.release` (also `debug`, and the soak tests' `debug-diagnostics` and `release-diagnostics`).

## Version compatibility

The extension is released together with the `restate/*` packages, at the same version; `restate/sdk` requires the extension of its own minor version. [Compatibility](https://github.com/restate-php/restate/blob/main/docs/compatibility.md) lists each version with the shared-core version it carries and the PHP and Restate server versions it supports.

## Building from source

There is no prebuilt binary for macOS, Windows, debug builds of PHP or glibc older than 2.28. Building it needs the Rust toolchain pinned in `rust-toolchain.toml`, libclang and the headers of the PHP that will load it; a module loads only into the PHP minor version and thread safety it was built for. See [Building from source](https://github.com/restate-php/restate/blob/main/docs/installation.md#building-from-source) and [CONTRIBUTING.md](https://github.com/restate-php/restate/blob/main/CONTRIBUTING.md#building-the-extension) in the main repository.

## Documentation

See the [documentation](https://github.com/restate-php/restate/blob/main/docs/README.md) for the SDK.

This repository is a read-only subtree split of [restate-php/restate](https://github.com/restate-php/restate). Report issues and send pull requests there.

## License

MIT; see [LICENSE](LICENSE).
