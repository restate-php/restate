<?php

declare(strict_types=1);

namespace Restate\Tests\Load;

use Restate\Testing\EndpointContainer;
use Restate\Testing\Internal\Http;
use Restate\Testing\Internal\Process;
use Restate\Testing\RestateContainer;
use Restate\Testing\RestateTestEnvironment;
use Restate\Tests\Load\Support\EffectsLog;
use Restate\Tests\Support\Repository;
use Restate\Tests\Support\RequestIdentityTestSupport;

/**
 * Runs one {@see LoadScenario} against a fresh Restate (signing its requests) and the load endpoint container, with
 * k6 on the host, and returns the result as an array the runner writes as JSON.
 *
 * Per repetition: the endpoint's and Restate's cgroup CPU (`cpu.stat` `usage_usec`, read with `<runtime> exec`) before
 * and after, the k6 summary, the endpoint's request log (attempts per invocation id, statuses, SDK-side time), the
 * effects ledger (duplicated runs), and `sys_invocation` for the scenario's invocations (retry counts, timestamps).
 * The repetition ends when Restate has no invocation of the scenario left that is not completed.
 */
final class LoadRunner
{
    private const string PEM_IN_CONTAINER = '/etc/restate/request-identity.pem';

    private const string OUT_IN_CONTAINER = '/load-out';

    private const int WARMUP_SECONDS_QUICK = 5;

    private const int WARMUP_SECONDS_FULL = 30;

    private const float POLL_SECONDS = 0.5;

    private const int DRAIN_TIMEOUT_SECONDS = 300;

    private ?RestateTestEnvironment $environment = null;

    public function __construct(
        private readonly LoadScenario $scenario,
        private readonly bool $quick,
        private readonly int $repetitions,
        private readonly string $image,
        private readonly string $workDir,
        private readonly ?string $cpus = null,
        private readonly ?string $cpusetEndpoint = null,
        private readonly ?string $cpusetRestate = null,
        private readonly int $partitions = 4,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        if (!is_dir($this->workDir) && !mkdir($this->workDir, 0o777, true)) {
            throw new \RuntimeException("Could not create {$this->workDir}.");
        }
        // The endpoint container writes its logs here, as whatever user it runs as.
        chmod($this->workDir, 0o777);
        [$pem, $identityKey] = self::keyPair();
        $pemFile = $this->workDir . '/request-identity.pem';
        file_put_contents($pemFile, $pem);
        chmod($pemFile, 0o644);
        foreach (['requests.log', 'effects.log'] as $log) {
            touch("{$this->workDir}/{$log}");
            chmod("{$this->workDir}/{$log}", 0o666);
        }

        $environment = $this->environment = RestateTestEnvironment::start($this->endpoint($identityKey), $this->restate($pemFile));
        try {
            $this->log(sprintf('Restate %s at %s, endpoint %s', $environment->serverVersion(), $environment->ingressUrl(), $this->image));
            $first = $this->scenario->phases($this->quick)[0];
            $warmup = $this->quick ? self::WARMUP_SECONDS_QUICK : self::WARMUP_SECONDS_FULL;
            $this->log("Warm-up: {$warmup} s at {$first['rate']}/s");
            $this->k6([['name' => 'warmup', 'rate' => $first['rate'], 'duration' => $warmup]], "{$this->workDir}/k6-warmup.json");
            $this->waitUntilDrained();

            $repetitions = [];
            for ($repetition = 1; $repetition <= $this->repetitions; $repetition++) {
                $this->log("Repetition {$repetition}/{$this->repetitions}");
                $repetitions[] = $this->repetition($repetition);
            }

            $endpointCgroup = $this->cgroup($environment->endpointContainerName() ?? '');
            $endpointInfo = $this->endpointInfo();
        } finally {
            $environment->stop();
            $this->environment = null;
        }

        return [
            'scenario' => $this->scenario->name,
            'description' => $this->scenario->description,
            'mode' => $this->quick ? 'quick' : 'full',
            'endpoint' => [
                'image' => $this->image,
                'cpus' => $this->cpus ?? $this->scenario->endpointCpus,
                'threads' => $this->scenario->endpointThreads,
                'memory_peak_bytes' => $endpointCgroup['memory_peak'] ?? null,
                'runtime' => $endpointInfo,
            ],
            'restate' => ['image' => RestateContainer::DEFAULT_IMAGE, 'partitions' => $this->partitions, 'request_signing' => true],
            'phases' => $this->scenario->phases($this->quick),
            'repetitions' => $repetitions,
            'metrics' => self::medians($repetitions),
        ];
    }

