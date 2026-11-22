<?php

declare(strict_types=1);

namespace Restate\Tests\Soak\Support;

/**
 * The worker's state sampled during a soak run, at checkpoints between batches of requests (no request in flight),
 * grouped by phase (one request class alone, or the mix), and the checks a clean run passes:
 *
 * - exact: no live VM at any checkpoint, the same number of live identity verifiers, open file descriptors within ±4
 *   of the first checkpoint, the same included files, the same worker script run (no restart) within a phase;
 * - growth: the Theil–Sen slope, after the phase's first quarter (warm-up), of the Rust heap (the counting allocator of
 *   the `diagnostics` build), of PHP's heap after `gc_collect_cycles()`, and of anonymous RSS, each per request,
 *   below a threshold.
 *
 * {@see failures()} names the phase, the counter and the measured value of every check that fails, so a planted leak
 * is pinned to the request class that leaks it.
 */
final class Checkpoints
{
    /** The phase that runs first and is not checked. */
    public const string WARM_UP = 'warm-up';

    /** @var list<array{phase: string, requests: int, phpBytes: int, rustBytes: int, liveVms: int, verifiers: int, rssAnonBytes: int, fds: int, includedFiles: int, instance: string}> */
    private array $samples = [];

    public function take(SoakEndpoint $endpoint, string $phase, int $requests): void
    {
        $stats = $endpoint->stats();
        $diagnostics = $stats['diagnostics'] ?? throw new \RuntimeException('The soak image has no diagnostics() (build it with the diagnostics feature: bin/test-soak.sh).');
        $this->samples[] = [
            'phase' => $phase,
            'requests' => $requests,
            'phpBytes' => $stats['memory']['usage'],
            'rustBytes' => $diagnostics['rust_live_bytes'],
            'liveVms' => $diagnostics['live_vms'],
            'verifiers' => $diagnostics['live_identity_verifiers'],
            'rssAnonBytes' => ($stats['process']['rssAnonKib'] ?? 0) * 1024,
            'fds' => $stats['process']['fds'] ?? 0,
            'includedFiles' => $stats['includedFiles'],
            'instance' => $stats['instance'],
        ];
    }

    /**
     * @param array{rust: ?float, php: ?float, rss: ?float} $maxSlopes bytes per request; a null threshold reports the
     *                                                              slope without gating on it
     *
     * @return list<string>
     */
    public function failures(array $maxSlopes, ?string $phase = null): array
    {
        $failures = [];
        // The warm-up phase loads classes and fills caches: it is neither checked nor the reference.
        $all = array_values(array_filter($this->samples, static fn (array $sample): bool => $sample['phase'] !== self::WARM_UP));
        if ($all === []) {
            return ['No checkpoint was taken after the warm-up.'];
        }
        $first = $all[0];
        foreach ($all as $sample) {
            if ($sample['liveVms'] !== 0) {
                $failures[] = "{$sample['phase']}: live_vms is {$sample['liveVms']} at request {$sample['requests']}, expected 0";
            }
            if ($sample['verifiers'] !== $first['verifiers']) {
                $failures[] = "{$sample['phase']}: live_identity_verifiers went from {$first['verifiers']} to {$sample['verifiers']} at request {$sample['requests']}";
            }
            if (abs($sample['fds'] - $first['fds']) > 4) {
                $failures[] = "{$sample['phase']}: open file descriptors went from {$first['fds']} to {$sample['fds']} at request {$sample['requests']}";
            }
        }

        foreach ($phase === null ? array_diff($this->phases(), [self::WARM_UP]) : [$phase] as $name) {
            $samples = $this->samples($name);
            if (count(array_unique(array_column($samples, 'instance'))) > 1) {
                $failures[] = "{$name}: the worker script restarted during the phase";
            }
            if (count(array_unique(array_column($samples, 'includedFiles'))) > 1) {
                $failures[] = "{$name}: included files changed: " . implode(', ', array_unique(array_column($samples, 'includedFiles')));
            }
            foreach (['rust' => 'rustBytes', 'php' => 'phpBytes', 'rss' => 'rssAnonBytes'] as $metric => $column) {
                $max = $maxSlopes[$metric];
                $slope = $this->slope($name, $column);
                if ($max !== null && $slope > $max) {
                    $failures[] = sprintf('%s: %s grows %.3f bytes per request (limit %.3f)', $name, $column, $slope, $max);
                }
            }
        }

        return $failures;
    }

    /** Bytes per request of one counter in one phase, after its first quarter. */
    public function slope(string $phase, string $column): float
    {
        $samples = $this->samples($phase);
        $samples = array_slice($samples, intdiv(count($samples), 4));

        /** @var list<array{0: int, 1: int}> $points */
        $points = array_map(static fn (array $sample): array => [$sample['requests'], $sample[$column]], $samples);

        return Slope::theilSen($points);
    }

    /** @return list<string> */
    public function phases(): array
    {
        return array_values(array_unique(array_column($this->samples, 'phase')));
    }

    /** @return list<array{phase: string, requests: int, phpBytes: int, rustBytes: int, liveVms: int, verifiers: int, rssAnonBytes: int, fds: int, includedFiles: int, instance: string}> */
    public function samples(string $phase): array
    {
        return array_values(array_filter($this->samples, static fn (array $sample): bool => $sample['phase'] === $phase));
    }

    /** One line per phase: the slopes, for the run's report. */
    public function summary(): string
    {
        $lines = [];
        foreach ($this->phases() as $phase) {
            $samples = $this->samples($phase);
            $last = $samples[count($samples) - 1];
            $lines[] = sprintf(
                '%-22s %8d requests  rust %+9.4f B/req  php %+9.4f B/req  rssAnon %+9.3f B/req  (last: rust %d B, php %d B, rssAnon %d KiB, fds %d)',
                $phase,
                $last['requests'] - $samples[0]['requests'],
                $this->slope($phase, 'rustBytes'),
                $this->slope($phase, 'phpBytes'),
                $this->slope($phase, 'rssAnonBytes'),
                $last['rustBytes'],
                $last['phpBytes'],
                intdiv($last['rssAnonBytes'], 1024),
                $last['fds'],
            );
        }

        return implode("\n", $lines);
    }

    public function writeCsv(string $file): void
    {
        $handle = fopen($file, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Cannot write {$file}.");
        }
        fputcsv($handle, ['phase', 'requests', 'phpBytes', 'rustBytes', 'liveVms', 'verifiers', 'rssAnonBytes', 'fds', 'includedFiles', 'instance'], escape: '');
        foreach ($this->samples as $sample) {
            fputcsv($handle, array_values($sample), escape: '');
        }
        fclose($handle);
    }
}
