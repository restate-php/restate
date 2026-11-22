#!/usr/bin/env bash
# Compare the PHP benchmarks (tests/Benchmark, phpbench) of this checkout ("head") against a
# base revision, on this machine, in one go: TESTING.md, Performance.
#
# Usage: bin/bench-compare.sh [--base <ref>] [--out <dir>] [--filter <regex>] [--no-callgrind]
#                             [--callgrind-revs <n>] [-- <compare.php options>...]
#
#   --base <ref>          the revision to compare against; default: the merge base of HEAD and
#                         origin/main (else main). `--base HEAD` is an A/A run: the same code on
#                         both sides, which shows how far the numbers move by noise alone.
#   --out <dir>           where the runs, the report (report.md) and report.json go;
#                         default var/bench/compare
#   --filter <regex>      only these benchmarks (phpbench --filter)
#   --no-callgrind        skip the instruction-count backstop (needs valgrind otherwise)
#   --callgrind-revs <n>  revolutions per callgrind subject (default 20)
#   after --               options for tests/Benchmark/compare.php, e.g. --threshold=15
#
# The base is checked out in a temporary git worktree with its own `composer install`. It runs
# its own benchmark files, so a benchmark that head changes or adds is simply not compared (see
# BenchComparison). The extension is the release build: when extension/ is the same in both, the
# base reuses head's module; otherwise the base builds its own. The runs alternate base, head,
# head, base, so drift in the machine's speed during the job shows up in opposite directions in
# the two pairs. Exits 1 when a benchmark regressed in both pairs (or an instruction count grew
# beyond the threshold), 0 otherwise. In GitHub Actions the report goes to the job summary.
set -euo pipefail

usage() {
  sed -n '2,27p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
  exit 2
}

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
base_ref=
out=$ROOT/var/bench/compare
filter=
callgrind=1
callgrind_revs=20
compare_args=()
while [ $# -gt 0 ]; do
  case "$1" in
    --base) [ $# -ge 2 ] || usage; base_ref=$2; shift 2 ;;
    --base=*) base_ref=${1#*=}; shift ;;
    --out) [ $# -ge 2 ] || usage; out=$2; shift 2 ;;
    --out=*) out=${1#*=}; shift ;;
    --filter) [ $# -ge 2 ] || usage; filter=$2; shift 2 ;;
    --filter=*) filter=${1#*=}; shift ;;
    --no-callgrind) callgrind=0; shift ;;
    --callgrind-revs) [ $# -ge 2 ] || usage; callgrind_revs=$2; shift 2 ;;
    --callgrind-revs=*) callgrind_revs=${1#*=}; shift ;;
    --) shift; compare_args=("$@"); break ;;
    -h|--help) usage ;;
    *) echo "bench-compare: unknown argument: $1" >&2; usage ;;
  esac
done

cd "$ROOT"
if [ -z "$base_ref" ]; then
  upstream=origin/main
  git rev-parse --verify --quiet "$upstream" >/dev/null || upstream=main
  base_ref=$(git merge-base "$upstream" HEAD)
fi
base_sha=$(git rev-parse --verify "$base_ref^{commit}")
echo "bench-compare: base $base_ref ($base_sha), head $(git rev-parse --short HEAD)$(git diff --quiet HEAD -- || echo ' + local changes')" >&2

mkdir -p "$out"
out=$(cd "$out" && pwd)
base_tree=$out/base-tree
if [ -e "$base_tree" ]; then
  git worktree remove --force "$base_tree" 2>/dev/null || true
fi
git worktree add --detach --quiet "$base_tree" "$base_sha"
trap 'git -C "$ROOT" worktree remove --force "$base_tree" 2>/dev/null || true' EXIT

head_module=$(bash bin/build-extension.sh --release)

if [ ! -f "$base_tree/phpbench.json" ]; then
  echo "bench-compare: the base has no benchmarks (no phpbench.json); running head once, nothing to compare" >&2
  vendor/bin/phpbench run --report=aggregate ${filter:+--filter="$filter"}
  exit 0
fi

(cd "$base_tree" && composer install --no-interaction --no-progress --quiet)
mkdir -p "$base_tree/extension/target/release"
if git diff --quiet "$base_sha" -- extension/; then
  echo "bench-compare: extension/ unchanged, the base reuses head's module" >&2
  cp "$head_module" "$base_tree/extension/target/release/restate.so"
else
  echo "bench-compare: extension/ differs, building the base's release module" >&2
  (cd "$base_tree" && bash bin/build-extension.sh --release >/dev/null)
fi

run_bench() { # <tree> <dump file>
  (cd "$1" && vendor/bin/phpbench run --progress=none --dump-file="$2" ${filter:+--filter="$filter"} >/dev/null)
}

for run in base-1 head-1 head-2 base-2; do
  echo "bench-compare: phpbench $run" >&2
  case "$run" in
    base-*) run_bench "$base_tree" "$out/$run.xml" ;;
    head-*) run_bench "$ROOT" "$out/$run.xml" ;;
  esac
done

callgrind_args=()
if [ "$callgrind" = 1 ]; then
  if ! command -v valgrind >/dev/null 2>&1; then
    echo "bench-compare: valgrind not found, skipping the instruction counts (--no-callgrind to silence)" >&2
  else
    # <tree> <tsv>: instructions per revolution of each subject the tree's callgrind.php has.
    run_callgrind() {
      local tree=$1 tsv=$2 subject total
      : >"$tsv"
      [ -f "$tree/tests/Benchmark/callgrind.php" ] || return 0
      for subject in $(cd "$tree" && php tests/Benchmark/callgrind.php --list); do
        # The dev shell's php is a wrapper that execs the real binary: follow it.
        (cd "$tree" && valgrind --tool=callgrind --trace-children=yes --toggle-collect=zif_array_map \
          --callgrind-out-file="$out/callgrind.out" \
          php -d extension="$tree/extension/target/release/restate.so" -d opcache.enable_cli=0 \
          tests/Benchmark/callgrind.php "$subject" "$callgrind_revs" 2>/dev/null)
        total=$(sed -n 's/^totals: \([0-9]*\).*/\1/p' "$out/callgrind.out")
        printf '%s\t%s\n' "$subject" "$((total / callgrind_revs))" >>"$tsv"
      done
      rm -f "$out/callgrind.out"
    }
    echo "bench-compare: callgrind base" >&2
    run_callgrind "$base_tree" "$out/callgrind-base.tsv"
    echo "bench-compare: callgrind head" >&2
    run_callgrind "$ROOT" "$out/callgrind-head.tsv"
    callgrind_args=(--callgrind-base="$out/callgrind-base.tsv" --callgrind-head="$out/callgrind-head.tsv")
  fi
fi

status=0
php tests/Benchmark/compare.php "$out/base-1.xml" "$out/head-1.xml" "$out/head-2.xml" "$out/base-2.xml" \
  "${callgrind_args[@]}" --json="$out/report.json" ${GITHUB_STEP_SUMMARY:+--summary="$GITHUB_STEP_SUMMARY"} \
  "${compare_args[@]}" >"$out/report.md" || status=$?
cat "$out/report.md"
exit "$status"
