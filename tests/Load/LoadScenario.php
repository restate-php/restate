<?php

declare(strict_types=1);

namespace Restate\Tests\Load;

/**
 * One load-test scenario: what k6 calls ({@see Support\LoadServices}), at which arrival rates, and what the runner
 * may assert about it. Each has a quick shape (a local run of about a minute) and a full one (the dedicated runner).
 */
final readonly class LoadScenario
{
    /**
     * @param list<string> $services the services whose invocations the scenario creates (sys_invocation filter)
     * @param list<array{name: string, rate: int, duration: int}> $quickPhases consecutive k6 phases, rate per second,
     *                                                                       duration in seconds
     * @param list<array{name: string, rate: int, duration: int}> $fullPhases
     */
    public function __construct(
        public string $name,
        public string $description,
        /** Ingress path, `{key}` replaced by one of $keys virtual-object keys. */
        public string $path,
        public string $body,
        /** The service of the invocations k6 starts; the others are their children. */
        public string $service,
        public array $services,
        public array $quickPhases,
        public array $fullPhases,
        /** HTTP attempts per invocation of $service once retries are subtracted; null: not deterministic. */
        public ?int $expectedAttempts,
        public int $keys = 1000,
        public int $timeoutSeconds = 30,
        /** `--cpus` for the endpoint container, to cap its capacity; null: no limit. */
        public ?string $endpointCpus = null,
        /** FrankenPHP's PHP thread count (LOAD_NUM_THREADS); null: its default. */
        public ?int $endpointThreads = null,
        /** An overload run: phases nominal, overload and recovery; it measures recovery instead of asserting attempts. */
        public bool $overload = false,
    ) {
    }

    /** @return array<string, self> */
    public static function all(): array
    {
        $scenarios = [
            new self(
                'noop',
                'LoadNoop/noop: one attempt, nothing journaled; the per-request floor (bootstrap, VM, HTTP hop)',
                '/LoadNoop/noop',
                '"load"',
                'LoadNoop',
                ['LoadNoop'],
                [['name' => 'steady', 'rate' => 100, 'duration' => 20]],
                [['name' => 'steady', 'rate' => 400, 'duration' => 90]],
                expectedAttempts: 1,
            ),
            new self(
                'state',
                'LoadState/update on 200 keys: reads and writes 50 state keys of 256 bytes (eager state); one attempt',
                '/LoadState/{key}/update',
                '{"keys":50,"bytes":256}',
                'LoadState',
                ['LoadState'],
                [['name' => 'steady', 'rate' => 50, 'duration' => 20]],
                [['name' => 'steady', 'rate' => 200, 'duration' => 90]],
                expectedAttempts: 1,
                keys: 200,
            ),
            new self(
                'runs',
                'LoadRuns/runs with 5 runs of 16 bytes: 6 attempts per invocation, short journals',
                '/LoadRuns/runs',
                '{"runs":5,"bytes":16}',
                'LoadRuns',
                ['LoadRuns'],
                [['name' => 'steady', 'rate' => 30, 'duration' => 20]],
                [['name' => 'steady', 'rate' => 100, 'duration' => 90]],
                expectedAttempts: 6,
            ),
            new self(
                'fanout',
                'LoadFanOut/fanOut(10): 10 LoadNoop/noop calls awaited with all(); attempts depend on completion timing',
                '/LoadFanOut/fanOut',
                '10',
                'LoadFanOut',
                ['LoadFanOut', 'LoadNoop'],
                [['name' => 'steady', 'rate' => 10, 'duration' => 20]],
                [['name' => 'steady', 'rate' => 40, 'duration' => 90]],
                expectedAttempts: null,
            ),
            new self(
                'long-journal',
                'LoadRuns/runs with 50 runs of 1 KiB: 51 attempts, the last replaying ~100 entries and 50 KiB (O(n²) replay)',
                '/LoadRuns/runs',
                '{"runs":50,"bytes":1024}',
                'LoadRuns',
                ['LoadRuns'],
                [['name' => 'steady', 'rate' => 2, 'duration' => 20]],
                [['name' => 'steady', 'rate' => 5, 'duration' => 90]],
                expectedAttempts: 51,
                timeoutSeconds: 60,
            ),
            new self(
                'overload',
                'LoadRuns/runs(3) on an endpoint capped at 1 CPU and 4 PHP threads: nominal rate, then far above capacity, then nominal again; '
                . 'counts calls that time out while queued, duplicated run effects, and how long the backlog takes to drain',
                '/LoadRuns/runs',
                '{"runs":3,"bytes":16}',
                'LoadRuns',
                ['LoadRuns'],
                [
                    ['name' => 'nominal', 'rate' => 20, 'duration' => 10],
                    ['name' => 'overload', 'rate' => 400, 'duration' => 15],
                    ['name' => 'recovery', 'rate' => 20, 'duration' => 20],
                ],
                [
                    ['name' => 'nominal', 'rate' => 50, 'duration' => 60],
                    ['name' => 'overload', 'rate' => 1000, 'duration' => 60],
                    ['name' => 'recovery', 'rate' => 50, 'duration' => 120],
                ],
                expectedAttempts: null,
                timeoutSeconds: 5,
                endpointCpus: '1',
                endpointThreads: 4,
                overload: true,
            ),
        ];

        $byName = [];
        foreach ($scenarios as $scenario) {
            $byName[$scenario->name] = $scenario;
        }

        return $byName;
    }

    public static function named(string $name): self
    {
        return self::all()[$name] ?? throw new \InvalidArgumentException(sprintf('Unknown load scenario "%s"; one of: %s.', $name, implode(', ', array_keys(self::all()))));
    }

    /** @return list<array{name: string, rate: int, duration: int}> */
    public function phases(bool $quick): array
    {
        return $quick ? $this->quickPhases : $this->fullPhases;
    }
}
