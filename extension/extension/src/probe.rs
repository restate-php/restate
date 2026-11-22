//! The panic and bailout probes: ways for tests to make the extension panic,
//! or end the PHP request with a fatal error, compiled into debug builds
//! only.
//!
//! In a debug build, passing [`PANIC_PROBE`] as any string the extension
//! converts from a PHP array (a header name or value, a call target field,
//! …) panics during argument conversion, and passing it as the name of
//! `Vm::sysRun()` panics inside the operation. `tests/Unit/Sdk/Backend/
//! ExtensionPanicTest.php` uses both to check the panic guard of
//! [`crate::PhpVm`].
//!
//! Passing [`BAILOUT_PROBE`] the same ways raises a PHP fatal error
//! (`E_ERROR`) at that point instead. PHP then unwinds with `longjmp` to the
//! request's bailout point, straight across the Rust frames of the
//! conversion: their destructors never run, as when `memory_limit` runs out
//! while the extension builds a PHP value. The soak suite
//! (`tests/Soak/BailoutSoakTest.php`) measures what that leaks, and how a
//! FrankenPHP worker survives it.
//!
//! Release builds (`cargo build --release`, where `debug_assertions` is off)
//! compile [`trip`] to nothing, so the probe values are ordinary strings
//! there.

/// The value that trips the panic probe.
#[cfg(debug_assertions)]
pub const PANIC_PROBE: &str = "__restate_php_panic_probe__";

/// The value that trips the bailout probe.
#[cfg(debug_assertions)]
pub const BAILOUT_PROBE: &str = "__restate_php_bailout_probe__";

/// Panics when `value` is the panic probe value, raises a fatal error when it
/// is the bailout probe value (debug builds only).
#[cfg(debug_assertions)]
pub fn trip(value: &str) {
    if value == PANIC_PROBE {
        panic!("restate panic probe");
    }
    if value == BAILOUT_PROBE {
        // Does not return: E_ERROR bails out of the request.
        ext_php_rs::error::php_error(
            &ext_php_rs::flags::ErrorType::Error,
            "restate bailout probe",
        );
    }
}

#[cfg(not(debug_assertions))]
#[inline(always)]
pub fn trip(_value: &str) {}
