<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Faults;

use Restate\Testing\Internal\Process;

/**
 * A TCP proxy between Restate and an endpoint that misbehaves on the connections a test asks it to: network faults
 * the endpoint cannot produce itself (a reset, a blackhole, latency, a stalled or truncated request body, a status
 * the front door sheds load with). One process (fault-proxy.php, forking per connection) started through the test
 * harness's Process, so no extra image is needed. HTTP/1.1 only, one request per connection; register the endpoint
 * through it with `registerDeployment('http://' . $environment->testHostForRestate() . ':' . $proxy->port, http2: false)`.
 *
 * A mode applies to the next `$connections` connections, then the proxy passes connections through again.
 */
final class FaultProxy
{
    public const string PASS = 'pass';

    public const string LATENCY = 'latency';

    public const string RESET = 'reset';

    public const string RESET_RESPONSE = 'reset-response';

    public const string BLACKHOLE = 'blackhole';

    public const string STALL_REQUEST = 'stall-request';

    public const string TRUNCATE_REQUEST = 'truncate-request';

    public const string STATUS = 'status';

    private function __construct(
        private readonly Process $process,
        private readonly string $prefix,
        public readonly int $port,
    ) {
    }

    /** Start a proxy to `$upstreamHost:$upstreamPort`, listening on a free port of `$bindAddress`. */
    public static function start(string $upstreamHost, int $upstreamPort, string $stateDir, string $bindAddress = '0.0.0.0'): self
    {
        $prefix = $stateDir . '/fault-proxy-' . bin2hex(random_bytes(4));
        $process = Process::start([PHP_BINARY, __DIR__ . '/fault-proxy.php', $bindAddress, $upstreamHost, (string) $upstreamPort, $prefix], $prefix . '.log');

        $deadline = microtime(true) + 10;
        while (!is_file($prefix . '.port')) {
            if (!$process->isRunning() || microtime(true) > $deadline) {
                $process->kill();

                throw new \RuntimeException('The fault proxy did not start: ' . @file_get_contents($prefix . '.log'));
            }
            usleep(20_000);
        }

        return new self($process, $prefix, (int) file_get_contents($prefix . '.port'));
    }

    /**
     * Misbehave on the next `$connections` connections.
     *
     * @param int $milliseconds the latency, blackhole or stall duration; the status code for {@see STATUS}
     */
    public function fault(string $mode, int $connections = 1, int $milliseconds = 0): void
    {
        $file = $this->prefix . '.json';
        $temporary = $file . '.' . bin2hex(random_bytes(4));
        file_put_contents($temporary, json_encode(['mode' => $mode, 'ms' => $milliseconds, 'connections' => $connections], JSON_THROW_ON_ERROR));
        rename($temporary, $file);
    }

    /**
     * The connections of one invocation's attempts.
     *
     * @return list<array{connection: int, mode: string, accepted: int, request: ?string, invocation: ?string, end: ?string, closed: ?int}>
     */
    public function connectionsOf(string $invocationId): array
    {
        return array_values(array_filter($this->connections(), static fn (array $connection): bool => $connection['invocation'] === $invocationId));
    }

    /** Pass every connection through from now on. */
    public function heal(): void
    {
        @unlink($this->prefix . '.json');
    }

    /**
     * What happened to each connection so far, in order: the mode it got, when it was accepted, the request line and
     * the `x-restate-invocation-id` it carried, how it ended (once it did) and when (milliseconds since the epoch).
     *
     * @return list<array{connection: int, mode: string, accepted: int, request: ?string, invocation: ?string, end: ?string, closed: ?int}>
     */
    public function connections(): array
    {
        $lines = is_file($this->prefix . '.jsonl') ? file($this->prefix . '.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        $connections = [];
        foreach ($lines === false ? [] : $lines as $line) {
            $event = json_decode($line, true);
            if (!is_array($event) || !is_int($event['connection'] ?? null) || !is_int($event['at'] ?? null)) {
                continue;
            }
            $number = $event['connection'];
            if (($event['event'] ?? null) === 'accept') {
                $connections[$number] = ['connection' => $number, 'mode' => is_string($event['mode'] ?? null) ? $event['mode'] : '', 'accepted' => $event['at'], 'request' => null, 'invocation' => null, 'end' => null, 'closed' => null];
            } elseif (($event['event'] ?? null) === 'request' && isset($connections[$number])) {
                $connections[$number]['request'] = is_string($event['request'] ?? null) ? $event['request'] : null;
                $connections[$number]['invocation'] = is_string($event['invocation'] ?? null) ? $event['invocation'] : null;
            } elseif (($event['event'] ?? null) === 'close' && isset($connections[$number])) {
                $connections[$number]['end'] = is_string($event['end'] ?? null) ? $event['end'] : '';
                $connections[$number]['closed'] = $event['at'];
            }
        }
        ksort($connections);

        return array_values($connections);
    }

    /** What the proxy process printed (errors). */
    public function log(): string
    {
        return (string) @file_get_contents($this->prefix . '.log');
    }

    public function stop(): void
    {
        $this->process->stop(2.0);
    }
}
