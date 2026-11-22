<?php

declare(strict_types=1);

namespace Restate\Tests\Soak\Support;

use Restate\Tests\Support\Workload\ConcurrentHttpDriver;
use Restate\Tests\Support\Workload\WorkloadRequest;
use Restate\Tests\Support\Workload\WorkloadResponse;

/**
 * Drives a {@see SoakEndpoint} by direct frame replay: phases of requests sent with a {@see ConcurrentHttpDriver},
 * every response checked, and a checkpoint of the worker's state after every batch (with nothing in flight).
 */
final class SoakRun
{
    public readonly Checkpoints $checkpoints;

    private readonly ConcurrentHttpDriver $driver;

    private int $sent = 0;

    /** @var array<int, SoakRequest> the requests in flight, by the object id of their WorkloadRequest */
    private array $inFlight = [];

    /** @var array<string, int> */
    private array $responses = [];

    /** @var array<string, array{count: int, examples: list<string>}> */
    private array $unexpected = [];

    /** @var list<float> seconds, of the current phase */
    private array $durations = [];

    /** @param int<1, max> $concurrency */
    public function __construct(
        public readonly SoakEndpoint $endpoint,
        private readonly ?SoakIdentity $identity = null,
        int $concurrency = 4,
    ) {
        $this->checkpoints = new Checkpoints();
        $this->driver = new ConcurrentHttpDriver($endpoint->url, $concurrency, extraHeaders: function (WorkloadRequest $request): array {
            $soakRequest = $this->inFlight[spl_object_id($request)] ?? null;
            $identity = match ($soakRequest?->signing) {
                'valid' => $this->identity?->headers($request) ?? [],
                'forged' => $this->identity?->forged($request) ?? [],
                default => [],
            };

            return [...$identity, ...($soakRequest === null ? [] : $soakRequest->headers)];
        });
    }

    /**
     * Sends `$count` requests made by `$make` in `$batches` batches, with a checkpoint after each one.
     *
     * @param \Closure(int): SoakRequest $make the i-th request of the phase
     */
    public function phase(string $name, int $count, \Closure $make, int $batches = 20): float
    {
        $this->durations = [];
        $started = microtime(true);
        $batches = max(1, min($batches, $count));
        for ($batch = 0; $batch < $batches; ++$batch) {
            $from = intdiv($count * $batch, $batches);
            $to = intdiv($count * ($batch + 1), $batches);
            $this->send((static function () use ($make, $from, $to): \Generator {
                for ($i = $from; $i < $to; ++$i) {
                    yield $make($i);
                }
            })());
            $this->checkpoints->take($this->endpoint, $name, $this->sent);
        }

        return $count / max(1e-6, microtime(true) - $started);
    }

    /** @param iterable<SoakRequest> $requests */
    public function send(iterable $requests): void
    {
        $workload = (function () use ($requests): \Generator {
            foreach ($requests as $soakRequest) {
                $this->inFlight[spl_object_id($soakRequest->request)] = $soakRequest;
                yield $soakRequest->request;
            }
        })();

        $this->driver->run($workload, function (WorkloadRequest $request, WorkloadResponse $response, float $seconds): void {
            $id = spl_object_id($request);
            $soakRequest = $this->inFlight[$id];
            unset($this->inFlight[$id]);
            ++$this->sent;
            $this->responses[$soakRequest->label()] = ($this->responses[$soakRequest->label()] ?? 0) + 1;
            $this->durations[] = $seconds;
            if (!$soakRequest->isExpected($response)) {
                $entry = $this->unexpected[$soakRequest->label()] ?? ['count' => 0, 'examples' => []];
                ++$entry['count'];
                if (count($entry['examples']) < 3) {
                    $entry['examples'][] = $response->describe();
                }
                $this->unexpected[$soakRequest->label()] = $entry;
            }
        });
    }

    public function sent(): int
    {
        return $this->sent;
    }

    /** @return array<string, int> responses per request label */
    public function responses(): array
    {
        return $this->responses;
    }

    /** @return array<string, array{count: int, examples: list<string>}> unexpected responses per request label */
    public function unexpected(): array
    {
        return $this->unexpected;
    }

    /** @return list<float> request durations of the last phase, in seconds */
    public function durations(): array
    {
        return $this->durations;
    }
}
