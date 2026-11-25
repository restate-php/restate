# Releasing

A release publishes every package in `packages/` and the extension (`extension/`) at one version `X.Y.Z`, from one manual run of `.github/workflows/release.yaml` on `main`. Why it works this way is in [Release](docs/decisions/0031-release.md); the version rules are in [Bumping versions](#bumping-versions) below, which versions work together in [Compatibility](docs/compatibility.md).

Copy the list for the release at hand into its tracking issue or pull request and tick it off there.

## Before the first release

Done once.

### Decisions

- [x] The vendor name: `restate/*`, agreed with Restate.
- [x] The license and its copyright holder: MIT, Márk Sági-Kazár.

### Files in this repository

- [x] `LICENSE` at the root and in `extension/` and each of `packages/*` (the release refuses to run without them).
- [x] `README.md` in `extension/` and each of `packages/*`: what the package is, how to install it, a link back here. Packagist and the split repositories show it.
- [x] Composer metadata Packagist shows: `homepage`, `support` (issues, source), `authors` and `keywords` in each `composer.json` (`just check-packages` must still pass).

### GitHub

- [x] Immutable releases on the extension repository (optional; the release creates a draft, uploads, then publishes, so it works either way).
- [x] Run the push split (`.github/workflows/split.yaml`, [Release](docs/decisions/0031-release.md) decision 9) once by hand (Actions → **Split**) and check that every repository's `main` has the split, with its `composer.json`.
- [ ] Recommended: required reviewers on the `release` environment. Without them the `publish` job starts as soon as the jobs before it pass, without an approval. The push split uses the same environment, so they approve every push and hourly split run too.
- [x] Dependabot (`.github/dependabot.yaml`) keeps the workflows' commit-SHA action pins (and their version comments) current.

### Packagist

- [x] Claim the vendor on Packagist.
- [x] Submit the five repositories, `restate/ext-restate` included (PIE resolves the extension through Packagist), right after the first run that pushed them (the first manual **Split** run, or the first release's publish job): Packagist only accepts a repository with a `composer.json` on its default branch, and then reads the tags already there.
- [x] Packagist's GitHub integration (or its webhook) on each of the five, so later tags appear without a manual update.

## Every release

### 1. Prepare

- [ ] Pick the version by semantic versioning of the whole SDK. While it is 0.x: a new minor for breaking changes and new features, a patch for fixes. **A changed contract version (`CONTRACT_VERSION` in `extension/extension/src/lib.rs`) needs a new minor**; the release refuses a patch that changes it.
- [ ] Check upstream: the Restate server versions the tests run against (`RestateContainer::DEFAULT_IMAGE`, `.github/workflows/upstream.yaml`), `restate-sdk-shared-core`, the PHP minors in the build matrix. Updating them is a change of its own, before the release commit.
- [ ] On a branch, one commit "Release X.Y.Z":
  - [ ] `version = "X.Y.Z"` in `extension/extension/Cargo.toml`, then `cd extension && cargo update --workspace` so `extension/Cargo.lock` follows (the archives are built with `--locked`).
  - [ ] For a new minor: `"ext-restate": "~X.Y.0"` in `packages/sdk/composer.json` (`just check-packages` fails until it matches the crate).
  - [ ] The row for `X.Y.Z` in [Compatibility](docs/compatibility.md#versions): shared-core, contract and PHP versions, and "(unreleased)" dropped from it.
  - [ ] The changelog, if one is kept.
- [ ] `just check` passes locally; for a release that touches the extension or the protocol, also `just test integration php-cli`, `just test integration frankenphp` and `just test upstream`.
- [ ] Merge to `main` and push. `CI`, `Upstream SDK tests` and `Extension binaries` pass on that commit.
- [ ] Rehearse the split on that commit: `just split publish --dry-run --version X.Y.Z --remote https://github.com/restate-php/{repository}.git`. For every repository it reports `main` as created, fast-forwarded, up to date or left alone (ahead: the push split already pushed a later commit's split), and the tag (`X.Y.Z` for the extension, `vX.Y.Z` for the packages) as created; it pushes nothing, and fails on what would stop the release ([Release](docs/decisions/0031-release.md), decision 5).

### 2. Release

- [ ] Actions → **Release** → Run workflow, on `main`. Leave `skip-tests` off unless the tests passed on this exact commit and a re-run is only for publishing.
- [ ] The `version` job passes: it refuses an existing or older version, a `restate/sdk` constraint that is not `~X.Y.0`, a contract change in a patch, and a missing README or LICENSE.
- [ ] `ci`, `upstream`, `extension` and `attest` pass.
- [ ] Approve the `publish` job (with required reviewers on the environment).
- [ ] If it stops halfway: fix the cause and **Re-run failed jobs**. Pushed repositories and tags are skipped, the extension's draft release is uploaded to again. Never delete or move a tag that was pushed; a package repository whose `main` was changed by hand stops the release until it is put back.

### 3. Verify

- [ ] Each split repository has the commit and the tag: `X.Y.Z` on the extension's, `vX.Y.Z` on the packages'.
- [ ] The extension repository's release `X.Y.Z` is published (not a draft), marked latest when it should be, with 24 `php_restate-X.Y.Z_*.tgz` archives, `SHA256SUMS` and `php_restate-X.Y.Z.sigstore.json`.
- [ ] This repository's release `vX.Y.Z` exists with its notes.
- [ ] Packagist shows `X.Y.Z` for all four packages and `restate/ext-restate`.
- [ ] `gh attestation verify php_restate-X.Y.Z_<one archive>.tgz --repo restate-php/restate` (this repository, whose release run attests the archives) passes.
- [ ] In `php:8.4-cli-trixie`, `php:8.5-zts-trixie`, `php:8.4-cli-alpine` and an arm64 image: `pie install restate/ext-restate:X.Y.Z`, then `php --ri restate` reports `X.Y.Z`, and `composer require restate/sdk:X.Y.Z` in an empty project installs.
- [ ] The [Docker quickstart](examples/quickstart/README.md) works with the released extension and packages: run the **Quickstart** workflow by hand (Actions → **Quickstart**; its `released` job installs them with PIE and Composer).

### 4. Afterwards

- [ ] Announce it (Restate Discord, the repository's release notes) if it is worth announcing.
- [ ] Update [Status](docs/status.md) if the release changes what is supported.

## Bumping versions

The version is the `version` in `extension/extension/Cargo.toml`; shared-core 8.7.2 speaks service protocol v5–v8, of which Restate 1.8 negotiates v7 and 1.7 v6.

- **The version is the release's**, for the packages and the extension alike, by semantic versioning of the whole SDK: while it is 0.x, a new minor version for breaking changes and new features, a patch version for fixes. A release starts with the commit that sets it ([Release](docs/decisions/0031-release.md#releasing)). A released version never changes; until a version is released, changes to the tree update its row instead of adding one.
- **`restate/sdk`'s `ext-restate` constraint is `~X.Y.0` of that version**: a new minor version changes it in the same commit (`just check-packages` fails otherwise).
- **The contract version is bumped only by an incompatible change after a release.** It versions the extension surface (the classes, functions and argument and result shapes in `Restate\Extension`, described by `extension/restate.stub.php`) as released extensions carry it. `CONTRACT_VERSION` in `extension/extension/src/lib.rs` and `Backend::CONTRACT_VERSION` in `packages/sdk/src/Internal/Backend/Extension/Backend.php` always hold the same number and change in the same commit.
  - **Until the first release it stays 1**, whatever changes: an incompatible change to the surface updates the unreleased row in place, like any other change, since before a release no extension with another number exists ([Composer Metadata](docs/decisions/0026-composer-metadata.md#decision)).
  - **After a release**, the first incompatible change to the surface since that release bumps the contract version by one. **It needs a new minor version**: `restate/sdk` accepts every extension of its minor version, so a patch release with a new contract version would accept an extension with the old one (the release workflow refuses it). Further incompatible changes before that version is released keep the number, so a release brings at most one new contract version.
  - **Compatible changes never bump it**: a shared-core upgrade behind the same surface, a fix, a newly supported PHP minor.
- **Update the [Compatibility](docs/compatibility.md#versions) table in the same commit** as the version bump or the change that alters a column: the version (`cargo` updates `extension/Cargo.lock`, which the release builds with `--locked`), the shared-core pin in `extension/core/Cargo.toml`, the contract version, the PHP matrix (with the `php` constraint of every `packages/*/composer.json`, the root `composer.json` and `extension/composer.json`, `phps` in `extension/flake.nix` and the `verify` matrix of `.github/workflows/extension.yaml`, see [Composer Metadata](docs/decisions/0026-composer-metadata.md)), the prebuilt targets, or the server versions the suites ran against.
