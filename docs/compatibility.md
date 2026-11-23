# Compatibility

Which versions of the SDK, the Restate PHP extension, PHP and the Restate server work together, and on which platforms. The last section is for maintainers: how to bump versions.

## Versions

The Restate PHP extension (module `restate`, `ext-restate` to Composer, `restate/ext-restate` to PIE) is released together with the `restate/*` packages, at one version: `restate/sdk` `X.Y.Z` and `ext-restate` `X.Y.Z` come out together. The extension reports its version through `php --ri restate`, `phpversion('restate')` and `Restate\Extension\version()`, and Composer shows it for `ext-restate`.

| Version | `restate-sdk-shared-core` | Contract version | PHP versions | Prebuilt binaries | Restate server versions tested |
| --- | --- | --- | --- | --- | --- |
| 0.1.0 (unreleased) | 8.7.2 | 1 | 8.4, 8.5, 8.6 (NTS and ZTS) | Linux x86_64 and arm64, glibc 2.28+ and musl | 1.8.1 (1.7.14 best-effort) |

The newest row is the version in the tree.

**Matching the SDK and the extension.** `restate/sdk` requires the extension of its own minor version (`~X.Y.0`, now `~0.1.0`). So any patch release of the SDK works with any patch release of the extension of the same minor version. Composer enforces this when you install the SDK.

**The contract version.** At run time the SDK does not check the extension's version number. It checks the contract version instead: the extension reports it (`Restate\Extension\contract_version()`), and the SDK compares it with the one it needs. The contract version says whether the two speak the same interface. It catches what Composer cannot see, such as an extension built from another commit of this repository. On a mismatch, or when the extension is not loaded, building an endpoint throws `BackendUnavailableException`. The message names both contract versions and the package version, and says which side to upgrade ([Troubleshooting](troubleshooting.md)).

## Restate server versions

| Restate | Status |
| --- | --- |
| 1.8 or newer | Supported. The suites run against 1.8.1, the version `restate/testing` starts by default. |
| 1.7 | Best-effort. The integration suite passes on 1.7.14, but some features fail ([below](#restate-17)). |
| Older | Untested |

### Restate 1.7

These features need Restate 1.8. On 1.7 they fail as follows:

| Feature | What happens on Restate 1.7 |
| --- | --- |
| Pausing an invocation by throwing `PauseError` (from `$ctx->run()`, the handler, or an error mapper) | The attempt fails with error 573 ("Pausing the invocation needs service protocol v7 …"), and Restate retries it like any other failure. |
| A `$ctx->run()` retry policy with `onMaxAttempts: OnMaxAttempts::Pause` | The attempt fails at the run's first retryable failure ("Feature 'pause' is not supported …"), and Restate retries it. |
| Scoped calls, sends and attaches, and limit keys, from a handler | The attempt fails with error 573 ("A call to Billing/charge in scope 'tenant-1' needs service protocol v7 (Restate 1.8 or newer), but this invocation negotiated v6"), and Restate retries it. |
| Scoped requests from the ingress client | Restate answers 400 ("scoped invocations require vqueues to be enabled"); the client throws `IngressException`. |
| `$ctx->request()->idempotencyKey`, `->scope` and `->limitKey` | Always `null`: Restate 1.7 does not send them. |
| Preloading a list of state keys (`InvocationConfiguration(statePreload: ['…'])`) | Registering the deployment fails with a message saying so. `true`, `false` and `[]` (same as `false`) work. |

Everything else passes the integration suite on 1.7.14, named signals included. To fix any of these, upgrade Restate to 1.8 or newer.

## Platforms

The packages and the extension support PHP 8.4, 8.5 and 8.6, NTS and ZTS:

| PHP | NTS | ZTS (FrankenPHP) | Notes |
| --- | --- | --- | --- |
| 8.4 | Supported, most tested | Supported | Every suite runs on 8.4 NTS; the integration and worker suites on 8.4 ZTS under FrankenPHP. |
| 8.5 | Supported | Supported | Less tested |
| 8.6 | Supported | Supported | Least tested. The official PHP 8.6 images are ZTS only. Under `php -S`, raise `post_max_size` ([PHP settings](serving.md#php-settings)). |

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

## Bumping versions

<a id="how-to-bump"></a>
This section is for maintainers. The version is the `version` in `extension/extension/Cargo.toml`; shared-core 8.7.2 speaks service protocol v5–v8, of which Restate 1.8 negotiates v7 and 1.7 v6.

- **The version is the release's**, for the packages and the extension alike, by semantic versioning of the whole SDK: while it is 0.x, a new minor version for breaking changes and new features, a patch version for fixes. A release starts with the commit that sets it ([Release](decisions/0031-release.md#releasing)). A released version never changes; until a version is released, changes to the tree update its row instead of adding one.
- **`restate/sdk`'s `ext-restate` constraint is `~X.Y.0` of that version**: a new minor version changes it in the same commit (`composer check:packages` fails otherwise).
- **The contract version is bumped only by an incompatible change after a release.** It versions the extension surface (the classes, functions and argument and result shapes in `Restate\Extension`, described by `extension/restate.stub.php`) as released extensions carry it. `CONTRACT_VERSION` in `extension/extension/src/lib.rs` and `Backend::CONTRACT_VERSION` in `packages/sdk/src/Internal/Backend/Extension/Backend.php` always hold the same number and change in the same commit.
  - **Until the first release it stays 1**, whatever changes: an incompatible change to the surface updates the unreleased row in place, like any other change, since before a release no extension with another number exists ([Composer Metadata](decisions/0026-composer-metadata.md#decision)).
  - **After a release**, the first incompatible change to the surface since that release bumps the contract version by one. **It needs a new minor version**: `restate/sdk` accepts every extension of its minor version, so a patch release with a new contract version would accept an extension with the old one (the release workflow refuses it). Further incompatible changes before that version is released keep the number, so a release brings at most one new contract version.
  - **Compatible changes never bump it**: a shared-core upgrade behind the same surface, a fix, a newly supported PHP minor.
- **Update the table in the same commit** as the version bump or the change that alters a column: the version (`cargo` updates `extension/Cargo.lock`, which the release builds with `--locked`), the shared-core pin in `extension/core/Cargo.toml`, the contract version, the PHP matrix (with the `php` constraint of every `packages/*/composer.json`, the root `composer.json` and `extension/composer.json`, `phps` in `extension/flake.nix` and the `verify` matrix of `.github/workflows/extension.yaml`, see [Composer Metadata](decisions/0026-composer-metadata.md)), the prebuilt targets, or the server versions the suites ran against.

## Design notes

- [Prebuilt Extension Binaries](decisions/0030-prebuilt-extension-binaries.md): the targets of the prebuilt binaries
- [Release](decisions/0031-release.md): one version for the packages and the extension
- [Shared Core](decisions/0014-shared-core.md#server-compatibility): server compatibility
- [Pause](decisions/0021-pause.md), [Selective State Preloading](decisions/0020-selective-state-preloading.md), [Scope and Limit Key](decisions/0024-scope-and-limit-key.md): the features that need Restate 1.8
