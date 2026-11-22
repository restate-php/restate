<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Faults;

/**
 * The {@see FaultInjector}'s record of one invocation's attempts: a JSON-lines file in the state directory, one
 * `start` event when an attempt arrives (its number is the count of starts, taken under a lock, so concurrent workers
 * never share one) and one `response` event once the endpoint answered it.
 */
final readonly class AttemptLog
{
    private string $file;

    public function __construct(string $stateDir, string $invocationId)
    {
        $this->file = $stateDir . '/fault-attempts-' . hash('sha256', $invocationId) . '.jsonl';
    }

    /** Record a new attempt; returns its number (1 for the first). */
    public function start(): int
    {
        $handle = fopen($this->file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open {$this->file}.");
        }

        try {
            flock($handle, LOCK_EX);
            $attempt = substr_count((string) stream_get_contents($handle), '"event":"start"') + 1;
            fseek($handle, 0, SEEK_END);
            fwrite($handle, json_encode(['event' => 'start', 'attempt' => $attempt, 'pid' => (int) getmypid()], JSON_THROW_ON_ERROR) . "\n");
            fflush($handle);

            return $attempt;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param ?list<int> $frames */
    public function respond(int $attempt, int $status, ?array $frames, ?string $fault, string $end, int $written, int $length): void
    {
        $event = ['event' => 'response', 'attempt' => $attempt, 'status' => $status, 'frames' => $frames, 'fault' => $fault, 'end' => $end, 'written' => $written, 'length' => $length];
        file_put_contents($this->file, json_encode($event, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<array{attempt: int, pid: int, response: ?array{status: int, frames: ?list<int>, fault: ?string, end: string, written: int, length: int}}>
     */
    public function read(): array
    {
        $lines = is_file($this->file) ? file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        $attempts = [];
        foreach ($lines === false ? [] : $lines as $line) {
            $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($event) || !is_int($event['attempt'] ?? null)) {
                continue;
            }
            $attempt = $event['attempt'];
            if (($event['event'] ?? null) === 'start') {
                $attempts[$attempt] = ['attempt' => $attempt, 'pid' => is_int($event['pid'] ?? null) ? $event['pid'] : 0, 'response' => null];
            } elseif (isset($attempts[$attempt])) {
                $frames = $event['frames'] ?? null;
                $attempts[$attempt]['response'] = [
                    'status' => is_int($event['status'] ?? null) ? $event['status'] : 0,
                    'frames' => is_array($frames) ? array_values(array_filter($frames, is_int(...))) : null,
                    'fault' => is_string($event['fault'] ?? null) ? $event['fault'] : null,
                    'end' => is_string($event['end'] ?? null) ? $event['end'] : '',
                    'written' => is_int($event['written'] ?? null) ? $event['written'] : 0,
                    'length' => is_int($event['length'] ?? null) ? $event['length'] : 0,
                ];
            }
        }
        ksort($attempts);

        return array_values($attempts);
    }
}
