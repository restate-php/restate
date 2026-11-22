# Release

## Status

Accepted. The workflow has not run on GitHub yet: nothing is published, and the repositories, the GitHub App and the Packagist entries it needs do not exist yet ([RELEASING.md](../../RELEASING.md)).

## Context

A release publishes four Composer packages (`packages/*`) and the extension's PIE package (`extension/`). Packagist publishes a package from the root of a repository only, so each one needs a read-only repository of its own that the release fills from this one; PIE also looks for the extension's prebuilt archives on the GitHub release of the extension's repository ([Prebuilt Extension Binaries](0030-prebuilt-extension-binaries.md)).

A release should be one run, started by hand in CI, that does everything else, after the pattern of `sagikazarmark/atuin-worker`'s release workflow (a manual run on `main` that picks the version with `sagikazarmark/next-version`, runs the build and tests as a reusable workflow, attests the artifact and creates the GitHub release). Nothing is published from a developer's machine.

## Decision

1. **Lockstep: one version for every package and the extension.** Release `X.Y.Z` tags this repository `vX.Y.Z`, every package repository `vX.Y.Z` and the extension repository `X.Y.Z` (bare: PIE puts the tag in the asset names, [Prebuilt Extension Binaries](0030-prebuilt-extension-binaries.md) decision 11), and the extension reports `X.Y.Z`. Users install the same version of both, and there is one compatibility table ([Extension Versions](../EXTENSION_VERSIONS.md)). The cost: a release rebuilds and republishes the extension even when it did not change, and a new minor version of the SDK needs the new minor of the extension. The versions can be split again later (at 1.0, say).
2. **`restate/sdk` requires the extension of its own minor version: `~X.Y.0`** (`~0.1.0` now). Within a minor version, patch releases of either side work together; a contract version change ([Extension Versions](../EXTENSION_VERSIONS.md#how-to-bump)) therefore needs a new minor version, which the workflow checks. `~X.Y.0` reads the same before and after 1.0, where `^X.Y` would accept every later minor.
3. **The version is in the tree: the extension's crate version** (`extension/extension/Cargo.toml`). The module compiles it in (`ext-php-rs` registers `CARGO_PKG_VERSION`; `Restate\Extension\version()` returns it), so the release cannot pass it at build time without editing files, and the split extension repository's sources then say the version they were released as. A release starts with a commit that sets it (`extension/Cargo.lock` follows; the archives are built with `--locked`) and, for a new minor version, `restate/sdk`'s constraint; `composer check:packages` fails when the constraint is not `~X.Y.0` of the crate version. The workflow has no version input: [`sagikazarmark/next-version`](https://github.com/sagikazarmark/next-version) only checks the crate version (not a tag yet, newer than the latest `vX.Y.Z`) and gives the previous tag and whether it is the latest release.
4. **One workflow, `.github/workflows/release.yaml`, run by hand on `main`** (`workflow_dispatch`; one release at a time):
   1. **version** reads and checks the version, then the tree: `restate/sdk`'s constraint is `~X.Y.0`; the contract version (`CONTRACT_VERSION` in the extension crate) is the previous release's unless the minor version changed; every directory it publishes has a `README.md` and a `LICENSE`.
   2. **ci** and **upstream** run `.github/workflows/ci.yaml` and `upstream.yaml` on the release commit (they are reusable workflows, `workflow_call`). The `skip-tests` input skips them, as an escape hatch.
   3. **extension** runs `.github/workflows/extension.yaml`, the 24 archives with their checks (it only builds; it also runs on changes to `extension/` and by hand); **attest** checks that they are of the release version, writes `SHA256SUMS` and attests them (`actions/attest-build-provenance`; the provenance is recorded in this repository) and names the bundle `php_restate-X.Y.Z.sigstore.json`.
   4. **publish** (environment `release`, after every job above passed or was skipped) publishes in dependency order: the extension repository (split, tag `X.Y.Z`, a release with the archives, `SHA256SUMS` and the bundle, created as a draft and published once complete, since PIE sees published releases only and immutable releases accept no assets after publishing); then each package repository (split, tag `vX.Y.Z`); then this repository's release `vX.Y.Z` with generated notes, which creates its tag. The extension comes first so that the packages, which Packagist picks up within minutes, never require an extension that cannot be installed yet.
5. **Split with `git subtree split`**: the history of the directory, on the repository's `main`, fast-forward only. It is built into git, needs no other tool, keeps each file's history in the package repository, and gives the same commits every time, so a re-run pushes nothing new. All of them together take about a minute. Chosen over `splitsh-lite` (faster, used by Symfony and Laravel, but a binary to download and pin for a minute saved) and over `danharrin/monorepo-split-github-action` (copies the directory as one new commit per release, so the package repositories have no file history and a re-run commits again).
6. **Re-runnable, never forced.** A repository's `main` only moves forward; a tag that exists on the same commit is skipped and one on another commit stops the release; the extension release is uploaded to again while it is a draft and left alone once published; this repository's release is skipped when it exists. "Re-run failed jobs" finishes a release that stopped halfway. Nothing is force-pushed: a package repository that someone pushed to stops the release until it is repaired by hand.
7. **A GitHub App pushes.** `actions/create-github-app-token` mints a token for the job that can only write the contents of the five repositories (`permission-contents: write`, the repositories listed in the workflow); it is not tied to a person's account and does not expire like a personal token. This repository's release uses the workflow's own token. The repositories are listed once, in the workflow's `SPLITS` (directory and repository name), all owned by the `RELEASE_OWNER` variable, `restate-php`, which also owns this repository (`restate-php/restate`). Each is named like its package without the vendor (`restate/sdk` is `restate-php/sdk`): `ext-restate`, `common`, `ingress`, `sdk`, `testing`.
8. **Actions are pinned by commit** in the release workflow (it holds the App's key), with the version in a comment; the other workflows still pin by tag ([TESTING.md](../../TESTING.md#ci)).

## Releasing

The checklist is [RELEASING.md](../../RELEASING.md): what to set up once before the first release (a LICENSE and README in every published directory, the five empty repositories, Packagist, the GitHub App and the `release` environment with `RELEASE_APP_PRIVATE_KEY`, `RELEASE_APP_CLIENT_ID` and `RELEASE_OWNER`), and the steps of every release (the version commit, the run, the approval, the checks afterwards).

## Verification

- `actionlint` (in `composer check:scripts`) passes on every workflow.
- The publish step's split, push and tag logic ran against local bare repositories, one per split directory (`file://` remotes instead of GitHub): a release pushed every directory's history and tag (the extension's bare), a second run of it pushed nothing, a tag already on another commit stopped it with an error, and a repository whose `main` was rewritten refused the push (non-fast-forward).
- `composer check:packages` fails with `restate/sdk must require ext-restate ~0.1.0, the minor version of the extension crate, not '^0.1'` when the constraint lags the crate version.
- The version job's tree checks, run locally: they report every published directory without a README or LICENSE; against a throwaway local tag `v0.1.0` whose `CONTRACT_VERSION` was 0, releasing `0.1.1` fails ("The contract version changed since v0.1.0 (0 -> 1): release a new minor version") and `0.2.0` fails until `restate/sdk` requires `~0.2.0`.
- Not verified: anything on GitHub (the App token, the reusable workflow calls, the attestation, the release creation and publication, Packagist picking up the tags), because no job has run there yet.

## Consequences

- Releasing is one manual run after a version commit; nothing is published from a developer's machine.
- Every release publishes 24 extension archives, also when only PHP code changed, and every new minor version of `restate/sdk` needs the same new minor of the extension.
- The release takes as long as its slowest test job (the integration and FrankenPHP suites, the upstream suite) plus the arm64 and musl builds.
- Adding a package means a line in `SPLITS`, a repository, the App's installation on it and a Packagist entry.
