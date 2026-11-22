#!/usr/bin/env bash
# Build the prebuilt binaries of a release, the PIE archives (the extension flake's
# packages.pie, extension/flake.nix, as devenv.nix `outputs.pie`), and copy them into a
# directory, default dist/: one php_restate-<version>_php<minor>-<arch>-linux-<glibc|musl>-<nts|zts>.tgz
# per target with its .sha256 file, and SHA256SUMS. Prints the archives' paths on stdout.
#
# Usage: bin/build-pie.sh [<dir>]
#
# Nix cross-compiles every target on any platform (Linux or macOS, x86_64 or arm64) and
# checks each module's shared libraries and glibc floor; loading one needs a Linux machine of
# its CPU (.github/workflows/extension.yaml). See docs/decisions/0030-prebuilt-extension-binaries.md.
# The flake sees git-tracked files only: `git add` new files under extension/ first.
set -euo pipefail

dir=${1:-dist}

if ! command -v devenv >/dev/null 2>&1; then
  echo "build-pie: devenv is required (https://devenv.sh)." >&2
  exit 2
fi

cd "$(dirname "${BASH_SOURCE[0]}")/.."
mkdir -p "$dir"

# devenv build prints {"outputs.pie": "<store path>"}.
result=$(devenv build outputs.pie | sed -n 's/^ *"outputs\.pie": "\(.*\)"$/\1/p')
if [ -z "$result" ] || [ ! -f "$result/SHA256SUMS" ]; then
  echo "build-pie: devenv build outputs.pie produced no archives" >&2
  exit 1
fi

for file in "$result"/*; do
  install -m 644 "$file" "$dir/"
done
echo "build-pie: copied $(grep -c . "$result/SHA256SUMS") archives from $result to $dir" >&2
while read -r _ asset; do
  echo "$dir/$asset"
done <"$result/SHA256SUMS"