    /** @return array<string, mixed> */
    private function repetition(int $number): array
    {
        $environment = $this->environment();
        $requestsOffset = (int) filesize("{$this->workDir}/requests.log");
        $effectsOffset = (int) filesize("{$this->workDir}/effects.log");
        $endpointBefore = $this->cpuMicros($environment->endpointContainerName() ?? '');
        $restateBefore = $this->cpuMicros($environment->restateContainerName());

        $phases = $this->scenario->phases($this->quick);
        $summaryFile = "{$this->workDir}/k6-{$number}.json";
        $started = microtime(true);
        $inflight = $this->k6($phases, $summaryFile, poll: $this->scenario->overload);
        $k6Seconds = microtime(true) - $started;
        $inflight = [...$inflight, ...$this->waitUntilDrained($started)];

        $endpointCpu = $this->cpuMicros($environment->endpointContainerName() ?? '') - $endpointBefore;
        $restateCpu = $this->cpuMicros($environment->restateContainerName()) - $restateBefore;

        $requests = self::requestLog(self::readFrom("{$this->workDir}/requests.log", $requestsOffset));
        $effects = EffectsLog::count(explode("\n", self::readFrom("{$this->workDir}/effects.log", $effectsOffset)));
        $rows = $this->invocationRows(array_keys($requests['by_id']));
        $k6 = self::k6Summary($summaryFile, $phases);

        $invocations = 0;
        $attempts = 0;
        $retries = 0;
        $split = ['total' => [], 'sdk' => [], 'restate' => [], 'queued' => []];
        foreach ($rows as $id => $row) {
            if ($row['service'] !== $this->scenario->service || $row['status'] !== 'completed') {
                continue;
            }
            $invocations++;
            $attempts += $requests['by_id'][$id]['attempts'];
            $retries += $row['retry_count'];
            if ($row['created_at'] !== null && $row['completed_at'] !== null) {
                $total = $row['completed_at'] - $row['created_at'];
                $sdk = $requests['by_id'][$id]['micros'] / 1000;
                $split['total'][] = $total;
                $split['sdk'][] = $sdk;
                $split['restate'][] = $total - $sdk;
                if ($row['running_at'] !== null) {
                    $split['queued'][] = $row['running_at'] - $row['created_at'];
                }
            }
        }
        $notCompleted = count(array_filter($rows, static fn (array $row): bool => $row['status'] !== 'completed'));

        $measuredSeconds = array_sum(array_column($phases, 'duration'));
        $metrics = [
            'invocations' => $invocations,
            'invocations_not_completed' => $notCompleted,
            'invocations_per_second' => round($invocations / max(1, $measuredSeconds), 2),
            'endpoint_cpu_us_per_invocation' => $invocations === 0 ? null : round($endpointCpu / $invocations, 1),
            'endpoint_cpu_us_per_attempt' => $requests['invoke_requests'] === 0 ? null : round($endpointCpu / $requests['invoke_requests'], 1),
            'restate_cpu_us_per_invocation' => $invocations === 0 ? null : round($restateCpu / $invocations, 1),
            'attempts_per_invocation' => $invocations === 0 ? null : round($attempts / $invocations, 3),
            'retries_per_invocation' => $invocations === 0 ? null : round($retries / $invocations, 3),
            'corrected_attempts_per_invocation' => $invocations === 0 ? null : round(($attempts - $retries) / $invocations, 3),
            'expected_attempts_per_invocation' => $this->scenario->expectedAttempts,
            'endpoint_requests' => $requests['requests'],
            'endpoint_status_401' => $requests['statuses'][401] ?? 0,
            'endpoint_status_not_200' => $requests['requests'] - ($requests['statuses'][200] ?? 0),
            'run_executions' => $effects['executions'],
            'run_duplicates' => $effects['duplicates'],
            'latency_ms' => $k6['latency_ms'],
            'k6' => $k6['counts'],
            'time_split_ms' => array_map(self::percentiles(...), $split),
        ];
        if ($this->scenario->overload) {
            $metrics['overload'] = $this->recovery($inflight, $phases);
        }

        $this->log(sprintf(
            '  %d invocations (%.1f/s), endpoint CPU %s µs/invocation, attempts %s (corrected %s), 401s %d, p50 %s ms, p99 %s ms, k6 %.0f s',
            $invocations,
            $metrics['invocations_per_second'],
            $metrics['endpoint_cpu_us_per_invocation'] ?? '-',
            $metrics['attempts_per_invocation'] ?? '-',
            $metrics['corrected_attempts_per_invocation'] ?? '-',
            $metrics['endpoint_status_401'],
            $k6['latency_ms']['all']['p50'] ?? '-',
            $k6['latency_ms']['all']['p99'] ?? '-',
            $k6Seconds,
        ));

        return $metrics;
    }

