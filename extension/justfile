set lazy
set default-list
set ignore-comments

# Build the Restate PHP extension (module `restate`) and print the absolute path of its restate.so
[arg('php_config', long='php-config', help="php-config of the PHP to build for, into target/php<minor>-<nts|zts>/")]
[arg('profile', long='release', value='release', help="release build (the workspace's [profile.release])")]
[script('bash')]
build php_config='' profile='dev':
    set -euo pipefail
    if [ -n {{ quote(php_config) }} ]; then
        # ext-php-rs reads the include paths from PHP_CONFIG and the thread safety and Zend API
        # version from `$PHP -i`: point both at the same PHP. ext-php-rs takes PHP_CONFIG only as
        # a path, so a name on PATH becomes its path (after the shell has run it once, so a
        # missing one fails with the shell's error). Each PHP gets a target directory of its own.
        PHP=$({{ quote(php_config) }} --php-binary)
        PHP_CONFIG=$(command -v {{ quote(php_config) }})
        CARGO_TARGET_DIR=target/$("$PHP" -n -r 'echo "php", PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION, "-", PHP_ZTS ? "zts" : "nts";')
        export PHP PHP_CONFIG CARGO_TARGET_DIR

        echo "build: building for $PHP_CONFIG into $PWD/$CARGO_TARGET_DIR" >&2
    fi

    built=$(cargo build -p restate --profile {{ profile }} --message-format=json-render-diagnostics | cargo artifacts list)
    module=$(dirname "$built")/restate.so

    # Rewriting the file in place would crash a PHP process that has it loaded. A rename leaves that
    # process the old file and new ones get the new module.
    cp -f "$built" "$module.tmp.$$"
    mv -f "$module.tmp.$$" "$module"

    echo "$module"

