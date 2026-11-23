# Subtree split tooling

As of 2026-11-22. This note compares the tools that can publish a monorepo directory, with its history, as its own repository. It was written to help decide whether the release workflow should keep `git subtree split` ([0031 Release](../decisions/0031-release.md), decision 5; `.github/workflows/release.yaml`, `SPLITS` and `split()`).

The constraints it is judged against:

- The split repositories' `main` is only fast-forwarded. Every run, on any tool version, must produce the same commit IDs.
- Per-file history should be kept.
- Speed is nice to have.
- The tool should be available from Nix (pin `2786a2a72088ecf32aea5b3ec52099e6a7cff78f`).
- The publish job should stay small.

Facts are cited to primary sources: the tool's own source or docs at a named tag or commit, git's commit messages, and nixpkgs package files at the pin. Results marked "measured" come from the experiments in [Appendix A](#appendix-a-empirical-check), which you can re-run. Anything not checked is marked **unverified**.

## Summary and recommendation

**Keep `git subtree split`.** The evidence supports it for this repository's constraints:

- **It is deterministic by contract.** The docs guarantee that repeated splits of the same history produce the same commit IDs when the split options are the same ([git-subtree.adoc v2.56.0 L111–113][gs-doc]). Its maintainers removed an optimisation in 2026 because it changed split hashes, writing: "The split history and its commit hashes are API contract, so this is not permissible" ([git commit 1f70684b517f][g-1f70]).
- **Measured: its IDs held across git versions on every test history but one.** git-subtree.sh from v2.20.0, v2.43.0, v2.53.0, v2.54.0 and v2.56.0 gave identical commit IDs on all four synthetic histories.
  - The exception was v2.44.0–v2.51.x. Those versions gave different IDs only on a history containing `git subtree add/pull --squash` commits nested inside the split directory. That is the regression git itself documents ([83f9dad7d6fb][g-83f9], [1f70684b517f][g-1f70]).
  - The faulty code only acts on commits carrying `git-subtree-dir:` trailers. This repository has none.
- **It is built in.** git 2.56.0 at the nixpkgs pin builds and installs it ([package.nix L296, L350–352][n-git]). The ubuntu-24.04 runner ships git 2.56.1 ([runner-images Ubuntu2404-Readme.md L79][gh-runner]).
- **No alternative is better on the constraint that matters: the same IDs, and fast-forward-safe splits.**
  - **splitsh-lite:** measured, its IDs differ from git ≥ 2.20 when a merge has more than one parent whose split tree equals the merge's. In one such history, a split published at an earlier `main` commit was not an ancestor of the split at the tip, so a later release would be a non-fast-forward push. It is not in nixpkgs. Its last release, v2.0.0 (2023), ships no binaries and needs libgit2 1.5 through the git2go binding, whose maintenance the project's own issue tracker questions ([#81][sl-81]).
  - **josh:** measured, it keeps more merges than git subtree, preserves `gpgsig` headers byte for byte, and has changed its defaults between versions ([filters.md L64–75, L77–110][j-filters]).
  - **Copybara and `danharrin/monorepo-split-github-action`:** they write new commits, with a new committer identity and the current time. They do not reproduce the directory's history the way git subtree does.

**What would justify switching before the first release.** Switching is cheapest now, but none of the triggers below exists today:

- **Speed becomes a problem.** git subtree split re-walks the whole history on every run. Measured: about 106 s for one directory of Twig (7,852 commits) on a laptop; about 8–9 minutes for five directories of that size.
  - The credible replacement is git-filter-repo 2.47.0 with `--subdirectory-filter <dir> --preserve-commit-hashes --prune-empty always --prune-degenerate always`. Measured: it produced exactly the same commit IDs as `git subtree split` on the merge-heavy synthetic history and on Twig's real history (110 commits, 30 merges), in about 3 s instead of about 106 s.
  - It differed only where git subtree itself misbehaves:
    - backslashes in author names (git subtree drops them);
    - a split directory that is deleted and re-added (git subtree then links the unsplit monorepo history).
  - Because the IDs matched, this switch could probably be made after releases too. Verify that on the real history first. The equivalence is empirical, not guaranteed by either project.
- **You want to delete and re-add a split directory, or vendor code with `git subtree add/merge`.** git subtree handles both badly (see [git subtree split](#git-subtree-split-git-contrib)). Avoiding them is cheaper than switching tools.

**Optional hardening that keeps `git subtree split`** (none of these is required):

- **Pin the git that splits.** The publish job uses whatever git the runner image carries, installed from the git-core PPA ([install-git.sh][gh-installgit]). Running the split through the pinned nixpkgs git would pin git-subtree.sh as well. Shopware already pins an older git (Alpine 3.19, git 2.43.7) for its `git subtree split` job ([05-prepare-release.yml L86–87][u-shopware]).
- **Never pass `--annotate`, `-S`/`--gpg-sign` or `--rejoin`, and leave `i18n.commitEncoding` unset.** Measured: setting `i18n.commitEncoding=ISO-8859-1` adds an `encoding` header to every split commit and changes every ID. Setting `commit.gpgSign=true` has no effect, because `git commit-tree` does not read it ([commit-tree.c L129][g-committree]).
- **Keep the fast-forward-only push.** It is the backstop: a diverging split stops the release instead of rewriting a package repository.

**Two corrections to the reasoning recorded in decision 0031:**

- **Symfony:** "splitsh-lite … used by Symfony" is not documented by Symfony.
  - What Symfony documents: it splits with a hosted service, split.sh, configured by `splitsh.json` ([symfony/symfony splitsh.json][u-symfony]). The service's engine is not documented.
  - Measured: Twig's published `twigphp/intl-extra` 3.x branch is byte-identical to `splitsh-lite --git='<1.8.2'` output. That is consistent with splitsh-lite in its legacy-message mode, but it is not proof.
  - Laravel's use of splitsh-lite is documented ([laravel/framework split.yml][u-laravel]).
- **`danharrin/monorepo-split-github-action`:** "a re-run commits again" is not what its source does. It commits only when the copied directory differs from the target ([entrypoint.php v2.4.5 L104–120][a-entry]). It then re-runs `git tag` through `execOrDie`, so a re-run whose tag already exists probably fails (read from the source, not run). The decision's main point stands: the action produces one snapshot commit per run and no per-file history.

## Comparison

| Tool | Mechanism | Same commit IDs as `git subtree split` (measured)? | Incremental? | Maintained (as of 2026-11-22) | nixpkgs at pin | GitHub Action | Notable users (evidence below) |
|---|---|---|---|---|---|---|---|
| `git subtree split` (git contrib) | Shell script. Rewrites each commit that touches the directory with `git commit-tree`; drops commits and merges that do not change it. | Reference. Stable across git-subtree.sh 2.20–2.43 and 2.52–2.56 on all tests. 2.44–2.51 differ on histories with nested `--squash` subtree merges. ≤ 2.19 differs on some merges. | No persistent cache; temporary per-run cache. `--rejoin` adds merge commits to the monorepo. | Yes. Fixes in 2025–2026; git 2.56.1. `contrib/` status. | Yes, in `git` 2.56.0 | Not needed (runner git) | Shopware; this repository |
| splitsh-lite (splitsh/lite) | Go + libgit2 reimplementation of git subtree's algorithm as of git 2.8–2.19. | Linear histories and Twig: yes. Merges with several tree-identical parents: **no**. Directory deleted and re-added: **no**. `--git='<1.8.2'` and `<2.8.0` emulate older git. | Yes (`.git/splitsh.db` bolt cache) | Last release v2.0.0, 2023-10-26, no binaries. Last commit 2025-11-30. Open issues about hash mismatches (#65, #88). | **No** | Third-party wrappers that download the v1.0.1 (2017) binary | Laravel, Drupal, Sylius, API Platform, Flysystem, Mautic, Hyperf; likely Symfony (engine undocumented) |
| git-filter-repo | `fast-export` → Python filter → `fast-import`. Rewrites the whole clone. | Defaults: **no** (keeps originally empty commits, rewrites hashes in messages). With `--preserve-commit-hashes --prune-empty always --prune-degenerate always`: **yes** on all ordinary tests and Twig. Differs on backslash names and delete/re-add. | No (`--state-branch` exists but is hidden and marked as having problems) | Last release v2.47.0, 2024-12-04; `main` active to 2026-07 | Yes, 2.47.0 | No official one; single file, can be vendored | CakePHP, Neos/Flow (slicer); GitHub Docs recommends it |
| `git filter-branch --subdirectory-filter` | Shell; replays every commit selected by path-limited `rev-list`. | Yes on merge tests. Same as filter-repo on edge cases (differs from git subtree there). | `--state-branch` | In git, but its docs say "its use is not recommended" | Yes, in `git` | n/a | CakePHP until 2026-03 |
| josh / josh-filter | Rust + libgit2 filter DSL with a persistent cache; also a proxy. | **No**. Keeps merges whose filtered tree differs from the first parent; keeps `gpgsig` and `encoding` headers. Defaults changed across versions. | Yes (`.git/josh` cache) | Very active; r26.08.28 | Yes, 26.05.08 (includes `josh-filter`) | None found (**unverified**) | Rust (josh-sync, two-way sync) |
| Copybara | Java. Reads origin changes, transforms them, writes new commits to the destination; state is a `GitOrigin-RevId` label in the destination. | **No**. New committer and current time; label added to the message; first-parent history by default. | Yes (by design, reads its label back) | Very active; weekly releases, v20261116 | Yes, 20261026 (jar + JDK 25) | None official (**unverified**) | Google (`GitOrigin-RevId` trailers) |
| danharrin/monorepo-split-github-action (was symplify/github-action-monorepo-split) | Docker action. Clones the target, copies the directory over it, makes one commit. | **No**; no history is rewritten. | n/a (appends a snapshot) | v2.4.5, 2026-05-12; dependency bumps 2026-11 | n/a | Is one (Docker, built from `php:8.1-cli-alpine` at run time) | Filament, Spiral, Ecotone |
| symplify/monorepo-builder | Does not split; its README points to the action above. | n/a | n/a | 12.7.2, 2026-07-31 | n/a | n/a | n/a |
| dflydev/git-subsplit | Wrapper around `git subtree split`. | Same as git subtree | No | Last commit 2017-01-26 | No (**unverified**) | No | No current notable user found |

## The tools

### git subtree split (git contrib)

**How it works.** `git-subtree.sh` is a shell script.

- It lists the history with `git rev-list --topo-order --reverse --parents` ([L974][gs-split]).
- For each commit, it reads the directory's tree and maps the parents to their already-split commits.
- `copy_or_skip` then reuses a parent when the tree is identical. It copies the commit when a merge must keep history along another branch: the merge-base and `rev-list --count` checks ([L695–769][gs-copy]).
- Copying runs `git log -1 --pretty=format:'%an%n%ae%n%aD%n%cn%n%ce%n%cD%n%B'` into shell `read`s and `git commit-tree` ([copy_commit L518–543][gs-copycommit]).
- The cache lives in a per-run directory, `$GIT_DIR/subtree-cache/$$` ([L282–292][gs-cache]). It is never reused and, measured, never deleted. dflydev's README also warns about the leftover cache ([git-subsplit README][a-subsplit]).

**Determinism.** The docs guarantee identical IDs for repeated splits with the same options, and warn that `--annotate` breaks this ([git-subtree.adoc L111–113, L200–210][gs-doc]). Changes in git that altered split IDs, from git's own history:

- **v1.8.2:** `%B` for the message ([a5b8e28e4eb5][g-a5b8]). splitsh-lite's `--git='<1.8.2'` emulates the older format.
- **v2.8.0:** merges whose non-identical parent carries history are kept ([933cfeb90b5d][g-933c]).
- **v2.20.0:** "When multiple identical parents are detected … if not, a merge must be performed to maintain history" ([68f8ff81513f][g-68f8]). Confirmed by the script's content at v2.19.0 and v2.20.0.
- **v2.44.0:** `should_ignore_subtree_split_commit` skipped commits from other subtrees ([98ba49ccc247][g-98ba]).
  - git 2.52 fixed it for nested `--squash` subtrees ([83f9dad7d6fb][g-83f9]): "This causes missing tree files and different commit hashes starting in git v2.44.0-rc0". v2.52.0 and v2.53.0 have the same git-subtree.sh.
  - git 2.54.0 removed it entirely ([1f70684b517f][g-1f70]). An intermediate rewrite was merged and then reverted ([28a7e27cff71][g-28a7], [ab689ea7f91a][g-ab68]).
- **v2.51.0:** `-S`/`--gpg-sign` was added ([fea50ce4118c][g-fea5]). It is opt-in.

**Other properties:**

- **Signing:** `git commit-tree` only reads `git_default_config` ([commit-tree.c L129][g-committree]), so a local `commit.gpgSign=true` does not sign split commits. Measured: the IDs were unchanged with it.
- **Encoding:** `i18n.commitEncoding` does change the output. Measured: with `ISO-8859-1`, every split commit gets an `encoding` header.
- **Merges:** kept only when needed to preserve history, as above.
- **Commits outside the directory:** dropped, and map to the nearest split ancestor.
- **Commits from before the directory existed:** no split commit.
- **Originally empty commits:** dropped.
- **Known quirks (measured):**
  - `read` without `-r` drops backslashes from author and committer names. That is deterministic, but no other tool does it.
  - When a split directory is deleted and later re-added, the deleting commit is cached as itself ([L826–835][gs-psc], the "ugly … subtree vs. a mainline commit" branch). The re-added commit's split then has the *original monorepo commit* as its parent, so the split would carry the whole monorepo history. Here the fast-forward-only push would refuse it.

**Incrementality and speed:**

- There is no persistent cache. `--rejoin` merges the split back into the monorepo so later splits can stop there ([L234–249][gs-doc]). That rewrites the monorepo history, which is not acceptable here.
- Commits with `git-subtree-dir:` trailers are used as stopping points ([find_existing_splits L460][gs-split]).
- Measured: about 106 s, and 92.6 s in an earlier run, for one directory of Twig (7,852 commits) on an arm64 laptop.
- Published measurements:
  - 98ba49ccc247 reports a `--rejoin` split going from "~10-12 minutes" to "seconds" ([98ba49ccc247][g-98ba]).
  - 1f70684b517f says that optimisation "is actually a speed penalty in many cases".
  - Shopware pins an older git because of "a performance regression" ([05-prepare-release.yml L86–87][u-shopware]).

**Maintenance.** It is part of git; v2.56.1 is the newest tag. git's `contrib/README` says contrib tools are "owned by their respective primary authors", not by the git maintainer ([contrib/README][g-contrib]). It received fixes in 2025 and 2026 (commit list in the sources). License: GPL-2.0, as git ([COPYING][g-copying]).

**Availability:**

- **nixpkgs:** `git` 2.56.0 at the pin builds and installs contrib/subtree ([pkgs/by-name/gi/git/package.nix L69, L296, L350–352][n-git]).
- **Homebrew:** git 2.56.1 installs `git-subtree` ([Formula/g/git.rb L156–161][h-git]).
- **GitHub runners:** ubuntu-24.04 image 20261116.376.1 has git 2.56.1 ([readme L79][gh-runner]).
- **GitHub Action:** none needed.

**Users.** Shopware runs `git subtree split -q -P src/${package}/` ([.github/bin/split.bash L32][u-shopware-split]) inside `node:lts-alpine3.19`, whose git is 2.43.7 ([Alpine package page][alpine-git]). dflydev/git-subsplit wraps it ([git-subsplit.sh L224][a-subsplit-sh]).

**Fit.** It meets every constraint today: deterministic, built in, keeps per-file history, about a minute now. Its risks are a floating runner git version, which pinning removes, and history shapes this repository does not have.

### splitsh-lite (splitsh/lite)

**How it works.** A Go reimplementation of `git subtree split` on libgit2, through git2go v34 ([go.mod v2.0.0][sl-gomod]).

- It walks the history in reverse topological order and maps each commit's parents through a cache.
- `copyOrSkip` mirrors git subtree's `copy_or_skip` **before git 2.20**. When several parents have the merge's tree, it keeps the *last* one (`identical = parent`) and has no merge-base check ([state.go L365–443, L380][sl-state]).
- Copied commits keep the raw message bytes and replace an empty email with `nobody@example.com` ([copyCommit L461–491][sl-state-copy]).
- When the directory is missing, the commit is skipped and not cached ([L219–222, L263–275][sl-state-tree]). A deleted-then-re-added directory therefore starts a new root.

**Determinism and compatibility:**

- **Its own claims:** the README says "two splits of the same code always results in the same history and the same `sha1`s", and that migrating from `git subtree split` "is easy as both tools generate the same `sha1`s" ([README v2.0.0, Usage and L146–157][sl-readme]).
- **The `--git` option:** `--git` accepts `<1.8.2`, `<2.8.0` and `latest` ([config.go][sl-config]). The cache key includes it ([cache.go][sl-cache]).
- **Its own test suite:** one test compares against `git subtree split`; the merge test uses hard-coded hashes ([run-tests.sh][sl-tests]).
- **Measured:**
  - Same IDs as git subtree on linear histories and on Twig (`extra/intl-extra`, 3.x).
  - Different IDs from git ≥ 2.20 on the synthetic merge history: 9 commits instead of 13. It dropped a change and its revert, and one of two identical-change branches.
  - It matched git-subtree.sh from v2.19.0 and earlier on that history.
  - Splitting at the revert commit gave a split that is **not an ancestor** of the split at the tip. A release made at that commit followed by a later one would be a non-fast-forward push.
  - It also differs after a directory is deleted and re-added, for empty emails, and for non-UTF-8 messages.
- **Open issues report mismatches:** [#65][sl-65] (2021, git 2.34.1 vs v1.0.1) and [#88][sl-88] (2026-07, git 2.9.4 and 2.54.0: "official git generates a history with initial commit, and lite do not").
- **Twig's published split:** `twigphp/intl-extra` 3.x equals `splitsh-lite --git='<1.8.2'` byte for byte (`b349d35f…`), which differs from git subtree's message format.

**Incrementality and speed.** The cache in `.git/splitsh.db` (bolt) makes re-runs cheap ([cache.go L25][sl-cache]). Measured on Twig:

- v2.0.0: 23.4 s cold, 0.025 s warm.
- master `36e0e74`: 0.2 s cold. Master writes the database once at the end ([26da73e][sl-26da]); v2.0.0 writes it per commit, which is slow on macOS.
- Laravel's workflow does not keep the cache: it "references split commits that only exist in the illuminate/* repos". It reports "A cold split of every component takes about a minute" ([split.yml L104–105][u-laravel]).

**Maintenance:**

- v2.0.0 (2023-10-26) is the last release, with no binaries. v1.0.1 (2017) has `lite_linux_amd64` and `lite_darwin_amd64` ([releases][sl-releases]).
- Master has unreleased changes, the last on 2025-11-30 ([36e0e74][sl-master]). There are 23 open issues, including "No prebuilt binaries for 2.0.0" ([#82][sl-82]), "git2go on life-support" ([#81][sl-81]), a go-git port ([#86][sl-86]) and a Nix flake ([#87][sl-87]).
- License: MIT.

**Availability:**

- **nixpkgs:** no file in the pinned tree mentions `splitsh`. The pin's libgit2 is 1.9.7 ([libgit2/package.nix L26][n-libgit2]), while git2go v34 needs libgit2 1.5 (README v2.0.0, Manual Installation).
- **Homebrew:** not in homebrew-core (the formulae.brew.sh API returns 404).
- **Building:** measured, v2.0.0 builds with the pin's Go 1.27.3 against libgit2 1.5.2 built from the pin with an override (Appendix A). Laravel builds master from source because "the committed bin/splitsh-lite needs OpenSSL 1.1" ([split.yml L20–23][u-laravel]).
- **GitHub Action wrappers**, all third-party. Each downloads the 2017 v1.0.1 binary:
  - acrobat/subtree-splitter v1.1.5, hard-coded URL ([src/index.ts L25–34][a-acrobat]);
  - SyliusLabs/SplitPackageAction v1.0;
  - frankdejonge/use-subsplit-publish 1.1.1;
  - jderusse/gitsplit (a Docker image using splitsh-lite as a library).

**Fit.** It is fast, but it fails the main constraint. Its IDs are not git subtree's on some merge shapes, it can produce splits that do not fast-forward, and it adds a binary that has to be built (libgit2 1.5) and is not in nixpkgs.

### git-filter-repo

**How it works.** It pipes `git fast-export --show-original-ids --signed-tags=strip … --reencode=yes` through a Python filter into `git fast-import` ([script L4345–4347][f-script]; [docs, internals][f-doc]). It rewrites every ref of a clone and refuses to run outside a fresh clone without `--force` ([docs L73, L545][f-doc]).

**Options that matter here** ([docs][f-doc]):

- `--subdirectory-filter <dir>` is `--path <dir>/ --path-rename <dir>/:` (L156–159).
- By default it rewrites abbreviated hashes in messages; `--preserve-commit-hashes` turns that off (L199–204).
- It re-encodes messages to UTF-8 (L206–210).
- `--prune-empty auto` keeps originally empty commits (L248–253).
- `--prune-degenerate auto` prunes merges only when they become degenerate *and* empty (L255–263).

**Determinism and compatibility (measured):**

- With defaults, IDs differ from git subtree. It keeps an originally empty commit and a degenerate merge, and rewrites a hash in a message.
- With `--preserve-commit-hashes --prune-empty always --prune-degenerate always`, it produced the same IDs as `git subtree split` (≥ 2.20) on:
  - the synthetic merge history;
  - the nested-subtree history;
  - Twig's real history: `a16ab56c…`, 110 commits with 30 merges, from 7,852 commits with 2,641 merges.
- It differed only on a backslash in an author name, which git subtree mangles, and on a directory deleted and re-added, where git subtree links the mainline history.
- On signed commits, it drops the signature, as git subtree does.
- In the unreleased main branch, signatures are parsed and round-tripped, but "by default signatures are still stripped" ([308accc26ab9][f-sig], [2a118d2ac218][f-sig2]). A future option could change IDs only if used.

**Incrementality and speed.** It is not incremental. `--state-branch` exists but is hidden (`help=argparse.SUPPRESS`) with the comment "WARNING: --state-branch has some problems" ([script L2230–2246][f-script-state]). Measured: 2.9 s on Twig, plus the clone. Its README says filter-branch is "multiple orders of magnitude slower" ([README][f-readme]).

**Maintenance.** v2.47.0 (2024-12-04) is the last release; main had commits until 2026-07-09. License: MIT, or the licenses git and libgit2 use ([COPYING][f-copying]). It requires git ≥ 2.22 and Python ≥ 3.6 ([README L41–43][f-readme]).

**Availability:**

- **nixpkgs:** `git-filter-repo` 2.47.0 ([python-modules/git-filter-repo/default.nix L13][n-gfr]; [all-packages.nix L1079][n-allpkgs]).
- **Homebrew:** 2.47.0 ([Formula/g/git-filter-repo.rb][h-gfr]).
- **Other:** a single Python file, which CakePHP vendors. No official GitHub Action.

**Users.**

- CakePHP runs `python3 contrib/git-filter-repo --subdirectory-filter … --force`, then `git push -f` ([Makefile L182–183][u-cake]). It switched from filter-branch in 2026-03.
- Neos/Flow's slicer calls `git-filter-repo --subdirectory-filter` ([neos/slicer slicer.php L159–165][u-neos]).
- GitHub Docs recommends it for splitting a subfolder ([GitHub Docs][gh-docs]).

**Fit.** It is the only measured tool that reproduced git subtree's IDs on real history. It is fast and in nixpkgs. It costs a clone per directory, three non-default flags, and some trust that the equivalence holds for future history shapes. It is the fallback if speed ever matters.

### git filter-branch --subdirectory-filter

**How it works.** It is part of git: a shell loop replaying the commits chosen by `rev-list` with path limiting, through `git commit-tree`.

**Status.** git's own documentation says: "'git filter-branch' has a plethora of pitfalls … These safety and performance issues cannot be backward compatibly fixed and as such, its use is not recommended. Please use an alternative history filtering tool such as git filter-repo" ([git-filter-branch.adoc v2.56.0 L19–30][g-fb]).

**Measured:**

- Same IDs as git subtree on the merge and nested-subtree histories.
- Same as git-filter-repo, and therefore different from git subtree, on the backslash and delete/re-add cases.
- Without `--prune-empty`, the results were the same on these histories.

**Availability.** It ships with git, so it is in nixpkgs and on the runner.

**Fit.** Deprecated by its own project. There is no reason to adopt it.

### josh / josh-filter (josh-project/josh)

**How it works.** A Rust filter engine on libgit2 with a filter DSL (`:/dir` selects a subdirectory) and a persistent cache, so that "the work needed to re-run a filter on a new commit … becomes proportional to the number of changes since the last run" ([intro.md L33–39][j-intro]). The FAQ calls it "essentially a much faster implementation of `git subtree split`" ([faq.md L20–24][j-faq]). It also offers a proxy that serves filtered views.

**Determinism and compatibility.** Measured: its output is the same run to run, cold and warm. It is not git subtree's:

- **Merges:** "Normally, Josh will drop merge commits from the filtered history if their filtered tree is identical to the first parent's tree" ([filters.md L64–66][j-filters]). It keeps merges that git subtree drops. Measured: on Twig, 176 commits and 96 merges, against 110 and 30 for git subtree.
- **Signatures:** "By default Josh preserves the `gpgsig` header byte-for-byte", which makes the signature invalid ([L77–90][j-filters]). This repository's commits are GPG-signed.
- **Version changes:** "In older versions of Josh, `keep-trivial-merges` was the default behavior." An older version "accidentally normalized `\r\n` to `\n`" in signatures, producing "different commit hashes" ([L73–75, L103–106][j-filters]). The output has changed across josh versions.

**Maintenance.** Very active: releases r26.04.19 through r26.08.28 in 2026, and commits on 2026-11-17. License: MIT.

**Availability.**

- **nixpkgs:** `josh` 26.05.08 builds the whole workspace, `josh-filter` included ([pkgs/by-name/jo/josh/package.nix L13, L39][n-josh]). Measured: the cached binary ships `josh-filter`.
- **Homebrew:** not in homebrew-core.
- **GitHub Action:** none found (**unverified**).

**Users.** Rust syncs subtrees such as Miri with rust-lang/josh-sync, which installs josh at `r26.08.28` ([miri CONTRIBUTING.md L385–392][u-miri]; [josh-sync src/josh.rs L11][u-joshsync]). That is a two-way sync, not a read-only publish.

**Fit.** Its IDs differ from git subtree, its defaults have changed between versions, and it keeps invalid signatures by default. It does not suit a "same IDs forever" constraint.

### Copybara (google/copybara)

**How it works.** Copybara "is stateless, or more specifically, that it stores the state in the destination repository (As a label in the commit message)" ([README L30–32][c-readme]).

- `core.workflow` modes: `SQUASH` (the default) and `ITERATIVE`, which imports each origin change ([reference.md L1931, L1945][c-ref]).
- The label is `GitOrigin-RevId` ([L1953][c-ref]).
- `git.origin` defaults to `first_parent=True` ([L3888][c-ref]).
- The first run needs `--init-history` ([L1986][c-ref]).

**Determinism (measured).** It wrote 10 first-parent commits for `packages/sdk`.

- Authors and author dates were preserved.
- The committer was the configured identity, with the current time.
- `GitOrigin-RevId: <monorepo sha>` was appended to each message.
- A re-run exited 4 ("Iterative workflow produced no changes in the destination") and left the destination unchanged.

So it appends rather than reproduces: the IDs are never git subtree's and would differ between two fresh runs. Measured as well: on the first attempt it inherited the user's `commit.gpgSign` git config and tried to sign.

**Maintenance.** Weekly releases; v20261116 on 2026-11-16. License: Apache-2.0.

**Availability.** nixpkgs `copybara` 20261026, the release jar run with JDK 25 ([pkgs/by-name/co/copybara/package.nix L17, L23][n-copybara]). Not in homebrew-core. No official GitHub Action (**unverified**).

**Users.** Google; `GitOrigin-RevId` trailers appear in, for example, bazelbuild/reclient `9d90f13c` (from the usage survey).

Meta's fbshipit is archived; its README says "Meta has switched to using a (currently) closed-source solution … Existing Open Source users can check out Copybara" ([fbshipit README][u-fbshipit]).

**Fit.** Too heavy (JVM, configuration). It changes committer metadata and messages, and keeps only first-parent history by default.

### danharrin/monorepo-split-github-action (formerly symplify/github-action-monorepo-split)

Both `symplify/github-action-monorepo-split` and `symplify/monorepo-split-github-action` now resolve to this repository (GitHub API), and its README notes the image moved from Docker Hub `symplify2/monorepo-split`.

**How it works.** The action is a Docker action built at run time from `FROM php:8.1-cli-alpine` plus `apk add git` ([Dockerfile][a-docker]). On each run it:

1. clones the target repository with a token ([entrypoint.php L37][a-entry]);
2. keeps only its `.git` and copies the package directory into it with `cp -ra` (L64–79);
3. commits with the first line of the monorepo commit's message, if anything changed (L104–120, L141–145);
4. tags (L123–131).

**Determinism.** Not applicable: it never reproduces history, and each commit is new, with the current time. The package repository gets one snapshot commit per run, so there is no per-file history from the monorepo.

**Maintenance.** v2.4.5 on 2026-05-12, with dependency bumps through 2026-11-17. `composer.json` says MIT, but GitHub detects no license file.

**Users.** Filament pins it by SHA (`@14e42e24… # v2.4.5`); Spiral and Ecotone pin by tag (from the usage survey).

**Fit.** It fails the per-file history constraint, and its unpinned base image is rebuilt on every run.

### symplify/monorepo-builder

It has no split command. Its README says "To split packages into separate repositories, use symplify/github-action-monorepo-split" ([README 12.7.2 L348–350][a-mb]). The latest release is 12.7.2 (2026-07-31).

### Others

- **dflydev/git-subsplit:** a wrapper that runs `git subtree split -q --prefix=… --branch=…` per directory ([git-subsplit.sh L224][a-subsplit-sh]). Last commit 2017-01-26. Same IDs as git subtree, because it is git subtree.
- **contao/monorepo-tools:** a custom PHP splitter used by contao/contao, which keeps history (from the usage survey). Its IDs relative to git subtree are **unverified**.
- **GitHub itself:** offers no split feature. Its documentation recommends git-filter-repo ([GitHub Docs][gh-docs]).
- **Custom copy scripts** (flow-php, TYPO3 soul-design-system): they snapshot like the danharrin action (from the usage survey).

## Who uses what

All of these come from a survey of each project's own files at the commits listed. The Laravel, Shopware, CakePHP, Symfony, Drupal and API Platform entries were re-read for this note.

| Project | Tool | Evidence | Installed or pinned how |
|---|---|---|---|
| Laravel (`illuminate/*`) | splitsh-lite | [`.github/workflows/split.yml` @1caec34a][u-laravel] L20–23, L104–105; `bin/split.sh` L11–12 (`git push … -f`) | Built from splitsh/lite master `36e0e74` and git2go v34 by SHA; force-pushes |
| Symfony (symfony/symfony, ux, ai, polyfill) | split.sh hosted service, configured by `splitsh.json`; engine undocumented | [`splitsh.json` @2894e4ee][u-symfony], added in [291a54c6][u-symfony-commit] (2025-12-11, "Add split.sh config") | Off GitHub |
| Twig (twigphp) | Consistent with `splitsh-lite --git='<1.8.2'` (measured; engine undocumented) | Appendix A, Twig check | Off GitHub |
| Drupal (drupal/core, core-\*) | splitsh-lite in the Drupal Association's subtree-splitter | [gitlab.com/drupal-infrastructure/subtree-splitter README @7f9dd9dd][u-drupal] ("Subtree splitting is performed by splitsh-lite") | v1.0.1 binary committed to that repository (survey) |
| Sylius | splitsh-lite through SyliusLabs/SplitPackageAction@v1.0 | `.github/workflows/packages_split.yaml` @9a37349f L42 (survey) | Downloads v1.0.1 without a checksum |
| API Platform | splitsh-lite | [`.github/workflows/release.yml` @42f5b4b0 L38–45][u-apip] | v1.0.1 tarball with a SHA-256 check |
| Shopware | `git subtree split` | [`05-prepare-release.yml` @551447a2 L86–107][u-shopware], [`split.bash` L32][u-shopware-split] | `node:lts-alpine3.19` image (git 2.43.7) |
| CakePHP | git-filter-repo (vendored) | [`Makefile` @45992900 L182–183][u-cake] | Vendored script, force-push |
| Neos / Flow | git-filter-repo in neos/slicer (Jenkins) | [slicer.php @e67375ce L159–165][u-neos] (survey) | Expected on PATH; switched from splitsh-lite in 2023 |
| Filament, Spiral, Ecotone | danharrin/monorepo-split-github-action | Filament `.github/workflows/monorepo-split.yml` @65a0a121 L40 (survey) | Pinned by SHA (Filament) or by tag |
| Flysystem; TYPO3 render-guides | splitsh-lite through frankdejonge/use-subsplit-publish | `publish-subsplits.yml` @12930254 (survey) | v1.0.1 |
| Mautic, Kunstmaan | splitsh-lite through acrobat/subtree-splitter v1.1.5 | survey; [action source][a-acrobat] | v1.0.1, hard-coded |
| OpenTelemetry PHP | jderusse/gitsplit (splitsh-lite as a library) | `split-monorepo.yaml` @f0667f79 L22 (survey) | Docker image pinned by digest |
| Rust (Miri and others) | josh via josh-sync (two-way) | [miri CONTRIBUTING.md][u-miri], [josh-sync][u-joshsync] | `cargo install … --tag r26.08.28` |
| Google | Copybara | `GitOrigin-RevId` trailers, e.g. bazelbuild/reclient `9d90f13c` (survey) | Internal |
| Yii 2, TYPO3 core | Not documented | yii2 `docs/internals/project-organization.md` L31 calls yii2-framework "a read-only git subsplit" (survey) | Unknown |

Not monorepos, or no split tooling found (survey): Doctrine, Spatie, Laminas/Mezzio, AMPHP, Nette (a metapackage), ReactPHP (a metapackage), Yii 3 (separate repositories).

Several users force-push the split branches (Laravel `-f`, CakePHP `-f`, API Platform `$FORCE_FLAG`). Their setups tolerate ID changes that this repository's fast-forward-only policy does not.

## Appendix A: empirical check

**Environment:**

- macOS 27.2 (Darwin), arm64, on 2026-11-22.
- The scratch directory was `/private/tmp/claude-501/-Users-mark-Projects-restate-php-restate/64e0f713-b37b-49ab-bd59-579ff4661933/scratchpad/split-research/`, called `$R` below. The scripts are in `$R/scripts/`.
- This repository was not modified; its HEAD is a single empty-tree root commit, so there was nothing in it to split.
- Every comparison was run twice: once with the user's git config, and once with `GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1`. The results were identical.

**Tool versions:**

| Tool | Version and source |
|---|---|
| git | 2.56.0, the nixpkgs build (`/nix/store/5gpqd5y6g8wav4sgry5i9qmp0kwnnwby-git-2.56.0`) |
| Older git-subtree versions | `contrib/subtree/git-subtree.sh` downloaded at tags v1.8.2, v2.8.0, v2.19.0, v2.20.0, v2.43.0, v2.44.0, v2.51.0, v2.53.0 and v2.54.0, installed as `git-subtree<ver>` in `$R/bin` and run as `git subtree<ver>` by the git 2.56.0 binary. Only the script differs, so this approximates those git versions. v2.52.0's script is byte-identical to v2.53.0's, and v2.55.0's and v2.56.1's to v2.54.0's. |
| splitsh-lite | v2.0.0 (`806f4764`) and master (`36e0e74c`), built from source as below |
| josh-filter | r26.05.08, `nix build github:NixOS/nixpkgs/2786a2a7…#josh` |
| git-filter-repo | 2.47.0, `nix build github:NixOS/nixpkgs/2786a2a7…#git-filter-repo` |
| Copybara | v20261026, `nix build github:NixOS/nixpkgs/2786a2a7…#copybara` |

**Building splitsh-lite.** The pin has no libgit2 1.5. libgit2 1.5.2 was built with an override of the pinned `libgit2` package, then splitsh-lite was compiled with the pin's Go:

```nix
# $R/build/libgit2-1.5.nix
let pkgs = import (builtins.fetchTarball "https://github.com/NixOS/nixpkgs/archive/2786a2a72088ecf32aea5b3ec52099e6a7cff78f.tar.gz") { };
in pkgs.libgit2.overrideAttrs (old: {
  version = "1.5.2";
  src = pkgs.fetchFromGitHub { owner = "libgit2"; repo = "libgit2"; rev = "v1.5.2"; hash = "sha256-zZetfuiSpiO0rRtZjBFOAqbdi+sCwl120utnXLtqMm0="; };
  cmakeFlags = [ "-DREGEX_BACKEND=pcre2" "-DUSE_SSH=OFF" "-DBUILD_SHARED_LIBS=ON" "-DBUILD_TESTS=OFF" "-DBUILD_CLAR=OFF" ];
  buildInputs = [ pkgs.zlib pkgs.openssl pkgs.pcre2 ];
  doCheck = false; postInstall = "";
})
```

```sh
nix-build --no-out-link -E '(import ./libgit2-1.5.nix).dev'   # -> /nix/store/pc2g6rx3…-libgit2-1.5.2-dev
git clone --depth 1 --branch v2.0.0 https://github.com/splitsh/lite.git lite-v2.0.0
P=github:NixOS/nixpkgs/2786a2a72088ecf32aea5b3ec52099e6a7cff78f
nix shell $P#go $P#pkg-config $P#pcre2.dev -c sh -c \
  'export PKG_CONFIG_PATH=<libgit2-1.5.2-dev>/lib/pkgconfig:$PKG_CONFIG_PATH;
   cd lite-v2.0.0 && CGO_ENABLED=1 go build -ldflags "-X main.version=v2.0.0" -o $R/build/splitsh-lite-v2.0.0 .'
# go1.27.3 darwin/arm64; "splitsh-lite version v2.0.0". The same for master 36e0e74.
```

**Histories.** All were built with fixed author and committer names, emails and dates (`GIT_AUTHOR_DATE`/`GIT_COMMITTER_DATE`). Each script rebuilds the same commit IDs.

**`mono`** (`$R/scripts/make-repo.sh`): 23 commits, 5 merges, split directory `packages/sdk`. Tip `6d2c39ed013ae2a97843b5e404bfb21967f6514e`. In order:

1. a root-only commit from before `packages/sdk` exists;
2. a commit adding it;
3. commits outside it;
4. a feature merge where only the feature touched it;
5. a merge where both sides touched it;
6. an originally empty commit (`--allow-empty`);
7. a change and its `git revert` on main, then a merge of a branch that did not touch it;
8. the same change made on two branches, each merged;
9. a message naming another commit's abbreviated hash;
10. a final in-directory commit and a final root-only commit.

**`edge`** (`$R/scripts/make-edge.sh`): 11 linear commits with commit objects crafted by `git hash-object --literally`, split directory `d`. Tip `a68a3a16b9958ce4dab4fb86a22d33558cdb6461`. It contains:

- a `gpgsig` header, a fake signature like a GitHub web merge's;
- a backslash in the author name;
- a message without a trailing newline;
- an ISO-8859-1 message with an `encoding` header;
- CRLF line endings in a message;
- an empty author email;
- a mode-only change;
- a two-line subject.

**`readd`** (`READD=1 make-edge.sh`): the same, plus `d` deleted and re-added. Tip `72244a141723d24da314e92f94d1fcc6e33bf761`.

**`stmerge`** (`$R/scripts/make-subtree-merge.sh`): `git subtree add --squash` and `git subtree pull --squash` of another repository at `packages/sdk/vendor/lib` (inside the split directory) and at `vendor/other` (outside it). Split directory `packages/sdk`. Tip `64785387f3c4fd16a2ca19632446875fc0a29df4`.

**Commands.** `$R/scripts/compare.sh <repo> <prefix>` clones the repository (`git clone --no-local`) and runs:

```sh
git subtree split --prefix="$prefix" HEAD                  # twice
git subtree<ver> split --prefix="$prefix" HEAD             # old git-subtree.sh versions
splitsh-lite-v2.0.0 --prefix="$prefix" --scratch           # then again with a warm cache,
                                                           # then --git='<2.8.0' and --git='<1.8.2'
splitsh-lite-master --prefix="$prefix" --scratch
josh-filter ":/$prefix" HEAD --update refs/josh/split
git-filter-repo --force --subdirectory-filter "$prefix"    # in a fresh clone, three variants:
                                                           # defaults; --preserve-commit-hashes;
                                                           # --preserve-commit-hashes --prune-empty always --prune-degenerate always
git filter-branch [--prune-empty] --subdirectory-filter "$prefix" main   # in a fresh clone
```

**Results.** Tip commit, with the number of commits in its history in parentheses:

| Tool | mono `packages/sdk` | edge `d` | readd `d` | stmerge `packages/sdk` |
|---|---|---|---|---|
| `git subtree split`, git 2.56.0, two runs | `9b141fa4` (13) | `0092a618` (9) | `60d7a219` (12) | `33b87456` (6) |
| git-subtree.sh v2.20.0, v2.43.0, v2.53.0, v2.54.0 | `9b141fa4` (13) | `0092a618` | `60d7a219` | `33b87456` |
| git-subtree.sh v2.44.0, v2.51.0 | `9b141fa4` | `0092a618` | `60d7a219` | **`c5675579` (1)** |
| git-subtree.sh v1.8.2, v2.8.0, v2.19.0 | **`41e28502` (9)** | `0092a618` | `60d7a219` | `33b87456` |
| splitsh-lite v2.0.0 `--git latest`, `--git '<2.8.0'`, master; warm cache the same | **`41e28502` (9)** | **`93893f1b`** (9) | **`ff16faf0` (1)** | `33b87456` |
| splitsh-lite v2.0.0 `--git '<1.8.2'` | `381f0a5a` (9) | `31557ff7` | `b3947096` (1) | `5d0b9b87` |
| josh-filter r26.05.08 | `a899f4b9` (16) | `76cef83a` (9) | `0b664c1d` (11) | `33b87456` |
| git-filter-repo, defaults | `7c56ffc6` (15) | `478f8366` (9) | `729ff421` (11) | `33b87456` |
| git-filter-repo `--preserve-commit-hashes` | `1b92333d` (15) | `478f8366` | `729ff421` | `33b87456` |
| git-filter-repo `--preserve-commit-hashes --prune-empty always --prune-degenerate always` | **`9b141fa4` (13)** | `478f8366` | `729ff421` | `33b87456` |
| git filter-branch, with or without `--prune-empty` | `9b141fa4` (13) | `478f8366` | `729ff421` | `33b87456` |

**Why the IDs differ:**

- **mono, splitsh-lite and git-subtree ≤ 2.19:**
  - At the merge after the change-and-revert, they took the second parent's split commit, dropping the change and the revert from the split history.
  - At the merge of the two identical changes, they took the last parent, dropping the other branch and the merge.
- **mono, josh:** it additionally kept the one-sided feature merge, the originally empty commit and the first identical-change merge.
- **mono, filter-repo with defaults:** it kept the originally empty commit and one degenerate merge, and rewrote the abbreviated hash in the message of the "c21" commit, step 9 above (`98e47a633157` became `b96edf090410`).
- **edge,** compared commit by commit on everything except the tree and parent lines:
  - Signature: git subtree, splitsh-lite and filter-repo drop it; josh keeps it.
  - Backslash in the author name: git subtree writes `Backslash Name`; all others keep the backslash.
  - Latin-1 message: git subtree and filter-repo write UTF-8 without the header; splitsh-lite keeps the Latin-1 byte without the header; josh keeps the header and the byte.
  - Empty email: splitsh-lite writes `nobody@example.com`.
  - No trailing newline, CRLF, mode change, two-line subject: identical in every tool.
- **readd:**
  - git subtree's split of the re-adding commit has the original monorepo commit `8b86bf1` ("e11 remove d") as its parent, so its history consists of original monorepo commits.
  - splitsh-lite starts a new root commit.
  - filter-repo, filter-branch and josh keep "e11 remove d" as an empty-tree commit.

**Fast-forward check (mono).** Each tool split `packages/sdk` at the revert commit `98e47a6` on main, and the result was checked against the tool's own split at the tip:

- `git subtree split`: `7fbaeac` is an ancestor of `9b141fa` (fast-forward).
- splitsh-lite master: `7fbaeac` is **not** an ancestor of its tip `41e2850`.

The test was `git merge-base --is-ancestor`, with splitsh-lite run with `--origin=heads/<branch>`.

**Configuration sensitivity of `git subtree split` (mono and edge):**

- `-c commit.gpgSign=true`, `log.showSignature=true`, `log.date=relative` and `i18n.logOutputEncoding=ISO-8859-1`: unchanged `9b141fa4`.
- `-c i18n.commitEncoding=ISO-8859-1`: changed to `1b078578`, because every commit gets `encoding ISO-8859-1`. On edge it changed to `fd450aee`.

**Twig (real history).** A full clone of twigphp/Twig 3.x at `5bf872a1a2ed30b40415c423ad9d1e23286df74c`: 7,852 commits, 2,641 merges, directory `extra/intl-extra`. Timed with `$R/scripts/time-twig.sh` on the laptop, one run each; these are not GitHub runner numbers.

| Tool | Tip | Time |
|---|---|---|
| `git subtree split`, git 2.56.0 | `a16ab56c…` (110 commits, 30 merges) | 106.0 s; an earlier run on a blobless bare clone took 92.6 s |
| splitsh-lite v2.0.0, cold (`--scratch`) | `a16ab56c…` | 23.4 s |
| splitsh-lite v2.0.0, warm cache | `a16ab56c…` | 0.025 s |
| splitsh-lite master, cold | `a16ab56c…` | 0.197 s |
| splitsh-lite v2.0.0 `--git='<1.8.2'` | `b349d35f…` | 23.2 s |
| git-filter-repo 2.47.0 `--preserve-commit-hashes --prune-empty always --prune-degenerate always` (fresh clone) | `a16ab56c…` | 2.9 s, excluding the clone |
| josh-filter r26.05.08, cold | `bd67af63…` (176 commits, 96 merges) | 0.47 s |
| josh-filter r26.05.08, warm | `bd67af63…` | 0.045 s |

The published `https://github.com/twigphp/intl-extra.git` branch 3.x was at `b349d35fd155421794dfd4ba4facd8edaf04a49d` (`git ls-remote`, 2026-11-22), the same as the `--git='<1.8.2'` split.

Its root commit has the same tree, author and committer as git subtree's root, but its message ends in `\n\n` instead of `\n`. That is the pre-1.8.2 message format: subject, blank line, body ([splitsh-lite utils.go SplitMessage][sl-utils], [state.go legacyMessage][sl-state-copy]).

**Copybara** (`$R/copybara/copy.bara.sky`, running against the mono repository):

```python
core.workflow(name = "sdk",
    origin = git.origin(url = "file://$R/repos/mono", ref = "main"),
    destination = git.destination(url = "file://$R/copybara/dest.git", fetch = "main", push = "main"),
    origin_files = glob(["packages/sdk/**"]), authoring = authoring.pass_thru("Release Bot <bot@example.com>"),
    mode = "ITERATIVE", transformations = [core.move("packages/sdk", "")])
```

Run with `copybara migrate copy.bara.sky sdk --init-history --force`, and with `GIT_CONFIG_GLOBAL` set to a file containing only a user name and email and `commit.gpgSign = false`. Without that file it inherited the user's signing config and failed at `gpg`.

- It produced 10 commits, tip `c84b8e83`.
- The committer was `Release Bot` with the run's wall-clock time.
- Each message ended with `GitOrigin-RevId: <monorepo sha>`.
- A second `copybara migrate copy.bara.sky sdk` exited 4 with "Iterative workflow produced no changes in the destination for resolved ref: 6d2c39ed…".

**Not verified:**

- Any tool on a GitHub runner.
- Real git binaries for versions other than 2.56.0; only their git-subtree.sh scripts were run.
- The splitsh-lite v1.0.1 release binary.
- Copybara's committer-date behaviour beyond this one run.
- josh's and Copybara's GitHub Action availability.

## Sources

Primary sources, at the tag or commit stated.

**git (git/git):**

- [gs-split]: `contrib/subtree/git-subtree.sh` v2.56.0, https://github.com/git/git/blob/v2.56.0/contrib/subtree/git-subtree.sh: find_existing_splits L460, process_split_commit L792–843, cmd_split L925–1021, rev-list L974
- [gs-copy]: same file, copy_or_skip L695–769, https://github.com/git/git/blob/v2.56.0/contrib/subtree/git-subtree.sh#L695-L769
- [gs-copycommit]: same file, copy_commit L518–543, https://github.com/git/git/blob/v2.56.0/contrib/subtree/git-subtree.sh#L518-L543
- [gs-cache]: same file, cache_setup L282–292, https://github.com/git/git/blob/v2.56.0/contrib/subtree/git-subtree.sh#L282-L292
- [gs-psc]: same file, the no-tree branch L826–835, https://github.com/git/git/blob/v2.56.0/contrib/subtree/git-subtree.sh#L826-L835
- [gs-doc]: `contrib/subtree/git-subtree.adoc` v2.56.0, L111–113 (guarantee), L200–210 (`--annotate`), L234–249 (`--rejoin`), https://github.com/git/git/blob/v2.56.0/contrib/subtree/git-subtree.adoc
- [g-1f70]: commit 1f70684b517f "contrib/subtree: process out-of-prefix subtrees" (2026-02-18; in v2.54.0), https://github.com/git/git/commit/1f70684b517f4fcfeb7b998b0b7f3146ee8a8c75
- [g-98ba]: commit 98ba49ccc247 "subtree: fix split processing with multiple subtrees present" (2023-12-01; in v2.44.0), https://github.com/git/git/commit/98ba49ccc247c3521659aa3d43c970e8978922c5
- [g-83f9]: commit 83f9dad7d6fb "contrib/subtree: fix split with squashed subtrees" (2025-09-10; in v2.52.0), https://github.com/git/git/commit/83f9dad7d6fb5988b68f80b25bd87c68693195dd
- [g-28a7]: commit 28a7e27cff71 "contrib/subtree: detect rewritten subtree commits", https://github.com/git/git/commit/28a7e27cff717e5ef91f7445e6a418068608082d
- [g-ab68]: its revert, ab689ea7f91a, https://github.com/git/git/commit/ab689ea7f91ab0858e85776f31102203d3ea7b83
- [g-68f8]: commit 68f8ff81513f "subtree: improve decision on merges kept in split" (in v2.20.0), https://github.com/git/git/commit/68f8ff81513fb3599ef3dfc3dd11da36d868e91b
- [g-933c]: commit 933cfeb90b5d "contrib/subtree: fix "subtree split" skipped-merge bug" (in v2.8.0), https://github.com/git/git/commit/933cfeb90b5d03b4096db6d60494a6eedea25d03
- [g-a5b8]: commit a5b8e28e4eb5 "contrib/subtree: use %B for split subject/body" (in v1.8.2), https://github.com/git/git/commit/a5b8e28e4eb5762c9da68b56efdf45d6c885bd56
- [g-fea5]: commit fea50ce4118c "contrib/subtree: add -S/--gpg-sign", https://github.com/git/git/commit/fea50ce4118c
- Commit history of git-subtree.sh: https://github.com/git/git/commits/master/contrib/subtree/git-subtree.sh
- [g-committree]: `builtin/commit-tree.c` v2.56.0 L129 (`repo_config(the_repository, git_default_config, NULL)`), https://github.com/git/git/blob/v2.56.0/builtin/commit-tree.c#L129; `Documentation/git-commit-tree.adoc` v2.56.0 L62–68
- [g-fb]: `Documentation/git-filter-branch.adoc` v2.56.0 L19–30, https://github.com/git/git/blob/v2.56.0/Documentation/git-filter-branch.adoc#L19-L30
- `Documentation/git-fast-export.adoc` v2.56.0 L47–51 (`--signed-commits`, default `strip`), https://github.com/git/git/blob/v2.56.0/Documentation/git-fast-export.adoc#L47-L51
- [g-contrib]: `contrib/README` v2.56.0, https://github.com/git/git/blob/v2.56.0/contrib/README
- [g-copying]: `COPYING` v2.56.0, https://github.com/git/git/blob/v2.56.0/COPYING

**splitsh-lite (splitsh/lite):**

- [sl-readme]: `README.md` v2.0.0, https://github.com/splitsh/lite/blob/v2.0.0/README.md
- [sl-state]: `splitter/state.go` v2.0.0 L365–443, https://github.com/splitsh/lite/blob/v2.0.0/splitter/state.go#L365-L443
- [sl-state-copy]: same file, copyCommit and legacyMessage L461–523, https://github.com/splitsh/lite/blob/v2.0.0/splitter/state.go#L461-L523
- [sl-state-tree]: same file, L214–275, https://github.com/splitsh/lite/blob/v2.0.0/splitter/state.go#L214-L275
- [sl-utils]: `splitter/utils.go` v2.0.0, https://github.com/splitsh/lite/blob/v2.0.0/splitter/utils.go
- [sl-cache]: `splitter/cache.go` v2.0.0 (L25, and `key()` including the git version), https://github.com/splitsh/lite/blob/v2.0.0/splitter/cache.go
- [sl-config]: `splitter/config.go` v2.0.0, https://github.com/splitsh/lite/blob/v2.0.0/splitter/config.go
- [sl-tests]: `run-tests.sh` v2.0.0, https://github.com/splitsh/lite/blob/v2.0.0/run-tests.sh
- [sl-gomod]: `go.mod` v2.0.0, https://github.com/splitsh/lite/blob/v2.0.0/go.mod
- `CHANGELOG` v2.0.0, https://github.com/splitsh/lite/blob/v2.0.0/CHANGELOG
- [sl-releases]: https://github.com/splitsh/lite/releases (v2.0.0, 2023-10-26, no assets; v1.0.1, 2017-02-25, `lite_linux_amd64.tar.gz` and `lite_darwin_amd64.tar.gz`)
- [sl-master]: https://github.com/splitsh/lite/commit/36e0e74cfef34487bf15f0bbaff7260b9ce9c021
- [sl-26da]: https://github.com/splitsh/lite/commit/26da73e
- [sl-65]: https://github.com/splitsh/lite/issues/65
- [sl-88]: https://github.com/splitsh/lite/issues/88
- [sl-81]: https://github.com/splitsh/lite/issues/81
- [sl-82]: https://github.com/splitsh/lite/issues/82
- [sl-86]: https://github.com/splitsh/lite/issues/86
- [sl-87]: https://github.com/splitsh/lite/issues/87

**git-filter-repo (newren/git-filter-repo):**

- [f-doc]: `Documentation/git-filter-repo.txt` v2.47.0, https://github.com/newren/git-filter-repo/blob/v2.47.0/Documentation/git-filter-repo.txt
- [f-script]: `git-filter-repo` v2.47.0 L4345–4347, https://github.com/newren/git-filter-repo/blob/v2.47.0/git-filter-repo#L4345-L4347
- [f-script-state]: same file, L2230–2246, https://github.com/newren/git-filter-repo/blob/v2.47.0/git-filter-repo#L2230-L2246
- [f-readme]: `README.md` v2.47.0, https://github.com/newren/git-filter-repo/blob/v2.47.0/README.md
- [f-copying]: `COPYING` v2.47.0, https://github.com/newren/git-filter-repo/blob/v2.47.0/COPYING
- [f-sig]: https://github.com/newren/git-filter-repo/commit/308accc26ab9
- [f-sig2]: https://github.com/newren/git-filter-repo/commit/2a118d2ac218
- Release v2.47.0, 2024-12-04: https://github.com/newren/git-filter-repo/releases/tag/v2.47.0

**josh (josh-project/josh):**

- [j-filters]: `docs/src/reference/filters.md` r26.05.08, https://github.com/josh-project/josh/blob/r26.05.08/docs/src/reference/filters.md
- [j-faq]: `docs/src/faq.md` r26.05.08, https://github.com/josh-project/josh/blob/r26.05.08/docs/src/faq.md
- [j-intro]: `docs/src/intro.md` r26.05.08, https://github.com/josh-project/josh/blob/r26.05.08/docs/src/intro.md
- Releases (r26.08.28 is the latest): https://github.com/josh-project/josh/releases

**Copybara (google/copybara):**

- [c-readme]: `README.md` v20261116, https://github.com/google/copybara/blob/v20261116/README.md
- [c-ref]: `docs/reference.md` v20261116, https://github.com/google/copybara/blob/v20261116/docs/reference.md
- Releases: https://github.com/google/copybara/releases

**Actions and wrappers:**

- [a-entry]: danharrin/monorepo-split-github-action `entrypoint.php` v2.4.5, https://github.com/danharrin/monorepo-split-github-action/blob/v2.4.5/entrypoint.php
- [a-docker]: same repository, `Dockerfile` v2.4.5, https://github.com/danharrin/monorepo-split-github-action/blob/v2.4.5/Dockerfile (`action.yaml` and `README.md` at the same tag)
- [a-mb]: symplify/monorepo-builder `README.md` at 12.7.2 (`35e7593e`), https://github.com/symplify/monorepo-builder/blob/12.7.2/README.md
- [a-acrobat]: acrobat/subtree-splitter `src/index.ts` v1.1.5, https://github.com/acrobat/subtree-splitter/blob/v1.1.5/src/index.ts
- [a-subsplit]: dflydev/git-subsplit `README.md`, https://github.com/dflydev/git-subsplit/blob/2cde593a10acdb3fcafd2136ee7025fee9e925b2/README.md
- [a-subsplit-sh]: `git-subsplit.sh`, https://github.com/dflydev/git-subsplit/blob/2cde593a10acdb3fcafd2136ee7025fee9e925b2/git-subsplit.sh

**nixpkgs at `2786a2a72088ecf32aea5b3ec52099e6a7cff78f`:**

- [n-git]: `pkgs/by-name/gi/git/package.nix` (L69 version 2.56.0; L296 builds contrib/subtree; L350–352 installs it), https://github.com/NixOS/nixpkgs/blob/2786a2a72088ecf32aea5b3ec52099e6a7cff78f/pkgs/by-name/gi/git/package.nix
- [n-gfr]: `pkgs/development/python-modules/git-filter-repo/default.nix` (L13, 2.47.0), https://github.com/NixOS/nixpkgs/blob/2786a2a72088ecf32aea5b3ec52099e6a7cff78f/pkgs/development/python-modules/git-filter-repo/default.nix
- [n-allpkgs]: `pkgs/top-level/all-packages.nix` L1079, https://github.com/NixOS/nixpkgs/blob/2786a2a72088ecf32aea5b3ec52099e6a7cff78f/pkgs/top-level/all-packages.nix#L1079
- [n-josh]: `pkgs/by-name/jo/josh/package.nix` (L13, 26.05.08; L39 `--workspace`), https://github.com/NixOS/nixpkgs/blob/2786a2a72088ecf32aea5b3ec52099e6a7cff78f/pkgs/by-name/jo/josh/package.nix
- [n-copybara]: `pkgs/by-name/co/copybara/package.nix` (L17, 20261026; L23 release jar), https://github.com/NixOS/nixpkgs/blob/2786a2a72088ecf32aea5b3ec52099e6a7cff78f/pkgs/by-name/co/copybara/package.nix
- [n-libgit2]: `pkgs/by-name/li/libgit2/package.nix` (L26, 1.9.7), https://github.com/NixOS/nixpkgs/blob/2786a2a72088ecf32aea5b3ec52099e6a7cff78f/pkgs/by-name/li/libgit2/package.nix
- splitsh-lite: `grep -rIl -i splitsh` over the whole pinned source tree finds no file. Its attribute does not exist (`nix eval …#splitsh-lite` fails).

**Homebrew and GitHub:**

- [h-git]: homebrew-core `Formula/g/git.rb` @c18f1bc6 (L156–161, installs git-subtree), https://github.com/Homebrew/homebrew-core/blob/c18f1bc6ff897361481f9baa120983503eba2a02/Formula/g/git.rb; https://formulae.brew.sh/api/formula/git.json (2.56.1)
- [h-gfr]: homebrew-core `Formula/g/git-filter-repo.rb` @938508e4, https://github.com/Homebrew/homebrew-core/blob/938508e40cb2ec7d31b94791dbace35e4937e74e/Formula/g/git-filter-repo.rb (2.47.0). josh, splitsh-lite and copybara: https://formulae.brew.sh/api/formula/<name>.json returns 404.
- [gh-runner]: actions/runner-images `images/ubuntu/Ubuntu2404-Readme.md` @998568e1 (L10 image 20261116.376.1; L79 Git 2.56.1), https://github.com/actions/runner-images/blob/998568e1de3215b167b9a1a7806c2d598ade1eba/images/ubuntu/Ubuntu2404-Readme.md
- [gh-installgit]: `images/ubuntu/scripts/build/install-git.sh` (main, read 2026-11-22; `ppa:git-core/ppa`), https://github.com/actions/runner-images/blob/main/images/ubuntu/scripts/build/install-git.sh
- [gh-docs]: GitHub Docs, "Splitting a subfolder out into a new repository", https://docs.github.com/en/get-started/using-git/splitting-a-subfolder-out-into-a-new-repository
- [alpine-git]: Alpine v3.19 `git` package 2.43.7-r0, https://pkgs.alpinelinux.org/package/v3.19/main/x86_64/git

**Users:**

- [u-laravel]: laravel/framework `.github/workflows/split.yml` @1caec34a, https://github.com/laravel/framework/blob/1caec34a347d3bdd875b18d0e279aef9b80435c2/.github/workflows/split.yml; `bin/split.sh` at the same commit
- [u-symfony]: symfony/symfony `splitsh.json` @2894e4ee, https://github.com/symfony/symfony/blob/2894e4eeea9e328e5f38d5e5e52498438c9a12fc/splitsh.json
- [u-symfony-commit]: https://github.com/symfony/symfony/commit/291a54c60c81410e8fde63ca932c272f5d729a59
- [u-drupal]: Drupal subtree-splitter `README.md` @7f9dd9dd, https://gitlab.com/drupal-infrastructure/subtree-splitter/-/blob/7f9dd9dd149aede67918287b8e6970a93e3d5cdd/README.md
- [u-apip]: api-platform/core `.github/workflows/release.yml` @42f5b4b0, https://github.com/api-platform/core/blob/42f5b4b0291b85bb4babd4f64abd84abb261cf56/.github/workflows/release.yml
- [u-shopware]: shopware/shopware `.github/workflows/05-prepare-release.yml` @551447a2, https://github.com/shopware/shopware/blob/551447a2dfd27526b8a1efd9cea90a26c1581a17/.github/workflows/05-prepare-release.yml
- [u-shopware-split]: shopware/shopware `.github/bin/split.bash` @551447a2, https://github.com/shopware/shopware/blob/551447a2dfd27526b8a1efd9cea90a26c1581a17/.github/bin/split.bash
- [u-cake]: cakephp/cakephp `Makefile` @45992900, https://github.com/cakephp/cakephp/blob/459929001cfcca2c1035f16cf0ea61f242b50578/Makefile
- [u-neos]: neos/slicer `slicer.php` @e67375ce, https://github.com/neos/slicer/blob/e67375ce8435f51c025aa4130efc6a958249f865/slicer.php
- [u-miri]: rust-lang/miri `CONTRIBUTING.md` @f667c131, https://github.com/rust-lang/miri/blob/f667c131a9a3c88f1b0d64b1abe2f5ac256043de/CONTRIBUTING.md
- [u-joshsync]: rust-lang/josh-sync `src/josh.rs` @f96b538a, https://github.com/rust-lang/josh-sync/blob/f96b538a3a77aed0f8401f91e576b6bae2bf5509/src/josh.rs
- [u-fbshipit]: facebook/fbshipit (archived) `README.md` @dfa6631d, https://github.com/facebookarchive/fbshipit/blob/dfa6631d5baa3c87955351988fc7433e2cb0e811/README.md
- Entries marked "survey" in [Who uses what](#who-uses-what) cite the file and commit given there; they were not re-read for this note.

[gs-split]: https://github.com/git/git/blob/v2.56.0/contrib/subtree/git-subtree.sh#L925-L1021
[gs-copy]: https://github.com/git/git/blob/v2.56.0/contrib/subtree/git-subtree.sh#L695-L769
[gs-copycommit]: https://github.com/git/git/blob/v2.56.0/contrib/subtree/git-subtree.sh#L518-L543
[gs-cache]: https://github.com/git/git/blob/v2.56.0/contrib/subtree/git-subtree.sh#L282-L292
[gs-psc]: https://github.com/git/git/blob/v2.56.0/contrib/subtree/git-subtree.sh#L826-L835
[gs-doc]: https://github.com/git/git/blob/v2.56.0/contrib/subtree/git-subtree.adoc#L111-L113
[g-1f70]: https://github.com/git/git/commit/1f70684b517f4fcfeb7b998b0b7f3146ee8a8c75
[g-98ba]: https://github.com/git/git/commit/98ba49ccc247c3521659aa3d43c970e8978922c5
[g-83f9]: https://github.com/git/git/commit/83f9dad7d6fb5988b68f80b25bd87c68693195dd
[g-28a7]: https://github.com/git/git/commit/28a7e27cff717e5ef91f7445e6a418068608082d
[g-ab68]: https://github.com/git/git/commit/ab689ea7f91ab0858e85776f31102203d3ea7b83
[g-68f8]: https://github.com/git/git/commit/68f8ff81513fb3599ef3dfc3dd11da36d868e91b
[g-933c]: https://github.com/git/git/commit/933cfeb90b5d03b4096db6d60494a6eedea25d03
[g-a5b8]: https://github.com/git/git/commit/a5b8e28e4eb5762c9da68b56efdf45d6c885bd56
[g-fea5]: https://github.com/git/git/commit/fea50ce4118c
[g-committree]: https://github.com/git/git/blob/v2.56.0/builtin/commit-tree.c#L129
[g-fb]: https://github.com/git/git/blob/v2.56.0/Documentation/git-filter-branch.adoc#L19-L30
[g-contrib]: https://github.com/git/git/blob/v2.56.0/contrib/README
[g-copying]: https://github.com/git/git/blob/v2.56.0/COPYING
[sl-readme]: https://github.com/splitsh/lite/blob/v2.0.0/README.md
[sl-state]: https://github.com/splitsh/lite/blob/v2.0.0/splitter/state.go#L365-L443
[sl-state-copy]: https://github.com/splitsh/lite/blob/v2.0.0/splitter/state.go#L461-L523
[sl-state-tree]: https://github.com/splitsh/lite/blob/v2.0.0/splitter/state.go#L214-L275
[sl-utils]: https://github.com/splitsh/lite/blob/v2.0.0/splitter/utils.go
[sl-cache]: https://github.com/splitsh/lite/blob/v2.0.0/splitter/cache.go
[sl-config]: https://github.com/splitsh/lite/blob/v2.0.0/splitter/config.go
[sl-tests]: https://github.com/splitsh/lite/blob/v2.0.0/run-tests.sh
[sl-gomod]: https://github.com/splitsh/lite/blob/v2.0.0/go.mod
[sl-releases]: https://github.com/splitsh/lite/releases
[sl-master]: https://github.com/splitsh/lite/commit/36e0e74cfef34487bf15f0bbaff7260b9ce9c021
[sl-26da]: https://github.com/splitsh/lite/commit/26da73e
[sl-65]: https://github.com/splitsh/lite/issues/65
[sl-88]: https://github.com/splitsh/lite/issues/88
[sl-81]: https://github.com/splitsh/lite/issues/81
[sl-82]: https://github.com/splitsh/lite/issues/82
[sl-86]: https://github.com/splitsh/lite/issues/86
[sl-87]: https://github.com/splitsh/lite/issues/87
[f-doc]: https://github.com/newren/git-filter-repo/blob/v2.47.0/Documentation/git-filter-repo.txt
[f-script]: https://github.com/newren/git-filter-repo/blob/v2.47.0/git-filter-repo#L4345-L4347
[f-script-state]: https://github.com/newren/git-filter-repo/blob/v2.47.0/git-filter-repo#L2230-L2246
[f-readme]: https://github.com/newren/git-filter-repo/blob/v2.47.0/README.md
[f-copying]: https://github.com/newren/git-filter-repo/blob/v2.47.0/COPYING
[f-sig]: https://github.com/newren/git-filter-repo/commit/308accc26ab9
[f-sig2]: https://github.com/newren/git-filter-repo/commit/2a118d2ac218
[j-filters]: https://github.com/josh-project/josh/blob/r26.05.08/docs/src/reference/filters.md
[j-faq]: https://github.com/josh-project/josh/blob/r26.05.08/docs/src/faq.md
[j-intro]: https://github.com/josh-project/josh/blob/r26.05.08/docs/src/intro.md
[c-readme]: https://github.com/google/copybara/blob/v20261116/README.md
[c-ref]: https://github.com/google/copybara/blob/v20261116/docs/reference.md
[a-entry]: https://github.com/danharrin/monorepo-split-github-action/blob/v2.4.5/entrypoint.php
[a-docker]: https://github.com/danharrin/monorepo-split-github-action/blob/v2.4.5/Dockerfile
[a-mb]: https://github.com/symplify/monorepo-builder/blob/12.7.2/README.md
[a-acrobat]: https://github.com/acrobat/subtree-splitter/blob/v1.1.5/src/index.ts
[a-subsplit]: https://github.com/dflydev/git-subsplit/blob/2cde593a10acdb3fcafd2136ee7025fee9e925b2/README.md
[a-subsplit-sh]: https://github.com/dflydev/git-subsplit/blob/2cde593a10acdb3fcafd2136ee7025fee9e925b2/git-subsplit.sh
[n-git]: https://github.com/NixOS/nixpkgs/blob/2786a2a72088ecf32aea5b3ec52099e6a7cff78f/pkgs/by-name/gi/git/package.nix
[n-gfr]: https://github.com/NixOS/nixpkgs/blob/2786a2a72088ecf32aea5b3ec52099e6a7cff78f/pkgs/development/python-modules/git-filter-repo/default.nix
[n-allpkgs]: https://github.com/NixOS/nixpkgs/blob/2786a2a72088ecf32aea5b3ec52099e6a7cff78f/pkgs/top-level/all-packages.nix#L1079
[n-josh]: https://github.com/NixOS/nixpkgs/blob/2786a2a72088ecf32aea5b3ec52099e6a7cff78f/pkgs/by-name/jo/josh/package.nix
[n-copybara]: https://github.com/NixOS/nixpkgs/blob/2786a2a72088ecf32aea5b3ec52099e6a7cff78f/pkgs/by-name/co/copybara/package.nix
[n-libgit2]: https://github.com/NixOS/nixpkgs/blob/2786a2a72088ecf32aea5b3ec52099e6a7cff78f/pkgs/by-name/li/libgit2/package.nix
[h-git]: https://github.com/Homebrew/homebrew-core/blob/c18f1bc6ff897361481f9baa120983503eba2a02/Formula/g/git.rb
[h-gfr]: https://github.com/Homebrew/homebrew-core/blob/938508e40cb2ec7d31b94791dbace35e4937e74e/Formula/g/git-filter-repo.rb
[gh-runner]: https://github.com/actions/runner-images/blob/998568e1de3215b167b9a1a7806c2d598ade1eba/images/ubuntu/Ubuntu2404-Readme.md
[gh-installgit]: https://github.com/actions/runner-images/blob/main/images/ubuntu/scripts/build/install-git.sh
[gh-docs]: https://docs.github.com/en/get-started/using-git/splitting-a-subfolder-out-into-a-new-repository
[alpine-git]: https://pkgs.alpinelinux.org/package/v3.19/main/x86_64/git
[u-laravel]: https://github.com/laravel/framework/blob/1caec34a347d3bdd875b18d0e279aef9b80435c2/.github/workflows/split.yml
[u-symfony]: https://github.com/symfony/symfony/blob/2894e4eeea9e328e5f38d5e5e52498438c9a12fc/splitsh.json
[u-symfony-commit]: https://github.com/symfony/symfony/commit/291a54c60c81410e8fde63ca932c272f5d729a59
[u-drupal]: https://gitlab.com/drupal-infrastructure/subtree-splitter/-/blob/7f9dd9dd149aede67918287b8e6970a93e3d5cdd/README.md
[u-apip]: https://github.com/api-platform/core/blob/42f5b4b0291b85bb4babd4f64abd84abb261cf56/.github/workflows/release.yml
[u-shopware]: https://github.com/shopware/shopware/blob/551447a2dfd27526b8a1efd9cea90a26c1581a17/.github/workflows/05-prepare-release.yml
[u-shopware-split]: https://github.com/shopware/shopware/blob/551447a2dfd27526b8a1efd9cea90a26c1581a17/.github/bin/split.bash
[u-cake]: https://github.com/cakephp/cakephp/blob/459929001cfcca2c1035f16cf0ea61f242b50578/Makefile
[u-neos]: https://github.com/neos/slicer/blob/e67375ce8435f51c025aa4130efc6a958249f865/slicer.php
[u-miri]: https://github.com/rust-lang/miri/blob/f667c131a9a3c88f1b0d64b1abe2f5ac256043de/CONTRIBUTING.md
[u-joshsync]: https://github.com/rust-lang/josh-sync/blob/f96b538a3a77aed0f8401f91e576b6bae2bf5509/src/josh.rs
[u-fbshipit]: https://github.com/facebookarchive/fbshipit/blob/dfa6631d5baa3c87955351988fc7433e2cb0e811/README.md
