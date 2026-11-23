#!/usr/bin/env bash
# Split the directories this repository publishes into their read-only repositories, each with its
# own history (`git subtree split`), as a release does (.github/workflows/release.yaml,
# docs/decisions/0031-release.md). The release runs it, so does every push to main
# (.github/workflows/split.yaml, without --version), and so can you: as a dry run against the real
# repositories, or for real against local bare repositories, to rehearse a release.
#
# Usage:
#   bin/split.sh list
#       The published directories and their repositories, "<directory> <repository>" per line, the
#       extension first.
#   bin/split.sh split [<directory>...]
#       Split HEAD and print "<directory> <split commit>" per directory (default: all of them).
#       Nothing leaves this repository.
#   bin/split.sh publish [--version <X.Y.Z>] --remote <url> [--dry-run] [<directory>...]
#       Split HEAD, push each split to its repository's main (fast-forward only, never forced) and,
#       with --version, tag it: X.Y.Z for the extension (PIE puts the tag in the asset names it
#       looks for), vX.Y.Z for the packages. A main that already contains the split (the split of
#       a later commit, pushed by a later push to main) is left alone; the tag still goes on the
#       split. A tag already on the split commit is left alone; one on another commit, or a main
#       the split neither extends nor is contained in, stops the run before anything of that
#       directory is pushed. <url> names every repository, with {repository} for its name:
#       https://github.com/restate-php/{repository}.git, or file:///tmp/rehearsal/{repository}.git.
#       With --dry-run it reads the repositories (git ls-remote, git fetch), says what it would
#       push, and pushes nothing; it reports every directory, and fails at the end if any would
#       stop a run.
#
# The split commits are the same on every run (git documents it, and treats changing them as
# breaking), so a run after a successful one pushes nothing. Splitting re-walks the history of
# HEAD: a CI checkout needs all of it (fetch-depth: 0). Runs in the repository of the current
# directory. docs/research/subtree-split-tooling.md compares the tools.
set -euo pipefail

# "<directory> <repository>", the extension first: the release publishes it before the packages.
SPLITS=(
  "extension ext-restate"
  "packages/common common"
  "packages/ingress ingress"
  "packages/sdk sdk"
  "packages/testing testing"
)

usage() {
  sed -n '2,31p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
  exit 2
}

fail() {
  # In GitHub Actions, as an error annotation on the run.
  if [ "${GITHUB_ACTIONS:-}" = true ]; then
    echo "::error::$*" >&2
  else
    echo "split: $*" >&2
  fi
  exit 1
}

# repository_of <directory>: the repository a published directory splits into.
repository_of() {
  local split
  for split in "${SPLITS[@]}"; do
    if [ "${split%% *}" = "$1" ]; then
      echo "${split#* }"
      return
    fi
  done
  fail "$1 is not a published directory (bin/split.sh list)"
}

# tag_of <directory> <version>: X.Y.Z for the extension, vX.Y.Z for the packages.
tag_of() {
  if [ "$1" = extension ]; then
    echo "$2"
  else
    echo "v$2"
  fi
}

# Settings that would change every split commit: an encoding header on each of them.
check_git_config() {
  local encoding
  encoding=$(git config --get i18n.commitEncoding || true)
  case "$encoding" in
    '' | [Uu][Tt][Ff]-8 | [Uu][Tt][Ff]8) ;;
    *) fail "i18n.commitEncoding is $encoding: the split commits would carry an encoding header and differ from the published ones" ;;
  esac
}

# split_commit <directory>: the split of HEAD. Never with --annotate, --rejoin or signing, which
# would change the commits.
split_commit() {
  git cat-file -e "HEAD:$1" 2> /dev/null || fail "$1 is not in HEAD"
  git subtree split --quiet --prefix="$1" HEAD
}