    private function endpoint(string $identityKey): EndpointContainer
    {
        $environment = [
            'LOAD_IDENTITY_KEYS' => $identityKey,
            'LOAD_REQUEST_LOG' => self::OUT_IN_CONTAINER . '/requests.log',
            'LOAD_EFFECTS_LOG' => self::OUT_IN_CONTAINER . '/effects.log',
        ];
        if ($this->scenario->endpointThreads !== null) {
            $environment['LOAD_NUM_THREADS'] = (string) $this->scenario->endpointThreads;
        }
        $arguments = [];
        $cpus = $this->cpus ?? $this->scenario->endpointCpus;
        if ($cpus !== null) {
            $arguments = ['--cpus', $cpus];
        }
        if ($this->cpusetEndpoint !== null) {
            $arguments = [...$arguments, '--cpuset-cpus', $this->cpusetEndpoint];
        }

        return EndpointContainer::forImage($this->image)
            ->withVolume(Repository::ROOT, '/app', readOnly: true)
            ->withVolume($this->workDir, self::OUT_IN_CONTAINER)
            ->withEnvironment($environment)
            ->withRunArguments($arguments);
    }

    private function restate(string $pemFile): RestateContainer
    {
        $restate = RestateContainer::default()
            ->withVolume($pemFile, self::PEM_IN_CONTAINER, readOnly: true)
            ->withEnvironment([
                'RESTATE_WORKER__INVOKER__REQUEST_IDENTITY_PRIVATE_KEY_PEM_FILE' => self::PEM_IN_CONTAINER,
                'RESTATE_DEFAULT_NUM_PARTITIONS' => (string) $this->partitions,
            ])
            ->withStartupTimeout(120);

        return $this->cpusetRestate === null ? $restate : $restate->withRunArguments(['--cpuset-cpus', $this->cpusetRestate]);
    }

    /**
     * Runs k6 with these phases back to back; with $poll, samples the number of the scenario's invocations that are
     * not completed every half second meanwhile.
     *
     * @param list<array{name: string, rate: int, duration: int}> $phases
     *
     * @return list<array{0: float, 1: int}> seconds since k6 started, invocations not completed
     */
    private function k6(array $phases, string $summaryFile, bool $poll = false): array
    {
        $k6 = Process::findExecutable('k6') ?? throw new \RuntimeException('k6 is not on PATH (it is in the dev shell).');
        $start = 0;
        $k6Phases = [];
        foreach ($phases as $phase) {
            $k6Phases[] = ['name' => $phase['name'], 'rate' => $phase['rate'], 'duration' => "{$phase['duration']}s", 'startTime' => "{$start}s"];
            $start += $phase['duration'];
        }
        $environment = [
            'LOAD_INGRESS_URL' => $this->environment()->ingressUrl(),
            'LOAD_PATH' => $this->scenario->path,
            'LOAD_KEYS' => (string) $this->scenario->keys,
            'LOAD_BODY' => $this->scenario->body,
            'LOAD_PHASES' => json_encode($k6Phases, JSON_THROW_ON_ERROR),
            'LOAD_TIMEOUT' => "{$this->scenario->timeoutSeconds}s",
            'LOAD_SUMMARY' => $summaryFile,
        ];
        $command = [$k6, 'run', '--quiet', '--no-usage-report', __DIR__ . '/k6/load.js'];
        $log = $summaryFile . '.log';
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, null, [...getenv(), ...$environment]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start k6.');
        }

