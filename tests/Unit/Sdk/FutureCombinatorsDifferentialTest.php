<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\DurableFutureAggregateException;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\Context\DurableFutureImpl;
use Restate\Internal\Context\FutureCombinators;
use Restate\Internal\ResultDecoder;
use Restate\Serde;
use Restate\Tests\Support\CombinatorScenario;
use Restate\Tests\Support\ReferenceFutureCombinators;
use Restate\Tests\Support\ScriptedInvocation;

/**
 * {@see FutureCombinators} reads which futures a tree await completed from the await's result (one backend call);
 * {@see ReferenceFutureCombinators}, the straightforward oracle, asks isCompleted() of each future after every await.
 * On random scripted journals both must observe the same keys, values and failures in the same order, and make the
 * same backend calls but those isCompleted() calls, with the same results. tests/Extension/FutureCombinatorsReplayTest.php
 * does the same on the real VM.
 */
final class FutureCombinatorsDifferentialTest extends TestCase
{
    private const int SCENARIOS = 3000;

    public function testRandomScenariosObserveAndCallTheSameAsTheReference(): void
    {
        $treeAwaits = 0;
        for ($seed = 1; $seed <= self::SCENARIOS; $seed++) {
            $scenario = CombinatorScenario::random($seed);
            [$reference, $referenceObserved] = self::play($scenario, static fn (ScriptedInvocation $scripted): ReferenceFutureCombinators => new ReferenceFutureCombinators($scripted));
            [$production, $productionObserved] = self::play($scenario, static fn (ScriptedInvocation $scripted): FutureCombinators => new FutureCombinators($scripted));

            self::assertSame($referenceObserved, $productionObserved, "seed {$seed}: observed");
            self::assertEquals(self::withoutIsCompleted($reference), $production->journal(), "seed {$seed}: backend calls");
            self::assertNotContains('isCompleted', $production->operations(), "seed {$seed}");
            $treeAwaits += count($production->calls('doAwaitTree'));
        }

        self::assertGreaterThan(self::SCENARIOS, $treeAwaits, 'the scenarios await trees');
    }

    /**
     * any() takes futures that code run by a result's deserializer resolved before it awaits again, in key order, as
     * the reference's fresh scan per round does.
     */
    public function testAnyTakesFuturesADeserializerResolvedFirst(): void
    {
        $observed = [];
        foreach (['reference', 'production'] as $implementation) {
            $scripted = ScriptedInvocation::new();
            $combinators = $implementation === 'reference' ? new ReferenceFutureCombinators($scripted) : new FutureCombinators($scripted);
            $scripted->complete(1, NotificationResult::success('"first"'))
                ->complete(3, NotificationResult::failure(409, 'third'))
                ->complete(2, NotificationResult::failure(409, 'second'));
            $third = DurableFutureImpl::jsonResult($scripted, 3, 'third', 'suspended');
            $second = DurableFutureImpl::jsonResult($scripted, 2, 'second', 'suspended');
            $first = DurableFutureImpl::of($scripted, 1, ResultDecoder::serde('first', new AwaitingSerde($third)), 'suspended');

            try {
                $combinators->any(['a' => $first, 'b' => $second, 'c' => $third]);
                self::fail("{$implementation}: any() succeeded.");
            } catch (DurableFutureAggregateException $e) {
                $observed[$implementation] = [
                    array_map(static fn (\Throwable $failure): string => $failure->getMessage(), $e->failures()),
                    array_values(array_diff($scripted->operations(), ['isCompleted'])),
                ];
            }
        }

        // 'a' fails to decode after taking 'c', which is then taken before the tree over 'b' alone is awaited.
        self::assertSame(['a' => 'cannot decode', 'c' => 'third', 'b' => 'second'], $observed['reference'][0]);
        self::assertSame($observed['reference'], $observed['production']);
    }

    /**
     * @template T of FutureCombinators|ReferenceFutureCombinators
     *
     * @param \Closure(ScriptedInvocation): T $combinators
     *
     * @return array{0: ScriptedInvocation, 1: list<mixed>}
     */
    private static function play(CombinatorScenario $scenario, \Closure $combinators): array
    {
        $scripted = ScriptedInvocation::new();
        foreach ($scenario->arrivals as [$index, $succeeds]) {
            $scripted->complete($index + 1, match (true) {
                $scenario->kinds[$index] === 'sleep' => NotificationResult::void(),
                $succeeds => NotificationResult::success("\"v{$index}\""),
                default => NotificationResult::failure(409, "failed {$index}"),
            });
        }
        $futures = [];
        foreach ($scenario->kinds as $index => $kind) {
            $futures[$index] = $kind === 'sleep'
                ? DurableFutureImpl::voidResult($scripted, $index + 1, 'Context::sleep()', "sleep {$index} suspended")
                : DurableFutureImpl::jsonResult($scripted, $index + 1, 'Awakeable', "awakeable {$index} suspended");
        }

        return [$scripted, $scenario->run($combinators($scripted), $futures)];
    }

    /** @return list<array{0: string, 1: list<mixed>, 2: mixed}> */
    private static function withoutIsCompleted(ScriptedInvocation $scripted): array
    {
        return array_values(array_filter($scripted->journal(), static fn (array $entry): bool => $entry[0] !== 'isCompleted'));
    }
}

/** Deserializes by awaiting another future first: code the combinator runs while it awaits. */
final readonly class AwaitingSerde implements Serde
{
    public function __construct(private DurableFutureImpl $other)
    {
    }

    public function serialize(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function deserialize(string $bytes): mixed
    {
        try {
            $this->other->awaitResult();
        } catch (\Throwable) {
        }

        throw new \RuntimeException('cannot decode');
    }

    public function contentType(): string
    {
        return 'application/json';
    }
}
