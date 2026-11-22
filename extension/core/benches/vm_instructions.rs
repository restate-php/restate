//! Instruction counts of the core's VM under callgrind (gungraun): what the
//! bench-extension workflow compares between a pull request and its
//! merge-base, near-deterministic on shared runners (TESTING.md,
//! Performance). Same subjects as `vm.rs`; building the request is the
//! setup, which gungraun does not count.
//!
//! Needs valgrind and the gungraun-runner binary of exactly the gungraun
//! version in Cargo.toml: `bin/bench-extension.sh` installs it and runs this,
//! and checks from the results that replay cost grows linearly.

#[path = "../tests/vm/protocol.rs"]
mod protocol;
mod subjects;
#[path = "../tests/vm/support.rs"]
#[allow(dead_code)]
mod support;

use gungraun::prelude::*;
use restate_php_core::{Bytes, IdentityVerifier, Vm};
use std::hint::black_box;
use subjects::{Request, LOGGED};

#[library_benchmark]
#[bench::plain(Request::start())]
#[bench::logged(Request::start().log_level(LOGGED))]
fn vm_start(request: Request) -> Vm {
    black_box(subjects::vm_start(&request))
}

// bin/bench-extension.sh checks that the instructions per replayed run stay
// flat from 10 through 1000 runs (it reads these ids).
#[library_benchmark]
#[bench::runs_0(Request::replay_runs(0), 0)]
#[bench::runs_10(Request::replay_runs(10), 10)]
#[bench::runs_100(Request::replay_runs(100), 100)]
#[bench::runs_1000(Request::replay_runs(1000), 1000)]
#[bench::logged_runs_100(Request::replay_runs(100).log_level(LOGGED), 100)]
fn replay_runs(request: Request, runs: u32) -> usize {
    black_box(subjects::replay_runs(&request, runs))
}

#[library_benchmark]
#[bench::keys_10(Request::eager_state(10), 10)]
#[bench::keys_1000(Request::eager_state(1000), 1000)]
fn eager_state(request: Request, keys: u32) -> usize {
    black_box(subjects::eager_state(&request, keys))
}

#[library_benchmark]
#[bench::mib_1(Request::start(), Bytes::from(vec![b'x'; 1 << 20]))]
fn output(request: Request, output: Bytes) -> usize {
    black_box(subjects::large_output(&request, &output))
}

#[library_benchmark]
#[bench::sleeps_10(Request::fired_sleeps(10), 10)]
#[bench::sleeps_100(Request::fired_sleeps(100), 100)]
fn await_tree(request: Request, sleeps: u32) -> usize {
    black_box(subjects::await_tree(&request, sleeps))
}

#[library_benchmark]
#[bench::awakeables_1(Request::start(), 1)]
#[bench::awakeables_100(Request::start(), 100)]
fn suspend(request: Request, awakeables: u32) -> usize {
    black_box(subjects::suspend(&request, awakeables))
}

#[library_benchmark]
fn identity_verifier_new() -> IdentityVerifier {
    black_box(subjects::identity_verifier())
}

#[library_benchmark]
#[bench::valid(
    subjects::identity_verifier(),
    subjects::signed_headers(subjects::IDENTITY_PATH, true),
    true
)]
#[bench::bad_audience(
    subjects::identity_verifier(),
    subjects::signed_headers("/invoke/Greeter/other", true),
    false
)]
#[bench::expired(
    subjects::identity_verifier(),
    subjects::signed_headers(subjects::IDENTITY_PATH, false),
    false
)]
fn identity_verify(verifier: IdentityVerifier, headers: Vec<(String, String)>, accepted: bool) {
    subjects::verify(&verifier, &headers, accepted);
}

library_benchmark_group!(
    name = vm,
    benchmarks = [
        vm_start,
        replay_runs,
        eager_state,
        output,
        await_tree,
        suspend
    ]
);

library_benchmark_group!(
    name = identity,
    benchmarks = [identity_verifier_new, identity_verify]
);

main!(library_benchmark_groups = vm, identity);
