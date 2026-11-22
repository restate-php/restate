<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Faults;

/**
 * What the {@see FaultInjector} does to one attempt's response, after the endpoint has handled the request (so the
 * handler and its run bodies have already executed). Frame counts are of the protocol frames the endpoint produced,
 * parsed from its response, never byte offsets.
 */
final readonly class Fault
{
    public const string KILL_BEFORE_OUTPUT = 'kill-before-output';

    public const string KILL_AFTER_FRAMES = 'kill-after-frames';

    public const string KILL_AFTER_RESPONSE = 'kill-after-response';

    public const string TRUNCATE_AFTER_FRAMES = 'truncate-after-frames';

    public const string EXIT = 'exit';

    public const string DELAY = 'delay';

    public const string RESPOND = 'respond';

    private const array ACTIONS = [self::KILL_BEFORE_OUTPUT, self::KILL_AFTER_FRAMES, self::KILL_AFTER_RESPONSE, self::TRUNCATE_AFTER_FRAMES, self::EXIT, self::DELAY, self::RESPOND];

    /** @param array<string, string> $headers */
    private function __construct(
        public string $action,
        /** Whole frames written before the cut (KILL_AFTER_FRAMES, TRUNCATE_AFTER_FRAMES). */
        public int $frames = 0,
        /** Also write the first half of the next frame, so Restate sees a frame cut in the middle. */
        public bool $halfFrame = false,
        /** How long DELAY waits before writing the whole response. */
        public int $milliseconds = 0,
        /** The status RESPOND answers with. */
        public int $status = 0,
        /** The headers RESPOND answers with. */
        public array $headers = [],
    ) {
    }

    /** SIGKILL the worker before it writes anything (degrades to an empty protocol response plus `exit`, see {@see ProcessFaults}). */
    public static function killBeforeOutput(): self
    {
        return new self(self::KILL_BEFORE_OUTPUT);
    }

    /** Write the status, the headers and the first `$frames` frames (and half the next), flush, then SIGKILL the worker. */
    public static function killAfterFrames(int $frames, bool $halfFrame = false): self
    {
        return new self(self::KILL_AFTER_FRAMES, self::count($frames), $halfFrame);
    }

    /** Write the whole response, flush, then SIGKILL the worker before it finishes the request. */
    public static function killAfterResponse(): self
    {
        return new self(self::KILL_AFTER_RESPONSE);
    }

    /** Write only the first `$frames` frames (and half the next), then end the response normally. */
    public static function truncateAfterFrames(int $frames, bool $halfFrame = false): self
    {
        return new self(self::TRUNCATE_AFTER_FRAMES, self::count($frames), $halfFrame);
    }

    /** `exit(0)` without writing anything: under `php -S` an empty `200 text/html`; in a worker loop the worker ends. */
    public static function exit(): self
    {
        return new self(self::EXIT);
    }

    /** Wait, then write the whole response. */
    public static function delay(int $milliseconds): self
    {
        return new self(self::DELAY, milliseconds: self::count($milliseconds));
    }

    /**
     * Answer `$status` with `$headers` and an empty `text/plain` body instead of the endpoint's response, as a front
     * door that sheds load does (`503` with `Retry-After`, `429`). The handler has run all the same.
     *
     * @param array<string, string> $headers
     */
    public static function respond(int $status, array $headers = []): self
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException("Not an HTTP status: {$status}.");
        }

        return new self(self::RESPOND, status: $status, headers: $headers);
    }

    /** @param array<array-key, mixed> $data what {@see toArray()} returned */
    public static function fromArray(array $data): self
    {
        $action = $data['action'] ?? null;
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unknown fault action ' . json_encode($action) . '.');
        }

        $headers = [];
        foreach (is_array($data['headers'] ?? null) ? $data['headers'] : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return new self(
            $action,
            self::count(is_int($data['frames'] ?? null) ? $data['frames'] : 0),
            ($data['halfFrame'] ?? false) === true,
            self::count(is_int($data['milliseconds'] ?? null) ? $data['milliseconds'] : 0),
            is_int($data['status'] ?? null) ? $data['status'] : 0,
            $headers,
        );
    }

    /** @return array{action: string, frames: int, halfFrame: bool, milliseconds: int, status: int, headers: array<string, string>} */
    public function toArray(): array
    {
        return ['action' => $this->action, 'frames' => $this->frames, 'halfFrame' => $this->halfFrame, 'milliseconds' => $this->milliseconds, 'status' => $this->status, 'headers' => $this->headers];
    }

    /** For logs: `kill-after-frames:2+half`, `delay:500ms`, `respond:503`, `exit`. */
    public function describe(): string
    {
        return match ($this->action) {
            self::KILL_AFTER_FRAMES, self::TRUNCATE_AFTER_FRAMES => "{$this->action}:{$this->frames}" . ($this->halfFrame ? '+half' : ''),
            self::DELAY => "delay:{$this->milliseconds}ms",
            self::RESPOND => "respond:{$this->status}",
            default => $this->action,
        };
    }

    private static function count(int $value): int
    {
        if ($value < 0) {
            throw new \InvalidArgumentException("Fault counts and durations must not be negative, got {$value}.");
        }

        return $value;
    }
}
