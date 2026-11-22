<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark\Tooling;

/**
 * Compares a base and a head revision from two pairs of phpbench runs made in the same job in reverse order (base,
 * head, head, base), plus optional callgrind instruction counts. Only variants present in every run are compared, so a
 * benchmark that head adds, renames or removes never breaks the comparison; they are listed as such.
 *
 * A variant regresses when head is slower than base by more than the time threshold in both pairs and no run of it
 * was noisier than the rstdev limit (a noisy variant is reported, never failed); its peak memory regresses when it
 * grows by more than the memory threshold in both pairs. An instruction count regresses when it grows by more than the
 * callgrind threshold (counts are near-deterministic, one run per side is enough).
 */
final readonly class BenchComparison
{
    /**
     * @param array<string, array{base: float, head: float, delta1: float, delta2: float, rstdev: float, memDelta1: float, memDelta2: float, verdict: string}> $rows
     * @param list<string> $added
     * @param list<string> $removed
     * @param array<string, array{base: float, head: float, delta: float, verdict: string}> $instructions
     */
    private function __construct(
        public array $rows,
        public array $added,
        public array $removed,
        public array $instructions,
        public float $timeThreshold,
        public float $rstdevLimit,
        public float $memoryThreshold,
        public float $callgrindThreshold,
    ) {
    }

    /**
     * @param array<string, float> $baseInstructions subject => instructions per revolution
     * @param array<string, float> $headInstructions
     */
    public static function compare(
        PhpbenchDump $base1,
        PhpbenchDump $head1,
        PhpbenchDump $head2,
        PhpbenchDump $base2,
        array $baseInstructions = [],
        array $headInstructions = [],
        float $timeThreshold = 20.0,
        float $rstdevLimit = 10.0,
        float $memoryThreshold = 10.0,
        float $callgrindThreshold = 3.0,
    ): self {
        $baseKeys = array_keys(array_intersect_key($base1->variants, $base2->variants));
        $headKeys = array_keys(array_intersect_key($head1->variants, $head2->variants));

        $rows = [];
        foreach (array_intersect($headKeys, $baseKeys) as $key) {
            [$b1, $h1, $h2, $b2] = [$base1->variants[$key], $head1->variants[$key], $head2->variants[$key], $base2->variants[$key]];
            $delta1 = self::delta($b1->median, $h1->median);
            $delta2 = self::delta($b2->median, $h2->median);
            $memDelta1 = self::delta($b1->memoryPeak, $h1->memoryPeak);
            $memDelta2 = self::delta($b2->memoryPeak, $h2->memoryPeak);
            $rstdev = max($b1->rstdev, $h1->rstdev, $h2->rstdev, $b2->rstdev);

            $verdict = match (true) {
                min($memDelta1, $memDelta2) > $memoryThreshold => 'memory regression',
                $rstdev > $rstdevLimit => 'noisy',
                min($delta1, $delta2) > $timeThreshold => 'regression',
                max($delta1, $delta2) < -$timeThreshold => 'improvement',
                default => 'same',
            };
            $rows[$key] = [
                'base' => ($b1->median + $b2->median) / 2,
                'head' => ($h1->median + $h2->median) / 2,
                'delta1' => $delta1,
                'delta2' => $delta2,
                'rstdev' => $rstdev,
                'memDelta1' => $memDelta1,
                'memDelta2' => $memDelta2,
                'verdict' => $verdict,
            ];
        }

        $instructions = [];
        foreach (array_intersect_key($headInstructions, $baseInstructions) as $subject => $head) {
            $delta = self::delta($baseInstructions[$subject], $head);
            $instructions[$subject] = [
                'base' => $baseInstructions[$subject],
                'head' => $head,
                'delta' => $delta,
                'verdict' => match (true) {
                    $delta > $callgrindThreshold => 'regression',
                    $delta < -$callgrindThreshold => 'improvement',
                    default => 'same',
                },
            ];
        }

        return new self(
            $rows,
            array_values(array_diff($headKeys, $baseKeys)),
            array_values(array_diff($baseKeys, $headKeys)),
            $instructions,
            $timeThreshold,
            $rstdevLimit,
            $memoryThreshold,
            $callgrindThreshold,
        );
    }

    /** @return list<string> the compared variants and instruction counts that regressed */
    public function regressions(): array
    {
        $regressed = [];
        foreach ($this->rows as $key => $row) {
            if (str_ends_with($row['verdict'], 'regression')) {
                $regressed[] = $key;
            }
        }
        foreach ($this->instructions as $subject => $row) {
            if ($row['verdict'] === 'regression') {
                $regressed[] = "instructions: {$subject}";
            }
        }

        return $regressed;
    }

    public function markdown(): string
    {
        $lines = [
            '## PHP benchmarks: head vs base',
            '',
            sprintf(
                'Median time per revolution, two pairs run in reverse order (base, head, head, base). A regression is slower by more than %s%% in both pairs with every run under %s%% rstdev, or peak memory up more than %s%% in both.',
                self::number($this->timeThreshold),
                self::number($this->rstdevLimit),
                self::number($this->memoryThreshold),
            ),
            '',
            '| Benchmark | Base (µs) | Head (µs) | Δ pair 1 | Δ pair 2 | max rstdev | Δ memory | Verdict |',
            '| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |',
        ];
        foreach ($this->rows as $key => $row) {
            $lines[] = sprintf(
                '| `%s` | %s | %s | %s | %s | %s%% | %s | %s |',
                $key,
                self::number($row['base']),
                self::number($row['head']),
                self::percent($row['delta1']),
                self::percent($row['delta2']),
                self::number($row['rstdev'], 1),
                self::percent(max($row['memDelta1'], $row['memDelta2'])),
                self::verdict($row['verdict']),
            );
        }
        if ($this->rows === []) {
            $lines[] = '| (no benchmark in both revisions) | | | | | | | |';
        }

        if ($this->instructions !== []) {
            $lines[] = '';
            $lines[] = sprintf('### Instructions per revolution (callgrind, threshold %s%%)', self::number($this->callgrindThreshold));
            $lines[] = '';
            $lines[] = '| Subject | Base | Head | Δ | Verdict |';
            $lines[] = '| --- | ---: | ---: | ---: | --- |';
            foreach ($this->instructions as $subject => $row) {
                $lines[] = sprintf('| `%s` | %s | %s | %s | %s |', $subject, self::number($row['base'], 0), self::number($row['head'], 0), self::percent($row['delta']), self::verdict($row['verdict']));
            }
        }

        foreach (['New in head (not compared)' => $this->added, 'Only in base (removed or renamed)' => $this->removed] as $title => $keys) {
            if ($keys !== []) {
                $lines[] = '';
                $lines[] = "**{$title}:** " . implode(', ', array_map(static fn (string $key): string => "`{$key}`", $keys));
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'thresholds' => ['time' => $this->timeThreshold, 'rstdev' => $this->rstdevLimit, 'memory' => $this->memoryThreshold, 'callgrind' => $this->callgrindThreshold],
            'variants' => $this->rows,
            'instructions' => $this->instructions,
            'added' => $this->added,
            'removed' => $this->removed,
            'regressions' => $this->regressions(),
        ];
    }

    /** Relative change from $base to $head, in percent. */
    private static function delta(float|int $base, float|int $head): float
    {
        return $base <= 0 ? 0.0 : ($head - $base) / $base * 100;
    }

    private static function number(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, '.', ',');
    }

    private static function percent(float $value): string
    {
        return sprintf('%+.1f%%', $value);
    }

    private static function verdict(string $verdict): string
    {
        return str_ends_with($verdict, 'regression') ? "**{$verdict}**" : $verdict;
    }
}
