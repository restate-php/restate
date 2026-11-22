<?php

declare(strict_types=1);

namespace Restate\Tests\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Restate\DurableFutureAggregateException;
use Restate\Internal\Context\DurableFutureImpl;
use Restate\Internal\Context\FutureCombinators;
use Restate\Internal\SuspensionException;
use Restate\TerminalError;

/**
 * A random use of the future combinators, for the differential tests of {@see FutureCombinators} against
 * {@see ReferenceFutureCombinators}: n futures (sleeps, which succeed, or awakeables, which succeed with `"v<i>"` or
 * fail with 409 `failed <i>`), the order their results arrive in (some never do), the futures whose results are
 * taken before any combinator runs, and steps that combine subsets of them in random key orders. The same seed gives
 * the same scenario; a backend builds the journal from it (ScriptedInvocation::complete(), or FrameCodec frames for
 * the real VM) and run() plays the steps against either implementation, returning what the handler would observe.
 *
 * @phpstan-type Step array{op: 'select'|'all'|'allSettled'|'any'|'race'|'waitCompleted', members: list<int>, takes: list<bool>}
 */
final readonly class CombinatorScenario
{
    public const array OPERATIONS = ['select', 'all', 'allSettled', 'any', 'race', 'waitCompleted'];

    /**
     * @param list<'sleep'|'awakeable'> $kinds future index => kind
     * @param list<int|string> $keys future index => the key it has in every step
     * @param list<array{0: int, 1: bool}> $arrivals future index and success, in the order the results arrive
     * @param list<int> $preResolved the futures awaited one by one before the steps
     * @param list<Step> $steps
     */
    private function __construct(
        public int $seed,
        public array $kinds,
        public array $keys,
        public array $arrivals,
        public array $preResolved,
        public array $steps,
    ) {
    }

    public static function random(int $seed, int $maxFutures = 10): self
    {
        $random = new Randomizer(new Mt19937($seed));
        $count = $random->getInt(1, $maxFutures);

        // Every result arrives in half the scenarios; otherwise some never do, and the attempt suspends.
        $arrivingCount = $random->getInt(0, 1) === 0 ? $count : $random->getInt(0, $count);
        $arriving = $arrivingCount === 0 ? [] : $random->pickArrayKeys(range(0, $count - 1), $arrivingCount);
        $arrivals = [];
        foreach ($random->shuffleArray($arriving) as $index) {
            $arrivals[$index] = $random->getInt(0, 3) !== 0;
        }

        $kinds = [];
        $keys = [];
        foreach (range(0, $count - 1) as $index) {
            $kinds[] = ($arrivals[$index] ?? true) && $random->getInt(0, 1) === 0 ? 'sleep' : 'awakeable';
            $keys[] = $random->getInt(0, 1) === 0 ? 100 + $index : "f{$index}";
        }

        $preResolved = [];
        foreach (array_keys($arrivals) as $index) {
            if ($random->getInt(0, 4) === 0) {
                $preResolved[] = $index;
            }
        }

        $steps = [];
        foreach (range(1, $random->getInt(1, 4)) as $_) {
            $members = array_values($random->shuffleArray($random->pickArrayKeys(range(0, $count - 1), $random->getInt(1, $count))));
            $op = self::OPERATIONS[$random->getInt(0, count(self::OPERATIONS) - 1)];
            $takes = [];
            if ($op === 'select') {
                foreach (range(1, $random->getInt(1, count($members) + 1)) as $__) {
                    $takes[] = $random->getInt(0, 3) !== 0;
                }
            }
            $steps[] = ['op' => $op, 'members' => $members, 'takes' => $takes];
        }

        return new self(
            $seed,
            $kinds,
            $keys,
            array_map(static fn (int $index, bool $succeeds): array => [$index, $succeeds], array_keys($arrivals), array_values($arrivals)),
            $preResolved,
            $steps,
        );
    }

    /**
     * Plays the scenario on futures built for it (index => future) and returns what the handler observes: each
     * key, value and failure, in order. It stops at the first suspension, as the attempt would.
     *
     * @param array<int, DurableFutureImpl> $futures
     *
     * @return list<mixed>
     */
    public function run(FutureCombinators|ReferenceFutureCombinators $combinators, array $futures): array
    {
        $observed = [];
        try {
            foreach ($this->preResolved as $index) {
                $observed[] = ['pre', $index, self::outcome(static fn (): mixed => $futures[$index]->awaitResult())];
            }
            foreach ($this->steps as $step) {
                $set = [];
                foreach ($step['members'] as $index) {
                    $set[$this->keys[$index]] = $futures[$index];
                }
                $observed[] = [$step['op'], ...$this->step($combinators, $step, $set)];
            }
        } catch (SuspensionException $e) {
            $observed[] = ['suspended', $e->getMessage()];
        }

        return $observed;
    }

    /**
     * @param Step $step
     * @param array<int|string, DurableFutureImpl> $set
     *
     * @return list<mixed>
     */
    private function step(FutureCombinators|ReferenceFutureCombinators $combinators, array $step, array $set): array
    {
        return match ($step['op']) {
            'select' => self::selectLoop($combinators, $step['takes'], $set),
            'all' => [self::outcome(static fn (): array => $combinators->all($set))],
            'allSettled' => [self::outcome(static fn (): array => array_map(
                static fn (array $settled): array => $settled['status'] === 'fulfilled' ? $settled : ['rejected', self::failure($settled['reason'])],
                $combinators->allSettled($set),
            ))],
            'any' => [self::outcome(static fn (): mixed => $combinators->any($set))],
            'race' => [self::outcome(static fn (): mixed => $combinators->race($set))],
            'waitCompleted' => (static function () use ($combinators, $set): array {
                $split = $combinators->waitCompleted($set);

                return [array_keys($split['completed']), array_keys($split['pending'])];
            })(),
        };
    }

    /**
     * select() up to once per entry of $takes, taking the selected future's result and dropping it from the set where
     * $takes says so, else selecting again over the same set (whose selected future the VM then holds, not taken).
     *
     * @param list<bool> $takes
     * @param array<int|string, DurableFutureImpl> $set
     *
     * @return list<mixed>
     */
    private static function selectLoop(FutureCombinators|ReferenceFutureCombinators $combinators, array $takes, array $set): array
    {
        $observed = [];
        foreach ($takes as $take) {
            if ($set === []) {
                break;
            }
            $key = $combinators->select($set);
            $observed[] = $key;
            if ($take) {
                $future = $set[$key];
                $observed[] = self::outcome(static fn (): mixed => $future->awaitResult());
                unset($set[$key]);
            }
        }

        return $observed;
    }

    /** @param \Closure(): mixed $call */
    private static function outcome(\Closure $call): mixed
    {
        try {
            return ['value', $call()];
        } catch (SuspensionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return self::failure($e);
        }
    }

    /** @return list<mixed> */
    private static function failure(\Throwable $e): array
    {
        return match (true) {
            $e instanceof DurableFutureAggregateException => ['aggregate', array_map(self::failure(...), $e->failures())],
            $e instanceof TerminalError => ['terminal', $e->getCode(), $e->getMessage()],
            default => ['error', $e::class, $e->getMessage()],
        };
    }

}
