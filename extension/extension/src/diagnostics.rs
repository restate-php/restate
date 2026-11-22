//! Resource counters for soak tests, compiled in only with the
//! `diagnostics` cargo feature (off by default; no release ships it).
//!
//! The Rust heap is invisible to PHP: `memory_get_usage()` and
//! `memory_limit` only see Zend's allocator. With the feature, a counting
//! global allocator wraps the system allocator for every allocation this
//! module makes, and the PHP objects that own Rust resources count
//! themselves. `Restate\Extension\diagnostics()` reports both:
//!
//! - `live_vms`: `Restate\Extension\Vm` objects whose core VM has not been
//!   dropped yet (by `free()` or when PHP frees the object);
//! - `live_identity_verifiers`: `Restate\Extension\IdentityVerifier` objects;
//! - `rust_live_bytes`: bytes allocated and not freed;
//! - `rust_allocations`: allocations so far.
//!
//! The counts are process-wide: under a thread-safe PHP they add up every
//! thread. tests/Soak reads them through the worker's `/__worker` probe.
//! Without the feature none of this exists, the function included (the stub
//! marks it optional), and the extension behaves exactly as before.

use crate::convert::Array;
use crate::exception::ExtensionResult;
use ext_php_rs::prelude::*;
use ext_php_rs::types::ZendHashTable;
use restate_php_core::guard::catch_panic;
use std::alloc::{GlobalAlloc, Layout, System};
use std::sync::atomic::{AtomicI64, AtomicUsize, Ordering};

static LIVE_VMS: AtomicUsize = AtomicUsize::new(0);
static LIVE_VERIFIERS: AtomicUsize = AtomicUsize::new(0);
// Signed, so that updates racing on other threads never wrap it.
static LIVE_BYTES: AtomicI64 = AtomicI64::new(0);
static ALLOCATIONS: AtomicUsize = AtomicUsize::new(0);

/// The system allocator, counting live bytes and allocations.
pub struct CountingAllocator;

#[global_allocator]
static ALLOCATOR: CountingAllocator = CountingAllocator;

fn grew(bytes: usize) {
    LIVE_BYTES.fetch_add(i64::try_from(bytes).unwrap_or(i64::MAX), Ordering::Relaxed);
}

fn shrank(bytes: usize) {
    LIVE_BYTES.fetch_sub(i64::try_from(bytes).unwrap_or(i64::MAX), Ordering::Relaxed);
}

// SAFETY: every method forwards to `System` with the same arguments and only
// adds counter updates, which never allocate.
unsafe impl GlobalAlloc for CountingAllocator {
    unsafe fn alloc(&self, layout: Layout) -> *mut u8 {
        let block = System.alloc(layout);
        if !block.is_null() {
            grew(layout.size());
            ALLOCATIONS.fetch_add(1, Ordering::Relaxed);
        }
        block
    }

    unsafe fn alloc_zeroed(&self, layout: Layout) -> *mut u8 {
        let block = System.alloc_zeroed(layout);
        if !block.is_null() {
            grew(layout.size());
            ALLOCATIONS.fetch_add(1, Ordering::Relaxed);
        }
        block
    }

    unsafe fn dealloc(&self, block: *mut u8, layout: Layout) {
        System.dealloc(block, layout);
        shrank(layout.size());
    }

    unsafe fn realloc(&self, block: *mut u8, layout: Layout, new_size: usize) -> *mut u8 {
        let moved = System.realloc(block, layout, new_size);
        if !moved.is_null() {
            grew(new_size);
            shrank(layout.size());
            ALLOCATIONS.fetch_add(1, Ordering::Relaxed);
        }
        moved
    }
}

/// One live object of a counted kind, for as long as this value lives.
pub struct Live(&'static AtomicUsize);

impl Live {
    pub fn vm() -> Self {
        LIVE_VMS.fetch_add(1, Ordering::Relaxed);
        Self(&LIVE_VMS)
    }

    pub fn identity_verifier() -> Self {
        LIVE_VERIFIERS.fetch_add(1, Ordering::Relaxed);
        Self(&LIVE_VERIFIERS)
    }
}

impl Drop for Live {
    fn drop(&mut self) {
        self.0.fetch_sub(1, Ordering::Relaxed);
    }
}

/// `['live_vms' => int, 'live_identity_verifiers' => int,
/// 'rust_live_bytes' => int, 'rust_allocations' => int]`
#[php_function]
#[php(name = "Restate\\Extension\\diagnostics")]
pub fn diagnostics() -> ExtensionResult<Array> {
    catch_panic(|| {
        let count = |counter: &AtomicUsize| {
            i64::try_from(counter.load(Ordering::Relaxed)).unwrap_or(i64::MAX)
        };
        let mut array = ZendHashTable::new();
        array.insert("live_vms", count(&LIVE_VMS))?;
        array.insert("live_identity_verifiers", count(&LIVE_VERIFIERS))?;
        array.insert("rust_live_bytes", LIVE_BYTES.load(Ordering::Relaxed))?;
        array.insert("rust_allocations", count(&ALLOCATIONS))?;
        Ok(array)
    })
}

/// Adds `diagnostics()` to the module.
pub fn register(module: ModuleBuilder) -> ModuleBuilder {
    module.function(wrap_function!(diagnostics))
}
