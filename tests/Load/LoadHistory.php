<?php

declare(strict_types=1);

namespace Restate\Tests\Load;

/**
 * Load results over time: the result files of earlier runs (one JSON file per run, under `<results dir>/<scenario>/`)
 * and optional pinned baselines (`tests/Load/baselines/<scenario>.json`, a result file copied there by hand; nothing
 * writes them and none is committed). A result is compared only with results of the same scenario, mode and endpoint
 * image on the same host: absolute numbers never compare across machines.
 *
 * - **Gate:** endpoint CPU per invocation, against the median of the last 5 comparable runs (catches a step) and,
 *   when there is one, against the pinned baseline (catches slow creep that a rolling median absorbs). More than +10%
 *   is a regression.
 * - **Hard failures** of the run itself: any 401 from the endpoint (Restate signs every request), corrected attempts
 *   per invocation other than the scenario's (deterministic, so any change is a behaviour change), and, outside the
 *   overload scenario, failed k6 calls above 0.1% or arrivals k6 dropped above 1%.
 * - **Reported only:** latency, the time split, throughput, Restate's CPU.
 */
final class LoadHistory
{
    public const string GATE_METRIC = 'endpoint_cpu_us_per_invocation';

    public const float GATE_TOLERANCE = 0.10;

    public const int ROLLING_RUNS = 5;

    /** Metrics shown next to the gate in the summary, with their deltas. */
    private const array REPORTED = [
        'invocations_per_second',
        'endpoint_cpu_us_per_attempt',
        'restate_cpu_us_per_invocation',
        'attempts_per_invocation',
        'corrected_attempts_per_invocation',
        'endpoint_status_401',
        'run_duplicates',
    ];

    private function __construct()
    {
    }

    /** @param list<int|float> $values */
    public static function median(array $values): int|float|null
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * The hard failures of a result, empty when it passes.
     *
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    public static function failures(array $result): array
    {
        $failures = [];
        $repetitions = is_array($result['repetitions'] ?? null) ? $result['repetitions'] : [];
        foreach ($repetitions as $index => $metrics) {
            if (!is_array($metrics)) {
                continue;
            }
            $repetition = (int) $index + 1;
            if (($metrics['endpoint_status_401'] ?? 0) !== 0) {
                $failures[] = sprintf('repetition %d: the endpoint answered %s requests with 401 (request identity)', $repetition, self::show($metrics['endpoint_status_401']));
            }
            $expected = $metrics['expected_attempts_per_invocation'] ?? null;
            $corrected = $metrics['corrected_attempts_per_invocation'] ?? null;
            if (is_int($expected) && is_numeric($corrected) && abs((float) $corrected - $expected) > 0.001) {
                $failures[] = sprintf('repetition %d: %s attempts per invocation after subtracting retries, expected %d', $repetition, self::show($corrected), $expected);
            }
            if (($metrics['invocations'] ?? 0) === 0) {
                $failures[] = sprintf('repetition %d: no invocation completed', $repetition);
            }
            $k6 = is_array($metrics['k6'] ?? null) ? $metrics['k6'] : [];
            $requests = is_int($k6['requests'] ?? null) ? $k6['requests'] : 0;
            $failed = (is_int($k6['failed'] ?? null) ? $k6['failed'] : 0) + (is_int($k6['timed_out'] ?? null) ? $k6['timed_out'] : 0);
            if (!isset($metrics['overload']) && $requests > 0 && $failed / $requests > 0.001) {
                $failures[] = sprintf('repetition %d: %d of %d calls failed or timed out', $repetition, $failed, $requests);
            }
            // An arrival k6 had no free VU for is dropped: the run did not hold the arrival rate it reports.
            $dropped = is_int($k6['dropped'] ?? null) ? $k6['dropped'] : 0;
            if (!isset($metrics['overload']) && $dropped > 0.01 * max(1, $requests + $dropped)) {
                $failures[] = sprintf('repetition %d: k6 dropped %d of %d arrivals (not enough VUs for the latency)', $repetition, $dropped, $requests + $dropped);
            }
        }

        return $failures;
    }

