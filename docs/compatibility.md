# Compatibility

Which versions of the SDK, the Restate PHP extension, PHP and the Restate server work together, and on which platforms. For maintainers, the rules for bumping the version and the contract version are in [RELEASING.md](../RELEASING.md#bumping-versions).

## Versions

The Restate PHP extension (module `restate`, `ext-restate` to Composer, `restate/ext-restate` to PIE) is released together with the `restate/*` packages, at one version: `restate/sdk` `X.Y.Z` and `ext-restate` `X.Y.Z` come out together. The extension reports its version through `php --ri restate` and `phpversion('restate')`, and Composer shows it for `ext-restate`.

| Version | `restate-sdk-shared-core` | Contract version | PHP versions | Prebuilt binaries | Restate server versions tested |
| --- | --- | --- | --- | --- | --- |
| 0.1.0 (unreleased) | 8.7.2 | 1 | 8.4, 8.5, 8.6 (NTS and ZTS) | Linux x86_64 and arm64, glibc 2.28+ and musl | 1.8.1 |

The newest row is the version in the tree.

**Matching the SDK and the extension.** `restate/sdk` requires the extension of its own minor version (`~X.Y.0`, now `~0.1.0`). So any patch release of the SDK works with any patch release of the extension of the same minor version. Composer enforces this when you install the SDK.

**The contract version.** At run time the SDK does not check the extension's version number. It checks the contract version instead: the extension reports it, and the SDK compares it with the one it needs. The contract version says whether the two speak the same interface. It catches what Composer cannot see, such as an extension built from another commit of this repository. On a mismatch, or when the extension is not loaded, building an endpoint throws `BackendUnavailableException`. The message names both contract versions and the package version, and says which side to upgrade ([Troubleshooting](troubleshooting.md)).

## Restate server versions

| Restate | Status |
| --- | --- |
| 1.8 or newer | Supported. The suites run against 1.8.1, the version `restate/testing` starts by default. |
| Older | Unsupported. The SDK advertises service protocol v7 as its minimum, so Restate 1.7 and older cannot invoke it: Restate 1.7.14 registers the deployment, then fails every invocation with `RT0013` (incompatible service protocol versions) and keeps retrying. |

## Platforms

The packages and the extension support PHP 8.4, 8.5 and 8.6, NTS and ZTS:

| PHP | NTS | ZTS (FrankenPHP) | Notes |
| --- | --- | --- | --- |
| 8.4 | Supported, most tested | Supported | Every suite runs on 8.4 NTS; the integration and worker suites on 8.4 ZTS under FrankenPHP. |
| 8.5 | Supported | Supported | Less tested |
| 8.6 | Supported | Supported | Least tested. PHP 8.6's official images are ZTS (`php:8.6-cli-*`, `php:8.6-fpm-*`), except the NTS CLI variant `php:8.6-nts-*`. Under `php -S`, raise `post_max_size` ([PHP settings](serving.md#php-settings)). |

Prebuilt extension binaries, which PIE installs, exist for:

| Platform | Prebuilt binary | Notes |
| --- | --- | --- |
| Linux x86_64, glibc 2.28 or newer | Yes | Where every suite runs |
| Linux arm64, glibc 2.28 or newer | Yes | Only the extension's own suite runs on arm64 |
| Linux x86_64 and arm64, musl (Alpine) | Yes | Needs `libgcc` (`apk add libgcc`) |
| Linux with glibc older than 2.28 | No | [Build from source](installation.md#building-from-source) |
| macOS | No | Untested. [Build from source](installation.md#building-from-source) or [use Nix](installation.md#with-nix); CI builds the module there and runs the extension's own checks, but no SDK suite. |
| Windows | No | Not supported |
| Debug builds of PHP | No | [Build from source](installation.md#building-from-source) |

[Status](status.md#supported-platforms) has the evidence behind every row: which suites ran where.

## Design notes

- [Prebuilt Extension Binaries](decisions/0030-prebuilt-extension-binaries.md): the targets of the prebuilt binaries
- [Release](decisions/0031-release.md): one version for the packages and the extension
- [Shared Core](decisions/0014-shared-core.md#server-compatibility): server compatibility, and why Restate 1.8 is the minimum
- [Pause](decisions/0021-pause.md), [Selective State Preloading](decisions/0020-selective-state-preloading.md), [Scope and Limit Key](decisions/0024-scope-and-limit-key.md): features that came with Restate 1.8
