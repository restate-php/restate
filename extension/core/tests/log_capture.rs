//! Log capture across VMs in a process of its own: the most verbose level any
//! capture asked for is process-wide state (`restate_php_core::logs`), so
//! these tests must not share a process with captures they do not create.
//!
//! `cargo test -p restate-php-core --test log_capture -- --ignored
//! --nocapture` also runs the creation-cost measurement.

use restate_php_core::{LogLevel, Vm};
use std::hint::black_box;
use std::time::{Duration, Instant};

fn headers() -> Vec<(String, String)> {
    vec![(
        "content-type".to_owned(),
        "application/vnd.restate.invocation.v5".to_owned(),
    )]
}

fn vm(level: LogLevel) -> Vm {
    Vm::with_log_level(headers(), Some(level)).unwrap()
}

/// One callsite, hit by every test below.
fn log_at_debug() {
    tracing::debug!("from the test");
}

fn messages(vm: &mut Vm) -> Vec<String> {
    vm.take_logs()
        .into_iter()
        .map(|record| record.message)
        .collect()
}

/// A capture more verbose than every one before re-enables callsites that
/// were disabled, and each capture still keeps only its own level.
#[test]
fn a_more_verbose_capture_enables_what_earlier_ones_disabled() {
    let mut warn = vm(LogLevel::Warn);
    warn.logged(|_| log_at_debug());
    assert_eq!(messages(&mut warn), Vec::<String>::new());

    let mut debug = vm(LogLevel::Debug);
    debug.logged(|_| log_at_debug());
    warn.logged(|_| log_at_debug());

    assert_eq!(messages(&mut debug), vec!["from the test"]);
    assert_eq!(messages(&mut warn), Vec::<String>::new());
}

/// The fastest of `rounds` runs of creating and dropping `count` VMs that
/// capture at info, per VM.
fn creation_cost(count: u32, rounds: u32) -> Duration {
    (0..rounds)
        .map(|_| {
            let start = Instant::now();
            for _ in 0..count {
                black_box(vm(LogLevel::Info));
            }
            start.elapsed() / count
        })
        .min()
        .unwrap()
}

/// Creating a capturing VM costs the same however many capturing VMs are
/// alive. With a `tracing` dispatcher per VM it grew linearly (a debug build
/// measured 4.7 µs with none alive, 449 µs with 5,000), under tracing-core's
/// process-wide dispatcher lock. Timing-based, so ignored by default; the
/// bound is loose (the old design is about 100 times slower at 5,000).
#[test]
#[ignore = "timing measurement; run with --ignored --nocapture"]
fn creating_a_vm_costs_the_same_with_many_live_capturing_vms() {
    let _warm_up = creation_cost(1_000, 1);
    let alone = creation_cost(2_000, 5);

    let live: Vec<Vm> = (0..5_000).map(|_| vm(LogLevel::Info)).collect();
    let crowded = creation_cost(2_000, 5);
    drop(live);

    println!("per VM: {alone:?} with no capturing VM alive, {crowded:?} with 5,000 alive");
    assert!(
        crowded < alone * 3,
        "creating a VM with 5,000 capturing VMs alive took {crowded:?}, alone {alone:?}"
    );
}