    /**
     * Compares a result with the comparable earlier ones and the pinned baseline.
     *
     * @param array<string, mixed> $result
     * @param list<array<string, mixed>> $history earlier results, oldest first
     * @param ?array<string, mixed> $baseline
     *
     * @return array{regressions: list<string>, rows: list<array{metric: string, value: mixed, rolling: mixed, rolling_delta: ?float, baseline: mixed, baseline_delta: ?float}>, rolling_runs: int, baseline: ?string}
     */
    public static function compare(array $result, array $history, ?array $baseline): array
    {
        $comparable = array_values(array_filter($history, static fn (array $earlier): bool => self::comparable($result, $earlier)));
        $recent = array_slice($comparable, -self::ROLLING_RUNS);
        $baseline = $baseline !== null && self::comparable($result, $baseline) ? $baseline : null;

        $rows = [];
        $regressions = [];
        foreach ([self::GATE_METRIC, ...self::REPORTED] as $metric) {
            $value = self::metric($result, $metric);
            $rollingValues = array_values(array_filter(array_map(static fn (array $earlier): int|float|null => self::metric($earlier, $metric), $recent), static fn (int|float|null $value): bool => $value !== null));
            $rolling = self::median($rollingValues);
            $pinned = $baseline === null ? null : self::metric($baseline, $metric);
            $row = [
                'metric' => $metric,
                'value' => $value,
                'rolling' => $rolling,
                'rolling_delta' => self::delta($value, $rolling),
                'baseline' => $pinned,
                'baseline_delta' => self::delta($value, $pinned),
            ];
            $rows[] = $row;
            if ($metric !== self::GATE_METRIC) {
                continue;
            }
            foreach (['rolling median' => $row['rolling_delta'], 'pinned baseline' => $row['baseline_delta']] as $against => $delta) {
                if ($delta !== null && $delta > self::GATE_TOLERANCE) {
                    $regressions[] = sprintf('%s is %+.1f%% against the %s (limit +%d%%)', $metric, $delta * 100, $against, (int) (self::GATE_TOLERANCE * 100));
                }
            }
        }

        $baselineName = null;
        if ($baseline !== null) {
            $baselineName = is_string($baseline['release'] ?? null) ? $baseline['release'] : (is_string($baseline['commit'] ?? null) ? $baseline['commit'] : 'pinned');
        }

        return ['regressions' => $regressions, 'rows' => $rows, 'rolling_runs' => count($recent), 'baseline' => $baselineName];
    }