        $samples = [];
        $started = microtime(true);
        while (proc_get_status($process)['running']) {
            if ($poll) {
                $samples[] = [round(microtime(true) - $started, 2), $this->notCompleted()];
            }
            usleep((int) (self::POLL_SECONDS * 1_000_000));
        }
        $exitCode = proc_close($process);
        // k6 exits 99 when a threshold fails; these never fail, so anything else is a broken run.
        if ($exitCode !== 0 || !is_file($summaryFile)) {
            throw new \RuntimeException("k6 failed (exit {$exitCode}):\n" . (string) file_get_contents($log));
        }

        return $samples;
    }

    /** @return list<array{0: float, 1: int}> samples since $since (seconds), until nothing is left */
    private function waitUntilDrained(?float $since = null): array
    {
        $since ??= microtime(true);
        $deadline = microtime(true) + self::DRAIN_TIMEOUT_SECONDS;
        $samples = [];
        do {
            $left = $this->notCompleted();
            $samples[] = [round(microtime(true) - $since, 2), $left];
            if ($left === 0) {
                return $samples;
            }
            usleep((int) (self::POLL_SECONDS * 1_000_000));
        } while (microtime(true) < $deadline);

        $this->log(sprintf('  %d invocations still not completed after %d s', $left, self::DRAIN_TIMEOUT_SECONDS));

        return $samples;
    }

    private function notCompleted(): int
    {
        $rows = $this->environment()->query(sprintf(
            "SELECT count(*) AS n FROM sys_invocation WHERE target_service_name IN (%s) AND status <> 'completed'",
            self::sqlList($this->scenario->services),
        ));

        return (int) ($rows[0]['n'] ?? 0);
    }

    /**
     * @param list<array{0: float, 1: int}> $samples
     * @param list<array{name: string, rate: int, duration: int}> $phases
     *
     * @return array<string, mixed>
     */
    private function recovery(array $samples, array $phases): array
    {
        $nominalEnd = $phases[0]['duration'];
        $overloadEnd = $nominalEnd + ($phases[1]['duration'] ?? 0);
        // The backlog a nominal rate keeps in flight (after the first second), the level recovery returns to.
        $nominal = array_map(static fn (array $sample): int => $sample[1], array_filter($samples, static fn (array $sample): bool => $sample[0] >= 1 && $sample[0] < $nominalEnd));
        $level = max([1, ...$nominal]);
        $recovered = null;
        foreach ($samples as [$at, $left]) {
            if ($at >= $overloadEnd && $left <= $level) {
                $recovered = round($at - $overloadEnd, 2);

                break;
            }
        }

        return [
            'nominal_in_flight' => $level,
            'peak_in_flight' => max([0, ...array_map(static fn (array $sample): int => $sample[1], $samples)]),
            'recovery_seconds' => $recovered,
            'in_flight_samples' => $samples,
        ];
    }

    /**
     * The scenario's sys_invocation rows for these invocation ids, timestamps in milliseconds.
     *
     * @param list<string> $ids
     *
     * @return array<string, array{service: string, status: string, retry_count: int, created_at: ?float, running_at: ?float, completed_at: ?float}>
     */
    private function invocationRows(array $ids): array
    {
        $wanted = array_flip($ids);
        $rows = $this->environment()->query(sprintf(
            'SELECT id, target_service_name, status, retry_count, created_at, running_at, completed_at FROM sys_invocation WHERE target_service_name IN (%s)',
            self::sqlList($this->scenario->services),
        ));
        $result = [];
        foreach ($rows as $row) {
            $id = is_string($row['id'] ?? null) ? $row['id'] : '';
            if (!isset($wanted[$id])) {
                continue;
            }
            $result[$id] = [
                'service' => is_string($row['target_service_name'] ?? null) ? $row['target_service_name'] : '',
                'status' => is_string($row['status'] ?? null) ? $row['status'] : '',
                'retry_count' => is_numeric($row['retry_count'] ?? null) ? (int) $row['retry_count'] : 0,
                'created_at' => self::millis($row['created_at'] ?? null),
                'running_at' => self::millis($row['running_at'] ?? null),
                'completed_at' => self::millis($row['completed_at'] ?? null),
            ];
        }

        return $result;
    }

    private static function millis(mixed $timestamp): ?float
    {
        if (is_int($timestamp) || is_float($timestamp)) {
            return (float) $timestamp;
        }
        if (!is_string($timestamp) || $timestamp === '') {
            return null;
        }
        // At most microseconds: PHP's parser takes no more fractional digits.
        $time = date_create_immutable(preg_replace('/(\.\d{6})\d+/', '$1', $timestamp) ?? $timestamp);

        return $time === false ? null : (float) $time->format('U.u') * 1000;
    }

    /**
     * The endpoint's request log lines (`<id> <status> <µs> <path>`).
     *
     * @return array{requests: int, invoke_requests: int, statuses: array<int, int>, by_id: array<string, array{attempts: int, micros: int}>}
     */
    private static function requestLog(string $contents): array
    {
        $result = ['requests' => 0, 'invoke_requests' => 0, 'statuses' => [], 'by_id' => []];
        foreach (explode("\n", $contents) as $line) {
            $fields = explode(' ', trim($line), 4);
            if (count($fields) !== 4) {
                continue;
            }
            [$id, $status, $micros, $path] = $fields;
            $result['requests']++;
            $result['statuses'][(int) $status] = ($result['statuses'][(int) $status] ?? 0) + 1;
            if (!str_starts_with($path, '/invoke/') || $id === '-') {
                continue;
            }
            $result['invoke_requests']++;
            $entry = $result['by_id'][$id] ?? ['attempts' => 0, 'micros' => 0];
            $result['by_id'][$id] = ['attempts' => $entry['attempts'] + 1, 'micros' => $entry['micros'] + max(0, (int) $micros)];
        }

        return $result;
    }

    /**
     * Latency per phase and in all, and the k6 counters.
     *
     * @param list<array{name: string, rate: int, duration: int}> $phases
     *
     * @return array{latency_ms: array<string, array<string, float|null>>, counts: array<string, int>}
     */
    private static function k6Summary(string $file, array $phases): array
    {
        $summary = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $metrics = is_array($summary) && is_array($summary['metrics'] ?? null) ? $summary['metrics'] : [];
        $trend = static function (string $name) use ($metrics): array {
            $values = is_array($metrics[$name] ?? null) && is_array($metrics[$name]['values'] ?? null) ? $metrics[$name]['values'] : [];
            $value = static fn (string $key): ?float => is_numeric($values[$key] ?? null) ? round((float) $values[$key], 2) : null;

            return ['p50' => $value('med'), 'p90' => $value('p(90)'), 'p99' => $value('p(99)'), 'max' => $value('max')];
        };
        $count = static function (string $name) use ($metrics): int {
            $values = is_array($metrics[$name] ?? null) && is_array($metrics[$name]['values'] ?? null) ? $metrics[$name]['values'] : [];

            return is_numeric($values['count'] ?? null) ? (int) $values['count'] : 0;
        };

        $latency = ['all' => $trend('http_req_duration')];
        $counts = [
            'requests' => $count('http_reqs'),
            'completed' => $count('load_completed'),
            'failed' => $count('load_failures'),
            'timed_out' => $count('load_timeouts'),
            'dropped' => $count('dropped_iterations'),
        ];
        foreach ($phases as $phase) {
            $tag = "{scenario:{$phase['name']}}";
            $latency[$phase['name']] = $trend("http_req_duration{$tag}");
            if (count($phases) > 1) {
                foreach (['completed' => 'load_completed', 'failed' => 'load_failures', 'timed_out' => 'load_timeouts', 'dropped' => 'dropped_iterations'] as $key => $metric) {
                    $counts["{$phase['name']}_{$key}"] = $count("{$metric}{$tag}");
                }
            }
        }

        return ['latency_ms' => $latency, 'counts' => $counts];
    }

    /**
     * @param list<float> $values
     *
     * @return array{count: int, p50: ?float, p99: ?float}
     */
    private static function percentiles(array $values): array
    {
        sort($values);
        $at = static fn (float $quantile): ?float => $values === [] ? null : round($values[(int) min(count($values) - 1, floor($quantile * count($values)))], 2);

        return ['count' => count($values), 'p50' => $at(0.5), 'p99' => $at(0.99)];
    }

    /**
     * The median of each scalar metric over the repetitions (the result's headline numbers).
     *
     * @param list<array<string, mixed>> $repetitions
     *
     * @return array<string, float|int|null>
     */
    private static function medians(array $repetitions): array
    {
        $medians = [];
        foreach (array_keys($repetitions[0] ?? []) as $key) {
            $values = array_map(static fn (array $repetition): mixed => $repetition[$key], $repetitions);
            if (array_filter($values, static fn (mixed $value): bool => !is_int($value) && !is_float($value)) !== []) {
                continue;
            }
            /** @var list<float|int> $values */
            $medians[$key] = LoadHistory::median($values);
        }

        return $medians;
    }

    /**
     * What the endpoint's `GET /load-info` reports: PHP version, SAPI, ini settings, OPcache statistics.
     *
     * @return array<array-key, mixed>|string
     */
    private function endpointInfo(): array|string
    {
        $response = Http::request('GET', $this->environment()->endpointUrl() . '/load-info');
        if (is_string($response)) {
            return $response;
        }
        $decoded = json_decode($response[1], true);

        return is_array($decoded) ? $decoded : "HTTP {$response[0]}: {$response[1]}";
    }

    /** @return array{memory_peak?: int} */
    private function cgroup(string $container): array
    {
        [$exitCode, $stdout] = Process::run([$this->environment()->containerRuntime(), 'exec', $container, 'cat', '/sys/fs/cgroup/memory.peak']);

        return $exitCode === 0 && is_numeric(trim($stdout)) ? ['memory_peak' => (int) trim($stdout)] : [];
    }

    /** The container's cgroup CPU time so far (cgroup v2 `cpu.stat` `usage_usec`), user and system. */
    private function cpuMicros(string $container): int
    {
        [$exitCode, $stdout, $stderr] = Process::run([$this->environment()->containerRuntime(), 'exec', $container, 'cat', '/sys/fs/cgroup/cpu.stat']);
        if ($exitCode !== 0 || preg_match('/^usage_usec (\d+)$/m', $stdout, $match) !== 1) {
            throw new \RuntimeException("Could not read the cgroup cpu.stat of {$container} (cgroup v2 needed): {$stdout}{$stderr}");
        }

        return (int) $match[1];
    }

    private function environment(): RestateTestEnvironment
    {
        return $this->environment ?? throw new \LogicException('The load environment is not running.');
    }

    private static function readFrom(string $file, int $offset): string
    {
        $contents = file_get_contents($file, offset: $offset);

        return $contents === false ? '' : $contents;
    }

    /** @param list<string> $values */
    private static function sqlList(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'" . str_replace("'", "''", $value) . "'", $values));
    }

    /**
     * Restate's request-signing key as the PKCS#8 PEM restate-server reads, and the matching identity key.
     *
     * @return array{string, string}
     */
    private static function keyPair(): array
    {
        [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair();
        $seed = substr($secretKey, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);
        // PKCS#8 PrivateKeyInfo { version 0, algorithm id-Ed25519 (1.3.101.112), OCTET STRING { OCTET STRING seed } }.
        $der = hex2bin('302e020100300506032b657004220420') . $seed;

        return ["-----BEGIN PRIVATE KEY-----\n" . base64_encode($der) . "\n-----END PRIVATE KEY-----\n", $identityKey];
    }

    private function log(string $message): void
    {
        fwrite(STDERR, "[load {$this->scenario->name}] {$message}\n");
    }
}
