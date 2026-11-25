# Composer Metadata

## Status

Accepted. Nothing is published yet; [RELEASING.md](../../RELEASING.md) has what the first release needs.

## Context

Only serving needs the extension: `Endpoint::serve()`, `Endpoint::psr()` and `Endpoint::builder()->build()` throw `BackendUnavailableException` when it is missing or speaks another contract version. The ingress client and the test harness run in processes without it ([Package Split](0029-package-split.md)).

What constrains the names and constraints:

- **PIE** accepts only extension names matching `^[A-Za-z][a-zA-Z0-9_]+$` ("hyphens are not allowed"), and its prebuilt binaries are per PHP minor (`php_{extension}-{version}_php{minor}-…`).
- **Composer** names an extension's platform package `ext-` plus the module name, and enforces a package's `php` upper bound only at install and update time: the generated `vendor/composer/platform_check.php` checks the lower bound alone.
- **A root package that requires an extension** cannot be installed until the extension is: `composer install` fails with "requires PHP extension … but it is missing from your system". Contributors build the extension from the repository after installing its dependencies.
- **`ext-php-rs`** supports a new PHP minor some time after its release ([Single Native Backend](0012-single-native-backend.md#evidence)), so a PHP constraint that promises every future minor promises builds nobody has made.

## Decision

1. **The extension is named `restate`.** The Cargo package `extension/extension` is named `restate`, so `ext-php-rs` registers the module as `restate` (`Backend::MODULE_NAME`); Cargo builds `librestate.so`, `just extension build` copies it to `restate.so`, and `extension=restate` loads it. Composer's platform name is `ext-restate`. The crate directory is `extension/extension`, next to the core crate in `extension/core`; the package name is what PHP and Composer see.
2. **The extension's PHP namespace is `Restate\Extension\`**: `Vm`, `IdentityVerifier`, `ExtensionException`, `version()`, `contract_version()` and `protocol_versions()`, described by `extension/restate.stub.php`. The extension registers the namespace at startup, so no package may declare anything in it (`just check-packages` fails otherwise). It is internal ([Public API Boundary](0016-public-api-boundary.md)).
3. **The extension lives in `extension/`**: the Cargo workspace (`core`, `extension`), its `rust-toolchain.toml` and the PIE package `extension/composer.json` (`restate/ext-restate`, [Prebuilt Extension Binaries](0030-prebuilt-extension-binaries.md)), which is also the directory the extension's repository is split from. It stays outside `packages/`: it is a Rust workspace with its own build, not one of the PHP packages.
4. **Vendor `restate`, namespace `Restate\`**, packages `restate/common`, `restate/sdk`, `restate/ingress` and `restate/testing`, plus the PIE package `restate/ext-restate` ([Package Split](0029-package-split.md)). The repository root is the development-only `restate/monorepo`.
5. **Only `restate/sdk` requires the extension**, as `ext-restate` `~X.Y.0`: the extension of the SDK's own minor version ([Release](0031-release.md)). No other package requires it, and neither does the root, so `composer install` works for contributors, who build the extension afterwards (`just extension build`). The runtime handshake stays: `BackendUnavailableException` names the module, the contract version the SDK requires and how to install it, and it is the only check that knows about contract versions. A CI job that only analyses an application can pass `--ignore-platform-req=ext-restate`.
6. **PHP `~8.4.0 || ~8.5.0 || ~8.6.0` in every package and in `extension/composer.json`**, also in packages that never load the extension: CI tests the packages together on exactly these minors. The `~` form names each supported minor, the unit PIE's binaries come in. A minor is added when the extension builds and passes on it, in the same change that adds it to the CI matrix and the prebuilt binaries.
7. **The extension has the packages' version** ([Release](0031-release.md)). The version lives in the crate (`extension/extension/Cargo.toml`), which the module reports through `phpversion('restate')` and `Restate\Extension\version()`. [Extension Versions](../compatibility.md) lists each version with the shared-core, contract, PHP and Restate server versions it carries.
8. **The contract version (`CONTRACT_VERSION` in the extension, `Backend::CONTRACT_VERSION` in the SDK) is 1, and stays 1 until the first release.** Before a release no extension carrying any other number exists, so an incompatible change to the Extension Surface updates the unreleased version in place. After a release, the first incompatible change bumps it by one, in a new minor version ([Bumping versions](../../RELEASING.md#bumping-versions)).
9. **What Packagist shows points at this repository.** Every published `composer.json` (`packages/*`, `extension/`) has the same `homepage`, `authors` and `support`: `issues` and `source` are `restate-php/restate`, not the package's read-only split, since that is where issues and pull requests go (as Laravel's `illuminate/*` packages point at `laravel/framework`); `keywords` differ per package. Each published directory has a `README.md` (what the package is, how to install it, links back here as absolute URLs, which work in the split) and the MIT `LICENSE`.

## Consequences

- `composer require restate/sdk` fails until the extension is installed (with PIE); `restate/ingress`, `restate/testing` and `restate/common` install without it.
- Users who serve without the matching extension find out at endpoint construction, with instructions.
- Composer refuses to install on PHP 8.6 (or 8.3) until the constraint is widened.
- Adding a PHP minor touches every `packages/*/composer.json`, the root `composer.json`, `extension/composer.json`, the CI matrix and the extension's build matrix in one change.
