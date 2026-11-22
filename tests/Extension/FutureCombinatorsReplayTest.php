<?php

declare(strict_types=1);

namespace Restate\Tests\Extension;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Restate\Internal\AwaitCombinator;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\AwaitTree;
use Restate\Internal\Backend\Extension\Invocation;
use Restate\Internal\Context\DurableFutureImpl;
use Restate\Internal\Context\FutureCombinators;
use Restate\Tests\Support\CombinatorScenario;
use Restate\Tests\Support\Protocol\FrameCodec;
use Restate\Tests\Support\ReferenceFutureCombinators;

/**
 * Differential tests of {@see FutureCombinators} on the real VM. A tree await reports the handles then completed
 * itself; that must be exactly what isCompleted() reports for each handle right after it, for any tree. And on random
 * replayed journals (sleeps and awakeables, results in random order, some missing so the attempt suspends)
 * {@see FutureCombinators} must observe what {@see ReferenceFutureCombinators} (the oracle that asks isCompleted() of
 * each future after every await) does, and make the VM write the same bytes, suspension and await point included.
 */
final class FutureCombinatorsReplayTest extends ExtensionTestCase
{
    private const int SCENARIOS = 2000;

    private const int TREES = 1500;

    public function testRandomReplaysObserveAndWriteTheSameAsTheReference(): void
    {
        $suspended = 0;
        for ($seed = 1; $seed <= self::SCENARIOS; $seed++) {
            $scenario = CombinatorScenario::random($seed);
            $contentType = [self::V5, self::V6, self::V7][$seed % 3];
            [$referenceObserved, $referenceWritten] = self::play($scenario, $contentType, static fn (Invocation $invocation): ReferenceFutureCombinators => new ReferenceFutureCombinators($invocation));
            [$productionObserved, $productionWritten] = self::play($scenario, $contentType, static fn (Invocation $invocation): FutureCombinators => new FutureCombinators($invocation));

            self::assertSame($referenceObserved, $productionObserved, "seed {$seed}: observed");
            self::assertSame(bin2hex($referenceWritten), bin2hex($productionWritten), "seed {$seed}: written");
            $suspended += FrameCodec::messageTypes($productionWritten) === [FrameCodec::SUSPENSION] ? 1 : 0;
        }

        self::assertGreaterThan(self::SCENARIOS / 10, $suspended, 'some scenarios suspend');
    }

    public function testATreeAwaitReportsWhatIsCompletedReportsForEachHandle(): void
    {
        $completedAwaits = 0;
        for ($seed = 1; $seed <= self::TREES; $seed++) {
            $random = new Randomizer(new Mt19937($seed));
            $count = $random->getInt(1, 8);
            $journal = [];
            $notifications = [];
            $kinds = [];
            for ($index = 0, $sleeps = 0, $awakeables = 0; $index < $count; $index++) {
                if ($random->getInt(0, 1) === 0) {
                    $kinds[] = 'sleep';
                    $journal[] = FrameCodec::sleepCommandFrame(1, ++$sleeps);
                    $notifications[] = FrameCodec::completionFrame(FrameCodec::SLEEP_COMPLETION, $sleeps);
                } else {
                    $kinds[] = 'awakeable';
                    $notifications[] = $random->getInt(0, 2) === 0
                        ? FrameCodec::signalFrame(17 + $awakeables++, failure: ['code' => 409, 'message' => 'no'])
                        : FrameCodec::signalFrame(17 + $awakeables++, value: '"yes"');
                }
            }
            $arriving = $random->getInt(1, $count);
            $journal = [...$journal, ...array_values(array_slice($random->shuffleArray($notifications), 0, $arriving))];
            $invocation = self::running($journal, contentType: [self::V5, self::V6, self::V7][$seed % 3]);
            $handles = array_map(static fn (string $kind): int => $kind === 'sleep' ? $invocation->sysSleep(1) : $invocation->sysAwakeable()->handle, $kinds);

            $taken = [];
            for ($round = 0; $round < 6; $round++) {
                $open = array_values(array_diff($handles, $taken));
                if ($open === []) {
                    break;
                }
                $tree = self::randomTree($random, $open, 0);
                $result = $invocation->doAwaitTree($tree);
                if ($result->state !== AwaitState::Completed) {
                    self::assertSame(AwaitState::Suspended, $result->state, "seed {$seed}");
                    break;
                }

                $completedAwaits++;
                $expected = array_values(array_filter(self::leaves($tree), $invocation->isCompleted(...)));
                self::assertSame($expected, $result->completedHandles, "seed {$seed}, round {$round}");
                self::assertNotSame([], $expected, "seed {$seed}: a completed await completed some handle");
                foreach ($result->completedHandles as $handle) {
                    if (!in_array($handle, $taken, true) && $random->getInt(0, 1) === 0) {
                        $invocation->takeNotificationResult($handle);
                        $taken[] = $handle;
                    }
                }
            }
        }

        self::assertGreaterThan(self::TREES, $completedAwaits);
    }