    /**
     * A Markdown summary of a result and its comparison (stdout and the GitHub job summary).
     *
     * @param array<string, mixed> $result
     * @param array{regressions: list<string>, rows: list<array{metric: string, value: mixed, rolling: mixed, rolling_delta: ?float, baseline: mixed, baseline_delta: ?float}>, rolling_runs: int, baseline: ?string} $comparison
     * @param list<string> $failures
     */
    public static function markdown(array $result, array $comparison, array $failures): string
    {
        $metrics = is_array($result['metrics'] ?? null) ? $result['metrics'] : [];
        $lines = [
            sprintf('### Load test `%s` (%s)', self::show($result['scenario'] ?? '?'), self::show($result['mode'] ?? '?')),
            '',
            self::show($result['description'] ?? ''),
            '',
            sprintf(
                'Median of %d repetition(s); rolling median of %d earlier comparable run(s); pinned baseline: %s.',
                is_array($result['repetitions'] ?? null) ? count($result['repetitions']) : 0,
                $comparison['rolling_runs'],
                $comparison['baseline'] ?? 'none',
            ),
            '',
            '| Metric | Value | Rolling median | Δ | Baseline | Δ |',
            '| --- | --- | --- | --- | --- | --- |',
        ];
        foreach ($comparison['rows'] as $row) {
            $lines[] = sprintf(
                '| %s%s | %s | %s | %s | %s | %s |',
                $row['metric'],
                $row['metric'] === self::GATE_METRIC ? ' (gate)' : '',
                self::show($row['value']),
                self::show($row['rolling']),
                $row['rolling_delta'] === null ? '' : sprintf('%+.1f%%', $row['rolling_delta'] * 100),
                self::show($row['baseline']),
                $row['baseline_delta'] === null ? '' : sprintf('%+.1f%%', $row['baseline_delta'] * 100),
            );
        }
        $repetitions = is_array($result['repetitions'] ?? null) ? $result['repetitions'] : [];
        $first = is_array($repetitions[0] ?? null) ? $repetitions[0] : [];
        $latency = is_array($first['latency_ms'] ?? null) ? $first['latency_ms'] : [];
        $all = is_array($latency['all'] ?? null) ? $latency['all'] : [];
        $split = is_array($first['time_split_ms'] ?? null) ? $first['time_split_ms'] : [];
        $lines[] = '';
        $lines[] = sprintf('Latency (first repetition, reported only): p50 %s ms, p90 %s ms, p99 %s ms.', self::show($all['p50'] ?? null), self::show($all['p90'] ?? null), self::show($all['p99'] ?? null));
        $parts = [];
        foreach (['total', 'sdk', 'restate', 'queued'] as $part) {
            $values = is_array($split[$part] ?? null) ? $split[$part] : [];
            $parts[] = sprintf('%s p50 %s / p99 %s', $part, self::show($values['p50'] ?? null), self::show($values['p99'] ?? null));
        }
        $lines[] = 'Time split per invocation in ms (sys_invocation timestamps, endpoint request log): ' . implode('; ', $parts) . '.';
        if (is_array($first['overload'] ?? null)) {
            $overload = $first['overload'];
            $lines[] = sprintf(
                'Overload: %s calls timed out while queued, %s duplicated run executions, peak %s invocations in flight (nominal %s), recovered %s s after the overload phase.',
                self::show(is_array($first['k6'] ?? null) ? ($first['k6']['timed_out'] ?? null) : null),
                self::show($first['run_duplicates'] ?? null),
                self::show($overload['peak_in_flight'] ?? null),
                self::show($overload['nominal_in_flight'] ?? null),
                self::show($overload['recovery_seconds'] ?? 'never'),
            );
        }
        unset($metrics);
        foreach ($failures as $failure) {
            $lines[] = "- **Failure:** {$failure}";
        }
        foreach ($comparison['regressions'] as $regression) {
            $lines[] = "- **Regression:** {$regression}";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The earlier results in a scenario's results directory, oldest first (file names start with a UTC timestamp).
     *
     * @return list<array<string, mixed>>
     */
    public static function load(string $directory, ?string $except = null): array
    {
        $files = glob(rtrim($directory, '/') . '/*.json');
        $files = $files === false ? [] : $files;
        sort($files);
        $results = [];
        foreach ($files as $file) {
            if ($except !== null && realpath($file) === realpath($except)) {
                continue;
            }
            $result = self::read($file);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /** @return ?array<string, mixed> */
    public static function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        /** @var ?array<string, mixed> */
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function comparable(array $a, array $b): bool
    {
        $endpoint = static fn (array $result): mixed => is_array($result['endpoint'] ?? null) ? ($result['endpoint']['image'] ?? null) : null;
        $host = static fn (array $result): mixed => is_array($result['host'] ?? null) ? ($result['host']['fingerprint'] ?? null) : null;

        return ($a['scenario'] ?? null) === ($b['scenario'] ?? null)
            && ($a['mode'] ?? null) === ($b['mode'] ?? null)
            && $endpoint($a) === $endpoint($b)
            && $host($a) !== null && $host($a) === $host($b);
    }

    /** @param array<string, mixed> $result */
    private static function metric(array $result, string $metric): int|float|null
    {
        $value = is_array($result['metrics'] ?? null) ? ($result['metrics'][$metric] ?? null) : null;

        return is_int($value) || is_float($value) ? $value : null;
    }

    private static function delta(mixed $value, mixed $reference): ?float
    {
        if ((!is_int($value) && !is_float($value)) || (!is_int($reference) && !is_float($reference)) || (float) $reference === 0.0) {
            return null;
        }

        return ($value - $reference) / $reference;
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null => '–',
            is_float($value) => (string) round($value, 2),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
