<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Support;

use Restate\Tests\Support\Protocol\FrameCodec;

/**
 * What a FrankenPHP worker script (worker.php) knows about itself, for the tests that must tell worker mode from
 * classic mode and one worker run from the next:
 *
 * - an instance id, random per worker script run (a restart after `exit()` or a fatal error gets a new one), the
 *   process id, and the requests this run has served, sent as response headers ({@see headers()}) and written, with
 *   the endpoints' answers by HTTP status, to
 *   `<RESTATE_PHP_TEST_STATE_DIR>/workers/<instance>.json` every {@see STATS_EVERY} requests;
 * - `GET /__worker`: the same plus PHP's view of the process (ini settings, heap after `gc_collect_cycles()`, included
 *   files, the extension's `diagnostics()` when it has them, RSS and open file descriptors from `/proc/self`);
 * - `GET /__worker/echo`: prints a line and flushes it, so PHP sends its own headers: the next request on the same
 *   worker shows whether `headers_sent()` was reset in between.
 *
 * Test services read the current run with {@see current()}; it is null outside a worker script.
 *
 * @phpstan-type WorkerStats array{instance: string, pid: int, requests: int, startedAt: float, threadSafe: bool, ini: array<string, string|false>, memory: array{usage: int, realUsage: int, peak: int}, gc: array<string, mixed>, includedFiles: int, diagnostics: ?array<string, int>, process: array<string, int>, canary: array{kind: ?string, kept: int}}
 */
final class WorkerProbe
{
    public const string HEADER_INSTANCE = 'x-restate-php-worker-instance';

    public const string HEADER_PID = 'x-restate-php-worker-pid';

    public const string HEADER_REQUESTS = 'x-restate-php-worker-requests';

    public const string PATH = '/__worker';

    private const int STATS_EVERY = 10;

    /** Environment variable that plants a leak after every request (`php` or `vm`), for the soak suite's self-test. */
    public const string CANARY_VARIABLE = 'RESTATE_PHP_TEST_SOAK_CANARY';

    private static ?self $current = null;

    private int $requests = 0;

    /** @var array<int, int> answers by HTTP status, of the requests the endpoints served */
    private array $statuses = [];

    /** @var list<\Closure(): void> */
    private array $atRequestEnd = [];

    /** @var list<mixed> what a canary keeps alive */
    private array $leaked = [];

    private function __construct(
        public readonly string $instance,
        public readonly int $pid,
        private readonly float $startedAt,
        private readonly ?string $statsDir,
        private readonly ?string $canary,
    ) {
    }

    public static function start(): self
    {
        $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR');
        $statsDir = is_string($stateDir) && $stateDir !== '' ? $stateDir . '/workers' : null;
        if ($statsDir !== null && !is_dir($statsDir)) {
            @mkdir($statsDir, 0o777, true);
        }
        $canary = getenv(self::CANARY_VARIABLE);

        return self::$current = new self(bin2hex(random_bytes(6)), (int) getmypid(), microtime(true), $statsDir, is_string($canary) && $canary !== '' ? $canary : null);
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function isProbe(string $uri): bool
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);

