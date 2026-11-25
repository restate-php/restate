# Worker-Mode and Soak Testing

## Status

Accepted. Covers the integration suite in FrankenPHP worker mode with isolation probes, and the soak suite: frame replay, ZTS concurrency, bailouts across Rust frames, and an end-to-end soak through Restate. Builds on [Stray Output](0032-stray-output.md) and the VM lifetime fix (`tests/Extension/VmLifetimeTest.php`). The two soak images are one Dockerfile target with build arguments since [Image Builds and CI Caches](0042-image-builds-and-ci-caches.md).

## Context

FrankenPHP worker mode is the documented long-lived target. Suites that build the endpoint per request do not check what a worker shares between invocations, how it survives `exit()` and fatal errors, or whether anything grows over hundreds of thousands of requests. Three things make the last one hard to see: the Rust heap is invisible to `memory_get_usage()` and `memory_limit`; RSS mixes Zend's, glibc's and Go's allocators; and Restate is too slow and too indirect to drive a million requests and pin a leak to a request class.

## Decision

1. **The integration suite in worker mode is a PR job.** `worker.php` serves the integration endpoints built once per worker script run, and `WorkerProbe` makes every response name its worker run and counter, so a silent fallback to classic mode fails (`WorkerModeTest`). Isolation probes run against one worker thread, in an environment of their own, so "the next request" really is the same PHP thread.
2. **A `diagnostics` cargo feature, off by default.** A counting global allocator and live-object counters (`Vm`, `IdentityVerifier`), read by `Restate\Extension\diagnostics()`. It is compiled out completely otherwise (cfg-gated module, field and registration), so release modules are unchanged. The stub declares the function with `@restate-optional`; `ExtensionStubTest` accepts its absence and checks it like the rest when present. Only soak images build it.
3. **A debug-only bailout probe** next to the panic probe: `__restate_php_bailout_probe__` raises `E_ERROR` mid-conversion. It makes "PHP longjmps across Rust frames" deterministic instead of relying on `memory_limit` running out at the right moment.
4. **Soak by direct frame replay, gated on exact counters and slopes.** `ConcurrentHttpDriver` replays workload bodies as Restate sends them, class by class, then mixed. Checks after warm-up: exact (no live VM, same verifiers, files and worker run, fds ±4) and Theil–Sen slopes per class (Rust and PHP heaps under 1 byte per request, anonymous RSS under 16). Planted-leak canaries (`RESTATE_PHP_TEST_SOAK_CANARY`, test-only) must fail the same checks. Throughput and latency are reported, never gated: shared runners are too noisy.
5. **The end-to-end soak through Restate gates on correctness only**: answers, counter sums, unfinished invocations, 401s and RT0012s, with retention kept low by the handlers' own `journalRetention` and `idempotencyRetention`.

## Consequences

- Measured on FrankenPHP 1.13.5, PHP 8.4 ZTS (TESTING.md, "Soak suite"): neither heap grows per request once warm, in any class; an idle VM holds no Rust heap; a fatal error mid-conversion leaks at most the values being converted (about the payload), and the restart loop never gives up, because `max_consecutive_failures` counts only scripts that fail before their first `frankenphp_handle_request()`.
- In worker mode a body over `post_max_size` makes `frankenphp_handle_request()` print a warning before the endpoint runs (FrankenPHP's images have no php.ini, so `display_errors` is on), which breaks the response as stray-output case (b); `display_errors=0` or `enable_post_data_reading=0` avoids it ([SERVING.md](../serving.md#frankenphp-worker-mode)). `max_execution_time` is 30 under FrankenPHP and applies per request, not to the worker's lifetime.
- The RSS threshold comes from one full clean run and quick runs; the nightly artifacts are what to recalibrate it from. RSS per worker restart (about 30 KiB in the debug build) is reported, not gated.
- Two more images to build (`frankenphp-worker-release`, `frankenphp-worker-debug`), each recompiling only the extension crate on top of an existing ZTS build stage. They serve the integration worker script, not the load suite's router, so they are no drop-in for the load tests' `LOAD_ENDPOINT_IMAGE`.
