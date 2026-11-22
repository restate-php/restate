<?php

declare(strict_types=1);

namespace Restate\Tests\Support;

use InvalidArgumentException;
use Restate\DurableFuture;
use Restate\DurableFutureAggregateException;
use Restate\Internal\AwaitCombinator;
use Restate\Internal\Context\DurableFutureImpl;
use Restate\Internal\InvocationOperations;
use Restate\Internal\ResultDecoder;
use Restate\Internal\SuspensionException;

/**
 * The test oracle for {@see \Restate\Internal\Context\FutureCombinators}: the same combinators written the
 * straightforward way. After every tree await they ask `isCompleted()` of each future, one backend call per future, so
 * a select() loop over n futures makes O(n^2) backend calls; the production class reads the same answer from the tree
 * await's result in one call. The differential tests (`FutureCombinatorsDifferentialTest`, and
 * `tests/Extension/FutureCombinatorsReplayTest.php` on the real VM) run both on the same journals and require the same
 * results, failures and backend interaction.
 */
final readonly class ReferenceFutureCombinators
{
    public function __construct(private InvocationOperations $operations)
    {
    }

    /**
     * @param array<array-key, mixed> $futures
     * @return array<array-key, mixed>
     */
    public function all(array $futures): array
    {
        $operation = 'Context::all()';
        $pending = $this->validated($futures, $operation);
        $results = [];

        foreach ($pending as $key => $future) {
            if ($future->isCompleted()) {
                $results[$key] = $future->awaitResult();
                unset($pending[$key]);
            }
        }

        while ($pending !== []) {
            // The VM resolves the tree once every future succeeded or on the first failure, and on protocol v7
            // Restate resumes a suspended invocation only then.
            foreach ($this->awaitTree(AwaitCombinator::AllSucceededOrFirstFailed, $pending, $operation) as $key) {
                $results[$key] = $pending[$key]->awaitResult();
                unset($pending[$key]);
            }
        }

        return self::orderedResults($futures, $results);
    }

    /** @param array<array-key, mixed> $futures */
    public function race(array $futures): mixed
    {
        $operation = 'Context::race()';
        $pending = $this->validatedNonEmpty($futures, $operation);
        foreach ($pending as $future) {
            if ($future->isCompleted()) {
                return $future->awaitResult();
            }
        }

        return $pending[$this->awaitAnyKey($pending, $operation)]->awaitResult();
    }

    /**
     * The aggregate lists the failures in the order the futures completed. That order is why this awaits one
     * completion at a time instead of awaiting the core's first-succeeded-or-all-failed tree: after that tree resolves
     * the VM can tell which futures completed, but not in which order.
     *
     * @param array<array-key, mixed> $futures
     */
    public function any(array $futures): mixed
    {
        $operation = 'Context::any()';
        $pending = $this->validated($futures, $operation);
        $failures = [];

        while ($pending !== []) {
            foreach ($pending as $key => $future) {
                if (!$future->isCompleted()) {
                    continue;
                }

                try {
                    return $future->awaitResult();
                } catch (SuspensionException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $failures[$key] = $e;
                    unset($pending[$key]);
                }
            }
            if ($pending === []) {
                break;
            }

            $key = $this->awaitAnyKey($pending, $operation);
            try {
                return $pending[$key]->awaitResult();
            } catch (SuspensionException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $failures[$key] = $e;
                unset($pending[$key]);
            }
        }

        throw new DurableFutureAggregateException($failures);
    }

    /**
     * @param array<array-key, mixed> $futures
     * @return array<array-key, array{status: 'fulfilled', value: mixed}|array{status: 'rejected', reason: \Throwable}>
     */
    public function allSettled(array $futures): array
    {
        $operation = 'Context::allSettled()';
        $pending = $this->validated($futures, $operation);
        $results = [];

        foreach ($pending as $key => $future) {
            if ($future->isCompleted()) {
                $results[$key] = self::settle($future);
                unset($pending[$key]);
            }
        }

        while ($pending !== []) {
            // The VM resolves the tree once every future completed; on protocol v7 Restate resumes a suspended
            // invocation only then.
            foreach ($this->awaitTree(AwaitCombinator::AllCompleted, $pending, $operation) as $key) {
                $results[$key] = self::settle($pending[$key]);
                unset($pending[$key]);
            }
        }

        return self::orderedResults($futures, $results);
    }

    /**
     * @param array<array-key, mixed> $futures
     * @return array{completed: array<array-key, DurableFuture>, pending: array<array-key, DurableFuture>}
     */
    public function waitCompleted(array $futures): array
    {
        $operation = 'Context::waitCompleted()';
        $pending = $this->validated($futures, $operation);
        $completed = [];

        foreach ($pending as $key => $future) {
            if ($future->isCompleted()) {
                $completed[$key] = $future;
                unset($pending[$key]);
            }
        }
        if ($completed !== [] || $pending === []) {
            return ['completed' => $completed, 'pending' => $pending];
        }

        $key = $this->awaitAnyKey($pending, $operation);
        $completed[$key] = $pending[$key];
        unset($pending[$key]);

        return ['completed' => $completed, 'pending' => $pending];
    }

    /** @param array<array-key, mixed> $futures */
    public function select(array $futures): int|string
    {
        $operation = 'Context::select()';
        $validated = $this->validatedNonEmpty($futures, $operation);
        foreach ($validated as $key => $future) {
            if ($future->isCompleted()) {
                return $key;
            }
        }

        return $this->awaitAnyKey($validated, $operation);
    }

    /**
     * @param array<array-key, mixed> $futures
     * @return non-empty-array<array-key, DurableFutureImpl>
     */
    private function validatedNonEmpty(array $futures, string $operation): array
    {
        $validated = $this->validated($futures, $operation);
        if ($validated === []) {
            throw new InvalidArgumentException("{$operation} requires at least one durable future.");
        }

        return $validated;
    }

    /**
     * @param array<array-key, mixed> $futures
     * @return array<array-key, DurableFutureImpl>
     */
    private function validated(array $futures, string $operation): array
    {
        $validated = [];
        $handles = [];
        foreach ($futures as $key => $future) {
            if (!$future instanceof DurableFuture) {
                throw new InvalidArgumentException("{$operation} accepts only DurableFuture instances.");
            }
            if (!$future instanceof DurableFutureImpl || $future->operations !== $this->operations) {
                throw new InvalidArgumentException("{$operation} futures must belong to this context.");
            }

            $handle = $future->handle;
            if (array_key_exists($handle, $handles)) {
                throw new InvalidArgumentException("{$operation} futures must have unique handles.");
            }

            $handles[$handle] = true;
            $validated[$key] = $future;
        }

        return $validated;
    }

    /**
     * The key of the first future, in key order, the VM reports completed once one of them completed.
     *
     * @param non-empty-array<array-key, DurableFutureImpl> $futures
     */
    private function awaitAnyKey(array $futures, string $operation): int|string
    {
        return $this->awaitTree(AwaitCombinator::FirstCompleted, $futures, $operation)[0];
    }

    /**
     * Awaits the combinator over the futures (none of them resolved yet) and returns the keys of those the VM then
     * reports completed, in key order, without taking their results.
     *
     * @param non-empty-array<array-key, DurableFutureImpl> $futures
     *
     * @return non-empty-list<array-key>
     */
    private function awaitTree(AwaitCombinator $combinator, array $futures, string $operation): array
    {
        $handles = [];
        foreach ($futures as $future) {
            $handles[] = $future->handle;
        }

        ResultDecoder::assertCompleted($this->operations->doAwaitTree($combinator->tree($handles)), $operation, "{$operation} suspended.");

        $completed = [];
        foreach ($futures as $key => $future) {
            if ($this->operations->isCompleted($future->handle)) {
                $completed[] = $key;
            }
        }
        if ($completed === []) {
            throw new \RuntimeException("{$operation} await completed, but none of its durable futures did.");
        }

        return $completed;
    }

    /** @return array{status: 'fulfilled', value: mixed}|array{status: 'rejected', reason: \Throwable} */
    private static function settle(DurableFuture $future): array
    {
        try {
            return ['status' => 'fulfilled', 'value' => $future->awaitResult()];
        } catch (SuspensionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ['status' => 'rejected', 'reason' => $e];
        }
    }

    /**
     * @template T
     *
     * @param array<array-key, mixed> $source
     * @param array<array-key, T> $results
     * @return array<array-key, T>
     */
    private static function orderedResults(array $source, array $results): array
    {
        $ordered = [];
        foreach ($source as $key => $_) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }

        return $ordered;
    }
}