    /**
     * @param \Closure(Invocation): (FutureCombinators|ReferenceFutureCombinators) $combinators
     *
     * @return array{0: list<mixed>, 1: string} what the handler observed, and what the VM wrote
     */
    private static function play(CombinatorScenario $scenario, string $contentType, \Closure $combinators): array
    {
        // Commands in the order the handler issues them, then the results in the order they arrived.
        $journal = [];
        $notifications = [];
        $sleepIds = [];
        $signalIds = [];
        foreach ($scenario->kinds as $index => $kind) {
            if ($kind === 'sleep') {
                $sleepIds[$index] = count($sleepIds) + 1;
                $journal[] = FrameCodec::sleepCommandFrame(1, $sleepIds[$index]);
            } else {
                $signalIds[$index] = 17 + count($signalIds);
            }
        }
        foreach ($scenario->arrivals as [$index, $succeeds]) {
            $notifications[] = match (true) {
                $scenario->kinds[$index] === 'sleep' => FrameCodec::completionFrame(FrameCodec::SLEEP_COMPLETION, $sleepIds[$index]),
                $succeeds => FrameCodec::signalFrame($signalIds[$index], value: "\"v{$index}\""),
                default => FrameCodec::signalFrame($signalIds[$index], failure: ['code' => 409, 'message' => "failed {$index}"]),
            };
        }

        $invocation = self::running([...$journal, ...$notifications], contentType: $contentType);
        $futures = [];
        foreach ($scenario->kinds as $index => $kind) {
            $futures[$index] = $kind === 'sleep'
                ? DurableFutureImpl::voidResult($invocation, $invocation->sysSleep(1), 'Context::sleep()', "sleep {$index} suspended")
                : DurableFutureImpl::jsonResult($invocation, $invocation->sysAwakeable()->handle, 'Awakeable', "awakeable {$index} suspended");
        }
        $observed = $scenario->run($combinators($invocation), $futures);

        return [$observed, self::written($invocation)];
    }

    /** @param non-empty-list<int> $handles */
    private static function randomTree(Randomizer $random, array $handles, int $depth): AwaitTree
    {
        $children = [];
        foreach ($random->shuffleArray($random->pickArrayKeys($handles, $random->getInt(1, count($handles)))) as $index) {
            $children[] = $depth < 2 && $random->getInt(0, 4) === 0 ? self::randomTree($random, $handles, $depth + 1) : $handles[$index];
        }

        return new AwaitTree(AwaitCombinator::cases()[$random->getInt(0, 3)], $children);
    }

    /** @return list<int> */
    private static function leaves(int|AwaitTree $tree): array
    {
        return is_int($tree) ? [$tree] : array_merge(...array_map(self::leaves(...), $tree->children));
    }
}
