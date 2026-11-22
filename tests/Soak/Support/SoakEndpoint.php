<?php

declare(strict_types=1);

namespace Restate\Tests\Soak\Support;

use Restate\Testing\Internal\Process;
use Restate\Tests\Integration\Support\WorkerProbe;
use Restate\Tests\Support\Repository;

/**
 * A FrankenPHP worker-mode endpoint for a soak run, without Restate: one of the soak images (`frankenphp-worker-release`
 * or `frankenphp-worker-debug`, built by bin/test-soak.sh) serving the integration endpoint's worker script from the
 * repository mounted at /app, on a free 127.0.0.1 port. The driver sends it protocol bodies straight away
 * ({@see \Restate\Tests\Support\Workload\ConcurrentHttpDriver}) and reads its state from `/__worker`
 * ({@see WorkerProbe}). Stopped and removed when the process exits.
 *
 * @phpstan-import-type WorkerStats from WorkerProbe
 */
final class SoakEndpoint
{
    public const string LABEL = 'restate-php-soak';

    private bool $stopped = false;

    private function __construct(
        private readonly string $runtime,
        public readonly string $name,
        public readonly string $url,
        public readonly string $stateDir,
    ) {
    }

    /**
     * @param array<string, string> $environment on top of the state directory; e.g. the worker threads
     *                                           (RESTATE_PHP_TEST_WORKER_NUM), IntegrationEndpoints' logger settings
     * @param list<string> $runArguments extra `run` options (`--cpus`, …)
     */
    public static function start(string $image, int $threads, array $environment = [], array $runArguments = []): self
    {
        $runtime = Process::findExecutable('docker') ?? Process::findExecutable('podman')
            ?? throw new \RuntimeException('A soak run needs `docker` or `podman` on PATH.');
        $stateDir = sys_get_temp_dir() . '/restate-php-soak-' . bin2hex(random_bytes(4));
        if (!mkdir($stateDir, 0o777, true) || !chmod($stateDir, 0o777)) {
            throw new \RuntimeException("Could not create {$stateDir}.");
        }

        $name = 'restate-php-soak-' . bin2hex(random_bytes(4));
        $command = [$runtime, 'run', '--detach', '--name', $name, '--label', self::LABEL, '--publish', '127.0.0.1::8080',
            '--volume', Repository::ROOT . ':/app:ro', '--volume', "{$stateDir}:{$stateDir}"];
        $environment = ['RESTATE_PHP_TEST_STATE_DIR' => $stateDir, 'RESTATE_PHP_TEST_WORKER_NUM' => (string) $threads] + $environment;
        foreach ($environment as $variable => $value) {
            $command[] = '--env';
            $command[] = "{$variable}={$value}";
        }
        // The repository's worker.Caddyfile rather than the copy baked into the image: changing it needs no rebuild.
        [$exitCode, $stdout, $stderr] = Process::run([...$command, ...$runArguments, $image, 'frankenphp', 'run', '--config', '/app/docker/frankenphp/worker.Caddyfile']);
        if ($exitCode !== 0) {
            throw new \RuntimeException("Could not start {$image} (build it with bin/test-soak.sh): " . trim($stdout . $stderr));
        }

        $endpoint = null;
        try {
            [, $port] = Process::run([$runtime, 'port', $name, '8080']);
            if (preg_match('/:(\d+)\s*$/m', trim($port), $match) !== 1) {
                throw new \RuntimeException("No published port for {$name}: {$port}");
            }
            $endpoint = new self($runtime, $name, "http://127.0.0.1:{$match[1]}", $stateDir);
            register_shutdown_function($endpoint->stop(...));
            $endpoint->waitUntilReady();
        } catch (\Throwable $exception) {
            $endpoint?->stop();
            if ($endpoint === null) {
                Process::run([$runtime, 'rm', '--force', $name]);
            }

            throw $exception;
        }

        return $endpoint;
    }

    /** The main endpoint's log (IntegrationEndpoints' JSON-lines logger), in the state directory. */
    public function endpointLogFile(): string
    {
        return $this->stateDir . '/endpoint-log.jsonl';
    }

    /**
     * What the worker that answers says about itself; with one worker thread, the worker.
     *
     * @return WorkerStats
     */
    public function stats(): array
    {
        $context = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true]]);
        $body = @file_get_contents($this->url . WorkerProbe::PATH, false, $context);
        if ($body === false) {
            throw new \RuntimeException("GET {$this->url}/__worker failed.");
        }

        /** @var WorkerStats */
        return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }

    public function isRunning(): bool
    {
        [$exitCode, $stdout] = Process::run([$this->runtime, 'inspect', '--format', '{{.State.Running}}', $this->name]);

        return $exitCode === 0 && trim($stdout) === 'true';
    }

    /** The container's log: FrankenPHP's (JSON lines) and PHP's stderr. */
    public function logs(): string
    {
        [, $stdout, $stderr] = Process::run([$this->runtime, 'logs', $this->name]);

        return $stdout . $stderr;
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        Process::run([$this->runtime, 'rm', '--force', $this->name]);
        self::removeDirectory($this->stateDir);
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline) {
            try {
                $this->stats();

                return;
            } catch (\Throwable) {
                usleep(200_000);
            }
        }

        throw new \RuntimeException("The soak endpoint {$this->name} did not answer within 60 s:\n" . $this->logs());
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($directory);
    }
}
