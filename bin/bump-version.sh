#!/usr/bin/env bash
# Set the release version in the tree, as the release does before it builds
# (.github/workflows/release.yaml, docs/decisions/0031-release.md): the version in
# [workspace.package] of extension/Cargo.toml, which every crate inherits; extension/Cargo.lock
# (cargo update --workspace, which reads the crates.io index), since the archives are built with
# --locked; and restate/sdk's ext-restate constraint, ~X.Y.0 of the version. Changes nothing that
# already holds the version, so a second run changes nothing.
#
# Usage:
#   bin/bump-version.sh <X.Y.Z>
set -euo pipefail

usage() {
  sed -n '2,10p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
  exit 2
}

fail() {
  # In GitHub Actions, as an error annotation on the run.
  if [ "${GITHUB_ACTIONS:-}" = true ]; then
    echo "::error::$*" >&2
  else
    echo "bump-version: $*" >&2
  fi
  exit 1
}

[ $# -eq 1 ] || usage
version=$1
[[ $version =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]] || fail "the version must be X.Y.Z, not $version"
constraint="~${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.0"

cd "$(git rev-parse --show-toplevel)"

manifest=extension/Cargo.toml
composer=packages/sdk/composer.json

# workspace_version: the version in [workspace.package] of the manifest.
workspace_version() {
  sed -n '/^\[workspace\.package\]$/,/^\[/s/^version = "\(.*\)"$/\1/p' "$manifest"
}

# replace <file> <awk program>: the file through the program, in place.
replace() {
  local tmp
  tmp=$(mktemp)
  awk -v version="$version" -v constraint="$constraint" "$2" "$1" > "$tmp"
  cat "$tmp" > "$1"
  rm -f "$tmp"
}

[ -n "$(workspace_version)" ] || fail "$manifest has no version in [workspace.package]"

# shellcheck disable=SC2016 # awk programs: their $0 is awk's
replace "$manifest" '
  /^\[/ { section = $0 }
  section == "[workspace.package]" && /^version = "/ { $0 = "version = \"" version "\"" }
  { print }
'
(cd extension && cargo update --workspace --quiet)

# shellcheck disable=SC2016
replace "$composer" '
  /^    "ext-restate": "/ { sub(/"ext-restate": "[^"]*"/, "\"ext-restate\": \"" constraint "\"") }
  { print }
'

[ "$(workspace_version)" = "$version" ] || fail "$manifest does not hold $version"
grep -q "^    \"ext-restate\": \"$constraint\",\{0,1\}$" "$composer" || fail "$composer does not require ext-restate $constraint"
git diff --stat -- "$manifest" extension/Cargo.lock "$composer"