        return $path === self::PATH || str_starts_with($path, self::PATH . '/');
    }

    public function requests(): int
    {
        return $this->requests;
    }

    public function served(): void
    {
        ++$this->requests;
        if ($this->requests === 1 || $this->requests % self::STATS_EVERY === 0) {
            $this->record();
        }
    }

    /** Writes this run's identity, request count and answers by status to the state directory. */
    private function record(): void
    {
        if ($this->statsDir !== null) {
            @file_put_contents("{$this->statsDir}/{$this->instance}.json", json_encode(['instance' => $this->instance, 'pid' => $this->pid, 'requests' => $this->requests, 'startedAt' => $this->startedAt, 'statuses' => $this->statuses], JSON_THROW_ON_ERROR), LOCK_EX);
        }
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return [self::HEADER_INSTANCE => $this->instance, self::HEADER_PID => (string) $this->pid, self::HEADER_REQUESTS => (string) $this->requests];
    }

    public function answer(string $uri): void
    {
        if ((string) parse_url($uri, PHP_URL_PATH) === self::PATH . '/echo') {
            header('content-type: text/plain');
            echo "worker probe output\n";
            flush();

            return;
        }

        header('content-type: application/json');
        echo json_encode($this->stats(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Runs `$callback` once the endpoints have answered the current request: what a classic script does in a shutdown
     * function, which in a worker script runs only when the script ends.
     */
    public function atRequestEnd(\Closure $callback): void
    {
        $this->atRequestEnd[] = $callback;
    }

    /** After the endpoints served a request: the {@see atRequestEnd()} callbacks, counts, and a planted leak if any. */
    public function afterRequest(): void
    {
        $callbacks = $this->atRequestEnd;
        $this->atRequestEnd = [];
        foreach ($callbacks as $callback) {
            $callback();
        }

        // Counted per status; an answer other than 2xx is recorded at once, so the counts on disk are exact for them.
        $status = http_response_code();
        $status = is_int($status) ? $status : 0;
        $this->statuses[$status] = ($this->statuses[$status] ?? 0) + 1;
        if ($status < 200 || $status >= 300) {
            $this->record();
        }

        match ($this->canary) {
            null => null,
            // 256 bytes of PHP heap per request.
            'php' => $this->leaked[] = str_repeat('x', 256),
            // A Rust VM per request that is never freed, holding about 1 KiB of input (an idle VM holds no heap).
            'vm' => $this->leaked[] = self::vmHoldingInput(),
            default => throw new \RuntimeException("Unknown soak canary '{$this->canary}'."),
        };
    }

    private static function vmHoldingInput(): \Restate\Extension\Vm
    {
        $vm = new \Restate\Extension\Vm([['content-type', 'application/vnd.restate.invocation.v7']], 'info');
        $vm->notifyInput(FrameCodec::startFrame('inv_1canary000000000000000000000000', 1) . FrameCodec::inputCommandFrame(str_repeat('x', 1024)));

        return $vm;
    }

    /** @return WorkerStats */
    public function stats(): array
    {
        gc_collect_cycles();
        $ini = [];
        foreach (['display_errors', 'log_errors', 'html_errors', 'output_buffering', 'max_execution_time', 'memory_limit', 'zend.exception_ignore_args', 'enable_post_data_reading', 'post_max_size'] as $name) {
            $ini[$name] = ini_get($name);
        }
        $diagnostics = function_exists('Restate\\Extension\\diagnostics') ? \Restate\Extension\diagnostics() : null;

        return [
            'instance' => $this->instance,
            'pid' => $this->pid,
            'requests' => $this->requests,
            'startedAt' => $this->startedAt,
            'threadSafe' => ZEND_THREAD_SAFE,
            'ini' => $ini,
            'memory' => ['usage' => memory_get_usage(), 'realUsage' => memory_get_usage(true), 'peak' => memory_get_peak_usage()],
            'gc' => gc_status(),
            'includedFiles' => count(get_included_files()),
            'diagnostics' => $diagnostics,
            'process' => self::process(),
            'canary' => ['kind' => $this->canary, 'kept' => count($this->leaked)],
        ];
    }

    /** @return array<string, int> RSS, anonymous RSS and threads (KiB and counts, from /proc/self/status) and open file descriptors */
    private static function process(): array
    {
        $process = [];
        $status = @file_get_contents('/proc/self/status');
        foreach (['VmRSS' => 'rssKib', 'RssAnon' => 'rssAnonKib', 'Threads' => 'threads'] as $field => $name) {
            if (is_string($status) && preg_match("/^{$field}:\\s+(\\d+)/m", $status, $match) === 1) {
                $process[$name] = (int) $match[1];
            }
        }
        $fds = @scandir('/proc/self/fd');
        if ($fds !== false) {
            $process['fds'] = count($fds) - 2;
        }

        return $process;
    }
}
