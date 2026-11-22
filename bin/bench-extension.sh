#!/usr/bin/env bash
# Benchmarks of the extension's Rust core (extension/core/benches/, behind the
# crate's `bench` feature). See TESTING.md, Performance.
#
# Usage: bin/bench-extension.sh [instructions|criterion|compare [<base>]] [-- <gungraun args>...]
#
#   instructions (default)  instruction counts under callgrind (gungraun, bench
#                           `vm_instructions`), then the linearity check below. Installs
#                           gungraun-runner at the gungraun version Cargo.lock pins into
#                           extension/target/gungraun-runner/ first, if it is not there.
#   criterion               wall-clock times (criterion, bench `vm`); report only, HTML in
#                           extension/target/criterion/.
#   compare [<base>]        instruction counts of <base> (default: the merge-base with
#                           origin/main, else main), built in a temporary git worktree,
#                           saved as gungraun baseline `base`; then this checkout's counts
#                           against it. Fails (exit 3) when a benchmark's instructions grow
#                           by more than BENCH_EXTENSION_IR_LIMIT (default 2%). A base without
#                           the benchmarks is skipped, with a note.
#
# Every mode first lints the benches (clippy -D warnings with the feature on), because
# `composer check:extension` never compiles them. Arguments after `--` go to gungraun's
# runner (e.g. `-- '*::replay_runs::*'` to filter by module path, `--save-baseline=mine`).
#
# Linearity check: the instructions per replayed run (replay_runs runs_N minus runs_0,
# divided by N) at 1000 runs must stay within BENCH_EXTENSION_LINEAR_LIMIT (default 1.25)
# times those at 100 runs: a replay that turns superlinear fails here even when the
# merge-base was already slow.
#
# Needs valgrind (the dev shell has it) and the dev shell's Rust and PHP.
set -euo pipefail

usage() {
  sed -n '2,29p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
  exit 2
}

mode=instructions
base=
case "${1:-}" in
  instructions|criterion) mode=$1; shift ;;
  compare)
    mode=compare; shift
    if [ $# -gt 0 ] && [ "$1" != -- ]; then base=$1; shift; fi ;;
  -h|--help) usage ;;
  ''|--) ;;
  *) echo "bench-extension: unknown mode: $1" >&2; usage ;;
esac
[ "${1:-}" = -- ] && shift
runner_args=("$@")

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
EXTENSION_DIR=$ROOT/extension
cd "$EXTENSION_DIR"

cargo clippy -p restate-php-core --features bench --benches --locked -- -D warnings

if [ "$mode" = criterion ]; then
  exec cargo bench -p restate-php-core --features bench --locked --bench vm -- "${runner_args[@]}"
fi

# The runner binary must be exactly the library's version.
version=$(sed -n '/^name = "gungraun"$/{n;s/^version = "\(.*\)"$/\1/p;}' Cargo.lock)
if [ -z "$version" ]; then
  echo "bench-extension: no gungraun in extension/Cargo.lock" >&2
  exit 1
fi
runner_root=$EXTENSION_DIR/target/gungraun-runner
export GUNGRAUN_RUNNER=$runner_root/bin/gungraun-runner
if ! "$GUNGRAUN_RUNNER" --version 2>/dev/null | grep -qF "$version"; then
  cargo install --locked --force gungraun-runner --version "$version" --root "$runner_root"
fi
# Both sides of a comparison write their results, and baselines, here.
export GUNGRAUN_HOME=$EXTENSION_DIR/target/gungraun

# Runs vm_instructions in the extension/ directory $1 with the given runner arguments.
instructions() {
  local dir=$1
  shift
  (cd "$dir" && cargo bench -p restate-php-core --features bench --locked --bench vm_instructions -- --save-summary=json "$@")
}

if [ "$mode" = compare ]; then
  if [ -z "$base" ]; then
    base=$(git -C "$ROOT" merge-base HEAD origin/main 2>/dev/null || git -C "$ROOT" merge-base HEAD main)
  fi
  worktree=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/bench-extension-base.XXXXXX")
  cleanup() { git -C "$ROOT" worktree remove --force "$worktree" >/dev/null 2>&1 || rm -rf "$worktree"; }
  trap cleanup EXIT
  git -C "$ROOT" worktree add --detach "$worktree" "$base" >&2
  echo "bench-extension: base $(git -C "$worktree" rev-parse --short HEAD) in $worktree" >&2

  if grep -q '^name = "vm_instructions"$' "$worktree/extension/core/Cargo.toml" 2>/dev/null; then
    # Its own target directory, kept between runs (CI caches it): the base has its own
    # Cargo.lock and must not evict this checkout's build.
    CARGO_TARGET_DIR=$EXTENSION_DIR/target/bench-base instructions "$worktree/extension" --save-baseline=base "${runner_args[@]}"
    instructions "$EXTENSION_DIR" --baseline=base --callgrind-limits="ir=${BENCH_EXTENSION_IR_LIMIT:-2%}" "${runner_args[@]}"
  else
    echo "bench-extension: the base has no benchmarks; measuring this checkout only" >&2
    instructions "$EXTENSION_DIR" "${runner_args[@]}"
  fi
else
  instructions "$EXTENSION_DIR" "${runner_args[@]}"
fi

# The linearity check, from the summaries this run wrote (skipped when a filter left out
# the replay benchmarks). PHP code, so its $ are PHP's.
# shellcheck disable=SC2016
php -r '
  $dir = $argv[1];
  $limit = (float) $argv[2];
  $ir = [];
  foreach ([0, 100, 1000] as $runs) {
    $file = "$dir/restate-php-core/vm_instructions/vm/replay_runs.runs_$runs/summary.json";
    if (!is_file($file)) {
      fwrite(STDERR, "bench-extension: no replay_runs results, linearity not checked\n");
      exit(0);
    }
    $summary = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $ir[$runs] = $summary["profiles"][0]["data"]["parts"][0]["metrics"]["Ir"]["values"]["new"];
  }
  $at100 = ($ir[100] - $ir[0]) / 100;
  $at1000 = ($ir[1000] - $ir[0]) / 1000;
  $ratio = $at1000 / $at100;
  printf("bench-extension: instructions per replayed run: %.0f at 100 runs, %.0f at 1000 runs (ratio %.3f, limit %.2f)\n", $at100, $at1000, $ratio, $limit);
  if ($ratio > $limit) {
    fwrite(STDERR, "bench-extension: replay cost grows faster than linearly\n");
    exit(1);
  }
' "$GUNGRAUN_HOME" "${BENCH_EXTENSION_LINEAR_LIMIT:-1.25}"
