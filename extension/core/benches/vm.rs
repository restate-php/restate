//! Wall-clock benchmarks of the core's VM (criterion): local profiling and
//! the nightly report, never a gate (TESTING.md, Performance). The
//! instruction counts CI compares are `vm_instructions.rs`, over the same
//! subjects.
//!
//! `cargo bench -p restate-php-core --features bench --bench vm` (from
//! `extension/`), or `bin/bench-extension.sh criterion`.

#[path = "../tests/vm/protocol.rs"]
mod protocol;
mod subjects;
#[path = "../tests/vm/support.rs"]
#[allow(dead_code)]
mod support;

use criterion::{criterion_group, criterion_main, BenchmarkId, Criterion, Throughput};
use restate_php_core::Bytes;
use subjects::{Request, LOGGED};

fn vm_start(c: &mut Criterion) {
    let mut group = c.benchmark_group("vm_start");
    for (name, level) in [("plain", None), ("logged", LOGGED)] {
        let request = Request::start().log_level(level);
        group.bench_function(name, |b| b.iter(|| subjects::vm_start(&request)));
    }
    group.finish();
}

fn replay_runs(c: &mut Criterion) {
    for (name, level) in [("replay_runs", None), ("replay_runs_logged", LOGGED)] {
        let mut group = c.benchmark_group(name);
        for runs in [0u32, 10, 100, 1000, 10_000] {
            let request = Request::replay_runs(runs).log_level(level);
            group.throughput(Throughput::Elements(u64::from(runs.max(1))));
            if runs >= 1000 {
                group.sample_size(10);
            }
            group.bench_with_input(BenchmarkId::from_parameter(runs), &runs, |b, &runs| {
                b.iter(|| subjects::replay_runs(&request, runs))
            });
        }
        group.finish();
    }
}

fn eager_state(c: &mut Criterion) {
    let mut group = c.benchmark_group("eager_state");
    for keys in [10u32, 1000] {
        let request = Request::eager_state(keys);
        group.bench_with_input(BenchmarkId::from_parameter(keys), &keys, |b, &keys| {
            b.iter(|| subjects::eager_state(&request, keys))
        });
    }
    group.finish();
}

fn output_1mib(c: &mut Criterion) {
    let request = Request::start();
    let output = Bytes::from(vec![b'x'; 1 << 20]);
    let mut group = c.benchmark_group("output");
    group.throughput(Throughput::Bytes(1 << 20));
    group.bench_function("1mib", |b| {
        b.iter(|| subjects::large_output(&request, &output))
    });
    group.finish();
}

fn await_tree(c: &mut Criterion) {
    let mut group = c.benchmark_group("await_tree");
    for sleeps in [10u32, 100] {
        let request = Request::fired_sleeps(sleeps);
        group.bench_with_input(
            BenchmarkId::from_parameter(sleeps),
            &sleeps,
            |b, &sleeps| b.iter(|| subjects::await_tree(&request, sleeps)),
        );
    }
    group.finish();

    let mut group = c.benchmark_group("suspend");
    let request = Request::start();
    for awakeables in [1u32, 100] {
        group.bench_with_input(
            BenchmarkId::from_parameter(awakeables),
            &awakeables,
            |b, &awakeables| b.iter(|| subjects::suspend(&request, awakeables)),
        );
    }
    group.finish();
}

fn identity(c: &mut Criterion) {
    let mut group = c.benchmark_group("identity");
    group.bench_function("verifier_new", |b| b.iter(subjects::identity_verifier));

    let verifier = subjects::identity_verifier();
    for (name, headers, accepted) in [
        (
            "verify_valid",
            subjects::signed_headers(subjects::IDENTITY_PATH, true),
            true,
        ),
        (
            "verify_bad_audience",
            subjects::signed_headers("/invoke/Greeter/other", true),
            false,
        ),
        (
            "verify_expired",
            subjects::signed_headers(subjects::IDENTITY_PATH, false),
            false,
        ),
    ] {
        group.bench_function(name, |b| {
            b.iter(|| subjects::verify(&verifier, &headers, accepted))
        });
    }
    group.finish();
}

criterion_group!(
    benches,
    vm_start,
    replay_runs,
    eager_state,
    output_1mib,
    await_tree,
    identity
);
criterion_main!(benches);
