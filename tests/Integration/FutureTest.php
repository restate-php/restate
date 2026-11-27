<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Restate\Tests\Integration\Support\IntegrationTestCase;

final class FutureTest extends IntegrationTestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function combinators(): iterable
    {
        yield 'select: call wins' => ['Communicator/selectCallWins', '"Future"', '{"winner":"call","value":"Hello Future"}'];
        yield 'race: call wins' => ['Communicator/futureRaceCallWins', '"Future"', '{"value":"Hello Future"}'];
        yield 'select: sleep wins' => ['Communicator/selectSleepWins', 'null', '{"winner":"fast"}'];
        yield 'unordered sleeps' => [
            'Communicator/unorderedSleeps',
            'null',
            '[{"index":1,"value":null},{"index":2,"value":null},{"index":0,"value":null},null]',
        ];
        yield 'unordered sleeps from collection' => [
            'Communicator/unorderedSleepsFromCollection',
            'null',
            '[{"index":1,"value":null},{"index":2,"value":null},{"index":0,"value":null},null]',
        ];
    }

    #[DataProvider('combinators')]
    public function testCombinator(string $path, string $payload, string $expected): void
    {
        self::assertInvocationReturns($expected, $path, $payload, timeout: 20.0);
    }

    /**
     * Awakeables completed by delayed sends, so the invocation suspends and Restate resumes it once the tree it awaits
     * can make progress. An outcome without `ok` or `fail` never completes. Restate fires delayed
     * sends on a tick of about a second, so outcomes whose order matters are more than a second apart.
     *
     * @return iterable<string, array{string, array<string, array{delay_ms?: int, ok?: string, fail?: string}>, string}>
     */
    public static function awakeableCombinators(): iterable
    {
        yield 'all: every future succeeds' => [
            'all',
            ['a' => ['delay_ms' => 600, 'ok' => 'A'], 'b' => ['delay_ms' => 100, 'ok' => 'B'], 'c' => ['delay_ms' => 300, 'ok' => 'C']],
            '{"value":{"a":"A","b":"B","c":"C"}}',
        ];
        yield 'all: the first failure ends it while a future never completes' => [
            'all',
            ['a' => ['delay_ms' => 100, 'ok' => 'A'], 'b' => ['delay_ms' => 300, 'fail' => 'b failed'], 'c' => []],
            '{"failure":"b failed"}',
        ];
        yield 'all: the failure that completes first is thrown' => [
            'all',
            ['a' => ['delay_ms' => 1500, 'fail' => 'a failed'], 'b' => ['delay_ms' => 100, 'fail' => 'b failed']],
            '{"failure":"b failed"}',
        ];
        yield 'race: the first to complete wins, even a failure' => [
            'race',
            ['a' => ['delay_ms' => 1500, 'ok' => 'A'], 'b' => ['delay_ms' => 100, 'fail' => 'b failed']],
            '{"failure":"b failed"}',
        ];
        yield 'race: a success wins while a future never completes' => [
            'race',
            ['a' => [], 'b' => ['delay_ms' => 100, 'ok' => 'B']],
            '{"value":"B"}',
        ];
        yield 'any: failures are skipped until a success' => [
            'any',
            ['a' => ['delay_ms' => 100, 'fail' => 'a failed'], 'b' => ['delay_ms' => 300, 'ok' => 'B'], 'c' => []],
            '{"value":"B"}',
        ];
        yield 'any: every future fails, failures in completion order' => [
            'any',
            ['a' => ['delay_ms' => 1500, 'fail' => 'a failed'], 'b' => ['delay_ms' => 100, 'fail' => 'b failed']],
            '{"failures":{"b":"b failed","a":"a failed"}}',
        ];
        yield 'allSettled: every outcome in key order' => [
            'allSettled',
            ['a' => ['delay_ms' => 600, 'ok' => 'A'], 'b' => ['delay_ms' => 100, 'fail' => 'b failed'], 'c' => ['delay_ms' => 300, 'ok' => 'C']],
            '{"value":{"a":{"fulfilled":"A"},"b":{"rejected":"b failed"},"c":{"fulfilled":"C"}}}',
        ];
    }

    /** @param array<string, array{delay_ms?: int, ok?: string, fail?: string}> $outcomes */
    #[DataProvider('awakeableCombinators')]
    public function testDurableFutureCombinatorOverAwakeables(string $combinator, array $outcomes, string $expected): void
    {
        $payload = json_encode(['combinator' => $combinator, 'outcomes' => $outcomes], JSON_THROW_ON_ERROR);

        self::assertInvocationReturns($expected, 'Communicator/combineAwakeables', $payload, timeout: 20.0);
    }
}
