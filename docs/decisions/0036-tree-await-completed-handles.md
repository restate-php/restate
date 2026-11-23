# Tree Awaits Report Their Completed Handles

## Status

Accepted. Fixes the SDK side of wide fan-out being quadratic per attempt ([Wide Fan-out](../serving.md#wide-fan-out-costs-quadratic-time-per-attempt), measured by `FanOutTest` and `FanOutBench`). The shared-core side is not fixed in 8.7.2, the pinned version ([Shared Core](0014-shared-core.md)); an upstream fix, [restatedev/sdk-shared-core#122](https://github.com/restatedev/sdk-shared-core/pull/122), is open.

## Context

Every combinator (`Context::all()`, `allSettled()`, `any()`, `race()`, `select()`, `waitCompleted()`) awaits a tree over the futures it has not resolved yet (`InvocationOperations::doAwaitTree()`) and then needs to know which of them the VM now holds. Asking `isCompleted()` of every one of them, one extension call per future, makes a `select()` call over r futures cost r + 1 calls (and three passes over its futures in PHP), and a `select()` loop over n futures about 2n² extension calls per attempt (`FanOutBench`: 1.4 s for n = 2000 with the release build, 4.3 s with the debug build).

What the VM reports there is subtle and must not change. shared-core's `try_resolve_future` moves arrived results into its `ready` set one at a time, in arrival order, and stops as soon as the tree (or a combinator nested in it) resolves; `is_completed` sees only `ready`. "The first future, in key order, that the VM reports completed" is therefore deterministic across replays, and it is what `select()` returns, `race()` takes, `waitCompleted()` splits on and `any()` orders its failures by.

Two ways to get the answer in one call:

1. **A batched query**, `areCompleted(list<int>)`, called after the await.
2. **The await reports it**: a completed `doAwaitTree()` returns the tree's handles that `is_completed` then reports.

## Decision

1. **The tree await reports its completed handles.** `Vm::do_await_tree()` in the core collects the tree's handles (depth first, each as often as it occurs) before it hands the tree to shared-core and, once the await completed, keeps those `is_completed` reports, in that order: `Progress<Vec<Handle>>`. The extension returns `['type' => 'any_completed', 'completed' => [handle, …]]`; the typed contract carries them as `AwaitResult::$completedHandles` (`SurfaceValues::awaitTreeResult()`). It is one call where a batched query would be two, the handles are already in Rust (a query would pass the list again), and nothing can run between the await and the check, so the answer is by construction the one the per-handle calls gave right after the await. `doAwait()` is unchanged, and `isCompleted()` stays in the contract for single handles (and as the oracle of the differential tests).
2. **The combinators make one pass in PHP and one call per await.** One pass validates the futures (the same checks and messages, in the same order) and splits them into those already resolved and the others with each one's handle; the tree is built from those handles in key order, so the reported handles map back to keys in key order. `DurableFutureImpl` exposes `$handle`, `$operations` and `$resolved` as public-read properties, so the pass reads properties instead of calling three methods per future. `any()` rescans its pending futures after each failure (property reads, no extension call), because a result's deserializer may have awaited one of them, and a fresh scan takes such futures first.
3. **The per-handle algorithm is the test oracle.** `tests/Support/ReferenceFutureCombinators.php` keeps it, unchanged but for the property reads. `FutureCombinatorsDifferentialTest` plays 3000 random scenarios (random futures, arrival orders, missing results, pre-taken results, subsets in random key orders, select loops that leave the selected future in the set) on `ScriptedInvocation` with both, and requires the same observations and the same backend calls but the dropped `isCompleted()` ones. `tests/Extension/FutureCombinatorsReplayTest.php` does the same on the real VM with replayed `FrameCodec` journals on protocol v5, v6 and v7, comparing what the VM writes too, suspension and await point included, and checks the reported handles against `isCompleted()` for random nested trees.

## Consequences

- The stub, `BackendShapes` (field type `list<int>`), `BackendContractTest` and `ScriptedInvocation` describe the field. `ScriptedInvocation` resolves trees like the VM does: only on results processed and not taken.
- Measured in process, release build, best of 5 on a loaded machine, per-handle calls → reported handles: a `select()` loop over 2000 completed sleeps 1396 → 351 ms, `any()` over 2000 rejected awakeables 1231 → 271 ms; `all()` and `allSettled()` unchanged (70 ms). Live (`FanOutTest`, debug build, `php -S`), a `select()` loop over awakeables completed one per attempt: 22 → 4.5 s of handler time for 200, 178 → 57 s for 500, 1487 → 448 s for 1000. The gungraun `await_tree` instruction counts grew 0.9% (10 sleeps) and 0.5% (100), the cost of the handle list.
- Still O(n²) per attempt: shared-core reduces the whole remaining tree once per result it processes, in `all()` within one await and in every `select()`; each call also passes the r futures it is given (the SDK's pass, about 100 ms of the 351, and the extension's conversion of the tree, about 65 ms). Removing that would need an incremental API (a set of pending handles held in the VM), not a cheaper query.