# Cross-compile the PIE archives of a release (the flake's packages.pie) and copy them into <dir> (relative to where just runs; default: the repository's dist/)
[no-cd]
[script('bash')]
pie dir='':
    set -euo pipefail
    dir={{ quote(if dir == '' { source_directory() / ".." / "dist" } else { dir }) }}
    # The flake sees git-tracked files only: `git add` new files under extension/ first.
    result=$(nix build {{ quote(source_directory()) }}#pie --no-link --print-out-paths)

    mkdir -p "$dir"
    install -m 644 "$result"/* "$dir"/

    while read -r _ asset; do
        echo "$dir/$asset"
    done <"$result/SHA256SUMS"

[doc("cargo fmt --check, clippy -D warnings and cargo test for every crate in the workspace (needs the dev shell for the extension build)")]
check:
    cargo fmt --all --check
    cargo clippy --workspace --all-targets -- -D warnings
    cargo test --workspace

# The Rust core's benchmarks (core/benches/, behind the crate's `bench` feature; TESTING.md,
# Performance). Each one first lints the benches (clippy -D warnings with the feature on), since
# `just extension check` never compiles them. Their arguments go to gungraun's or criterion's
# runner, e.g. '*::replay_runs::*' to filter by module path, or --save-baseline=mine.
#
# The gungraun runner, at exactly the version of the library that Cargo.lock pins (installed by
# the benchmarks if it is not), and where both sides of a comparison write their results and
# baselines.

export GUNGRAUN_RUNNER := source_directory() / "target/gungraun-runner/bin/gungraun-runner"
export GUNGRAUN_HOME := source_directory() / "target/gungraun"

# Instruction counts of the Rust core under callgrind (gungraun), then the replay linearity check
[positional-arguments]
bench *runner: bench-setup && bench-linearity
    cargo bench -p restate-php-core --features bench --locked --bench vm_instructions -- --save-summary=json "$@"

# Wall-clock times of the Rust core (criterion), report only; HTML in target/criterion/
[positional-arguments]
bench-criterion *runner: bench-lint
    cargo bench -p restate-php-core --features bench --locked --bench vm -- "$@"

# The base is built in a temporary git worktree, into target/bench-base/ (its own Cargo.lock
# must not evict this checkout's build; CI caches it), and saved as gungraun baseline `base`. A
# base without the benchmarks is skipped, with a note.
[doc("Instruction counts of <base> (default: the merge base with origin/main) and of this checkout against them, failing 2% above them; then the replay linearity check")]
[positional-arguments]
[script('bash')]
bench-compare base='' *runner: bench-setup && bench-linearity
    set -euo pipefail
    shift
    base={{ quote(base) }}
    if [ -z "$base" ]; then base=$(git merge-base HEAD origin/main 2>/dev/null || git merge-base HEAD main); fi
    worktree=$(mktemp -d -t bench-extension-base.XXXXXX)
    trap 'git worktree remove --force "$worktree" >/dev/null 2>&1 || rm -rf "$worktree"' EXIT
    git worktree add --detach "$worktree" "$base" >&2
    echo "bench-compare: base $(git -C "$worktree" rev-parse --short HEAD) in $worktree" >&2

    # vm_instructions in the extension/ directory $1, with the runner arguments after it.
    instructions() {
        local dir=$1
        shift
        (cd "$dir" && cargo bench -p restate-php-core --features bench --locked --bench vm_instructions -- --save-summary=json "$@")
    }
    if grep -q '^name = "vm_instructions"$' "$worktree/extension/core/Cargo.toml" 2>/dev/null; then
        CARGO_TARGET_DIR={{ quote(source_directory() / "target/bench-base") }} instructions "$worktree/extension" --save-baseline=base "$@"
        instructions . --baseline=base --callgrind-limits=ir=2% "$@"
    else
        echo "bench-compare: the base has no benchmarks; measuring this checkout only" >&2
        instructions . "$@"
    fi

[private]
bench-lint:
    cargo clippy -p restate-php-core --features bench --benches --locked -- -D warnings

# The lint, and the gungraun runner at the library's version
[private]
[script('bash')]
bench-setup: bench-lint
    set -euo pipefail
    version=$(sed -n '/^name = "gungraun"$/{n;s/^version = "\(.*\)"$/\1/p;}' Cargo.lock)
    [ -n "$version" ] || { echo "bench: no gungraun in extension/Cargo.lock" >&2 && exit 1; }
    if ! "$GUNGRAUN_RUNNER" --version 2>/dev/null | grep -qF "$version"; then
        cargo install --locked --force gungraun-runner --version "$version" --root {{ quote(source_directory() / "target/gungraun-runner") }}
    fi

# The instructions per replayed run (replay_runs runs_N minus runs_0, divided by N) at 1000 runs must stay within 1.25 times those at 100 runs: a replay that turns superlinear fails even when the base was already slow. Skipped when a filter left out the replay benchmarks.
[private]
[script('php')]
bench-linearity:
    <?php
    $limit = 1.25;
    $ir = [];
    foreach ([0, 100, 1000] as $runs) {
        $file = getenv('GUNGRAUN_HOME') . "/restate-php-core/vm_instructions/vm/replay_runs.runs_{$runs}/summary.json";
        if (!is_file($file)) {
            fwrite(STDERR, "bench: no replay_runs results, linearity not checked\n");
            exit(0);
        }
        $summary = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $ir[$runs] = $summary['profiles'][0]['data']['parts'][0]['metrics']['Ir']['values']['new'];
    }
    $at100 = ($ir[100] - $ir[0]) / 100;
    $at1000 = ($ir[1000] - $ir[0]) / 1000;
    $ratio = $at1000 / $at100;
    printf("bench: instructions per replayed run: %.0f at 100 runs, %.0f at 1000 runs (ratio %.3f, limit %.2f)\n", $at100, $at1000, $ratio, $limit);
    if ($ratio > $limit) {
        fwrite(STDERR, "bench: replay cost grows faster than linearly\n");
        exit(1);
    }
