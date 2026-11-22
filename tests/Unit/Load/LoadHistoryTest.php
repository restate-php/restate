<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Load;

use PHPUnit\Framework\TestCase;
use Restate\Tests\Load\LoadHistory;
use Restate\Tests\Load\LoadScenario;
use Restate\Tests\Load\Support\EffectsLog;

/** The load tests' result logic (tests/Load), without running a load test. */
final class LoadHistoryTest extends TestCase
{
    public function testTheMedianOfAnOddAndAnEvenNumberOfValues(): void
    {
        self::assertSame(3, LoadHistory::median([5, 1, 3]));
        self::assertSame(2.5, LoadHistory::median([4, 1, 3, 2]));
        self::assertNull(LoadHistory::median([]));
    }

    public function testCpuPerInvocationRegressesAgainstTheRollingMedianOfTheLastFiveComparableRuns(): void
    {
        // The oldest run (2000 µs) falls out of the window; an unrelated host and another scenario never count.
        $history = [
            self::loadResult(2000),
            ...array_map(self::loadResult(...), [100, 100, 104, 96, 100]),
            self::loadResult(50, host: 'other'),
            self::loadResult(50, scenario: 'runs'),
        ];

        $within = LoadHistory::compare(self::loadResult(109), $history, null);
        self::assertSame([], $within['regressions']);
        self::assertSame(5, $within['rolling_runs']);
        self::assertSame(100, $within['rows'][0]['rolling']);

        $regressed = LoadHistory::compare(self::loadResult(111), $history, null);
        self::assertCount(1, $regressed['regressions']);
        self::assertStringContainsString('rolling median', $regressed['regressions'][0]);
    }

    public function testCpuPerInvocationRegressesAgainstThePinnedBaselineToo(): void
    {
        $baseline = ['release' => '0.1.0', ...self::loadResult(80)];

        $comparison = LoadHistory::compare(self::loadResult(100), [self::loadResult(100)], $baseline);

        self::assertSame('0.1.0', $comparison['baseline']);
        self::assertCount(1, $comparison['regressions']);
        self::assertStringContainsString('pinned baseline', $comparison['regressions'][0]);
        self::assertSame([], LoadHistory::compare(self::loadResult(100), [], self::loadResult(80, host: 'other'))['regressions']);
    }

    public function testA401UnexpectedAttemptsAndFailedCallsAreHardFailures(): void
    {
        self::assertSame([], LoadHistory::failures(self::loadResult(100)));

        $failures = LoadHistory::failures(self::loadResult(100, repetition: [
            'endpoint_status_401' => 2,
            'corrected_attempts_per_invocation' => 1.5,
            'k6' => ['requests' => 100, 'failed' => 1, 'timed_out' => 0],
        ]));

        self::assertCount(3, $failures);
        self::assertStringContainsString('401', $failures[0]);
        self::assertStringContainsString('expected 1', $failures[1]);
        self::assertStringContainsString('1 of 100 calls', $failures[2]);
    }

    public function testOverloadRunsMayTimeOutWithoutFailing(): void
    {
        $failures = LoadHistory::failures(self::loadResult(100, repetition: [
            'expected_attempts_per_invocation' => null,
            'k6' => ['requests' => 100, 'failed' => 0, 'timed_out' => 40],
            'overload' => ['recovery_seconds' => 3.5],
        ]));

        self::assertSame([], $failures);
    }

    public function testTheEffectsLedgerCountsExecutionsBeyondTheFirst(): void
    {
        self::assertSame(['executions' => 4, 'duplicates' => 1], EffectsLog::count(['inv_a 1', 'inv_a 2', 'inv_a 1', 'inv_b 1', '']));
    }

    public function testEveryScenarioHasPhasesForBothModes(): void
    {
        foreach (LoadScenario::all() as $name => $scenario) {
            self::assertSame($name, $scenario->name);
            self::assertNotSame([], $scenario->phases(true), $name);
            self::assertNotSame([], $scenario->phases(false), $name);
            self::assertContains($scenario->service, $scenario->services, $name);
            if ($scenario->overload) {
                self::assertCount(3, $scenario->phases(true), $name);
            }
        }
    }

    /**
     * @param array<string, mixed> $repetition
     *
     * @return array<string, mixed>
     */
    private static function loadResult(int|float $cpu, string $host = 'here', string $scenario = 'noop', array $repetition = []): array
    {
        return [
            'scenario' => $scenario,
            'mode' => 'quick',
            'host' => ['fingerprint' => $host],
            'endpoint' => ['image' => 'image'],
            'metrics' => ['endpoint_cpu_us_per_invocation' => $cpu, 'invocations_per_second' => 100.0],
            'repetitions' => [[
                'invocations' => 100,
                'endpoint_status_401' => 0,
                'expected_attempts_per_invocation' => 1,
                'corrected_attempts_per_invocation' => 1,
                'k6' => ['requests' => 100, 'failed' => 0, 'timed_out' => 0],
                ...$repetition,
            ]],
        ];
    }
}
