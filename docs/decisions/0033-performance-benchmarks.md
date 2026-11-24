# Performance Benchmarks

## Status

Accepted. Covers PHP micro-benchmarks compared between a pull request and its base, Rust micro-benchmarks, and load tests. Decision 6 is amended by [Load Tests in Compose](0040-load-tests-in-compose.md): the load tests no longer compare runs or gate. [TESTING.md](../../TESTING.md#performance) has the commands and reference measurements.

## Context

Nothing measured the SDK's time, memory or capacity. The hot path spans PHP (routing, header parsing, the context, serde and DTO mapping, the PHP-Rust conversion) and Rust (the core's bookkeeping over the pinned shared-core). Under php-fpm and `php -S` the endpoint is rebuilt on every request, so construction cost matters as much as an attempt's. The API can change between a pull request and its base, so a benchmark written against head's API may not run on the base it is compared with. CI runs on shared GitHub runners, where wall-clock times move by 10-20% between runs. On the development machine, an A/A run under load moved them by up to 60%. Instruction counts under callgrind repeated to within 0.012% (PHP) and 0.3% (Rust, gungraun).

## Decision

1. **Three layers.** phpbench for PHP, against the release extension with OPcache on and JIT off. gungraun (instruction counts) plus criterion (wall clock) for the Rust core, behind a `bench` feature with `required-features`, so `composer check:extension` never builds them. k6 load tests against Restate on a dedicated runner only. The first two run on pull requests; load numbers from shared runners would not be comparable.
2. **Each side runs its own benchmarks.** `bin/bench-compare.sh` checks the base out in a worktree, runs the base's own benchmark files on the base and head's on head, and compares only the variants with the same `Class::subject#set` name in both. A pull request that changes the API, or adds, renames or removes a benchmark, never breaks the comparison; the unmatched names are listed. Running head's files against base code would break exactly on refactoring PRs.
3. **Stable entry points.** The endpoint benchmarks send the workload library's wire-level request bodies through `Endpoint::handleHttpRequest()`. The Rust benchmarks feed protocol frames to the core's `Vm`. Both survive internal churn.
4. **Noise control.** Base and head run in one job on one machine, in the order base, head, head, base. A time regression must exceed the threshold in both pairs, with every run under an rstdev limit; anything noisier is reported, not failed.
5. **Gate on instructions first.** A callgrind count per revolution of three PHP subjects (only the measured loop is collected, `--toggle-collect=zif_array_map`), and gungraun's counts with a +2% limit, are the backstop. The replay benchmarks also assert linear growth in the Rust core (instructions per run at 1000 runs ≤ 1.25 × at 100), so a superlinear replay fails even when the base was already slow.
6. **Load-test gate: endpoint CPU per invocation** from the endpoint container's cgroup `cpu.stat`. Latency and the Restate-vs-SDK split are reported only. Each run is compared with a rolling 5-run median and a pinned per-release baseline; the rolling median alone misses slow creep.
7. **Calibrate before gating.** Every CI job is `continue-on-error`. `bench.yaml` runs a nightly A/A comparison of main against itself and keeps the reports for 90 days. After about two weeks, set the thresholds to about 1.5 × the worst A/A delta, then drop `continue-on-error`.

## Consequences

- A hot-path regression shows in a pull request's job summary. It fails the job only once the thresholds are calibrated, and earliest for instruction counts.
- A PR job costs about 5 min for PHP (four phpbench runs of about 45 s, callgrind about 30 s, plus the base's `composer install`, and a base extension build when `extension/` changed) and about 6-8 min for Rust.
- `criterion` and `gungraun` are dev-dependencies of the core, so `cargo test` builds them although it never builds the benches.
- Benchmarks rot silently unless something compiles them. phpbench files are under `tests/`, so PHPStan and php-cs-fixer check them. The Rust benches are linted with the feature on by `just extension bench`.
- The load tests need a self-hosted runner labelled `restate-php-load`. Until one exists they run by hand (`composer load`).
- The reference measurements show costs worth following:
  - shared-core's all-of-N await tree is O(N²) in instructions;
  - 100 eager state keys cost about 5 µs each;
  - 1 MiB in and out costs about 7 ms;
  - the built-in DTO mapper costs nearly as much warm as cold.