# directories_or_all <directory>...: the arguments, else every published directory.
directories_or_all() {
  local split
  if [ $# -gt 0 ]; then
    printf '%s\n' "$@"
  else
    for split in "${SPLITS[@]}"; do
      echo "${split%% *}"
    done
  fi
}

list() {
  [ $# -eq 0 ] || usage
  printf '%s\n' "${SPLITS[@]}"
}

split() {
  local directory
  check_git_config
  directories_or_all "$@" | while read -r directory; do
    repository_of "$directory" > /dev/null
    echo "$directory $(split_commit "$directory")"
  done
}

# has_commit <commit>: the commit is in this repository.
has_commit() {
  git cat-file -e "$1^{commit}" 2> /dev/null
}

# fetch_main <url> <repository>: the repository's main and its history, into FETCH_HEAD; no ref of
# this repository changes. Its commits are splits of this repository's, so this fetches little.
fetch_main() {
  git fetch --quiet --no-tags "$1" refs/heads/main || fail "cannot read $2"
}

# publish_one <directory> <version or empty> <remote> <dry run: 1 or empty>: returns 1 for a
# problem that a dry run reports and goes on from.
publish_one() {
  local directory=$1 version=$2 remote=$3 dry_run=$4 repository tag='' url commit main existing='' main_state tag_state=''
  repository=$(repository_of "$directory")
  url=${remote//\{repository\}/$repository}
  commit=$(split_commit "$directory")
  # The URL may carry a token: only the repository's name is printed.
  main=$(git ls-remote "$url" refs/heads/main | cut -f1) || fail "cannot read $repository"
  if [ -n "$version" ]; then
    tag=$(tag_of "$directory" "$version")
    existing=$(git ls-remote "$url" "refs/tags/$tag" | cut -f1) || fail "cannot read $repository"
  fi

  if [ "$main" = "$commit" ]; then
    main_state='up to date'
  elif [ -z "$main" ]; then
    main_state='created'
  else
    # A main pushed from a later commit of this repository is a split this run has not made.
    has_commit "$main" || fetch_main "$url" "$repository"
    # The split of a later commit extends the earlier one, whose commits it recreates: a main that
    # neither contains the split nor is contained in it has commits of its own.
    if has_commit "$main" && git merge-base --is-ancestor "$main" "$commit"; then
      main_state="fast-forwarded from ${main:0:12}"
    # A main that contains the split: the split of a later commit when a release is of a commit
    # that later pushes to main have passed. A run without --version splits the tip of main
    # (.github/workflows/split.yaml), so its split is the latest one, and anything on top of it
    # was pushed by hand.
    elif has_commit "$main" && git merge-base --is-ancestor "$commit" "$main"; then
      if [ -n "$version" ]; then
        main_state="left alone (ahead at ${main:0:12})"
      else
        main_state="AHEAD at ${main:0:12}"
      fi
    else
      main_state="NOT a fast-forward from ${main:0:12}"
    fi
  fi

  if [ -n "$version" ]; then
    if [ -z "$existing" ]; then
      tag_state='created'
    elif [ "$existing" = "$commit" ]; then
      tag_state='already there'
    else
      tag_state="ALREADY on ${existing:0:12}"
    fi
  fi

  local verb='' problem=''
  [ -n "$dry_run" ] && verb='would be '
  echo "$directory -> $repository: split $commit; main ${verb}${main_state}${tag:+; tag $tag ${verb}${tag_state}}"
  case "$main_state" in
    NOT*) problem="$repository's main ($main) has commits the split $commit does not: it was changed by hand, and pushing would not be a fast-forward" ;;
    AHEAD*) problem="$repository's main ($main) has commits on top of the split $commit: they were pushed by hand, or this is not the tip of main (split the tip instead)" ;;
  esac
  case "$tag_state" in ALREADY*) problem="${problem:+$problem; }$repository has $tag on $existing, not on the split $commit" ;; esac
  if [ -n "$problem" ]; then
    if [ -n "$dry_run" ]; then
      echo "  problem: $problem" >&2
      return 1
    fi
    fail "$problem"
  fi
  [ -z "$dry_run" ] || return 0

  case "$main_state" in
    'up to date' | 'left alone'*) ;;
    *)
      if ! git push --quiet "$url" "$commit:refs/heads/main"; then
        # Another run (a release, a push to this repository's main) may have pushed the split of a
        # later commit since main was read: then main contains this split, as it should.
        fetch_main "$url" "$repository"
        git merge-base --is-ancestor "$commit" FETCH_HEAD || fail "cannot push $repository's main"
        echo "  $repository's main moved on to $(git rev-parse --short=12 FETCH_HEAD), which contains the split: left alone"
      fi
      ;;
  esac
  if [ "$tag_state" = created ]; then
    git push --quiet "$url" "$commit:refs/tags/$tag"
  fi
}

publish() {
  local version='' remote='' dry_run='' directory failed=0
  while [ $# -gt 0 ]; do
    case "$1" in
      --version)
        [ $# -ge 2 ] || usage
        version=$2
        shift 2
        ;;
      --version=*)
        version=${1#*=}
        shift
        ;;
      --remote)
        [ $# -ge 2 ] || usage
        remote=$2
        shift 2
        ;;
      --remote=*)
        remote=${1#*=}
        shift
        ;;
      --dry-run)
        dry_run=1
        shift
        ;;
      --)
        shift
        break
        ;;
      -*)
        echo "split: unknown option $1" >&2
        usage
        ;;
      *) break ;;
    esac
  done
  [ -n "$remote" ] || usage
  if [ -n "$version" ]; then
    [[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] || fail "--version must be X.Y.Z (the packages' tags add the v), not $version"
  fi
  case "$remote" in
    *'{repository}'*) ;;
    *) fail "--remote must name the repository with {repository}, for example https://github.com/restate-php/{repository}.git" ;;
  esac
  check_git_config
  # A repository that does not exist, or a URL without credentials, fails instead of waiting at a
  # password prompt: credentials come from the URL or a credential helper.
  export GIT_TERMINAL_PROMPT=0

  # Not in a pipeline: a failure must stop this shell.
  for directory in $(directories_or_all "$@"); do
    publish_one "$directory" "$version" "$remote" "$dry_run" || failed=1
  done
  [ "$failed" -eq 0 ] || fail "a release would stop at the problems above"
}

cd "$(git rev-parse --show-toplevel)"

case "${1:-}" in
  list | split | publish)
    command=$1
    shift
    "$command" "$@"
    ;;
  -h | --help) usage ;;
  *) usage ;;
esac
