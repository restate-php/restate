<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Faults;

/** What the {@see FaultInjector} does with one response: what it writes, and how the attempt then ends. */
final readonly class FaultOutcome
{
    public const string COMPLETE = 'complete';

    public const string TRUNCATED = 'truncated';

    public const string KILLED = 'killed';

    public const string EXITED = 'exited';

    /** The endpoint's response was replaced by {@see $status} and {@see $headers}. */
    public const string REPLACED = 'replaced';

    /** @param array<string, string> $headers */
    public function __construct(
        /** Whether the status and headers are written (not for a kill before output or an `exit`). */
        public bool $writeHead,
        /** The body bytes written. */
        public string $body,
        /** {@see COMPLETE}, {@see TRUNCATED} (the response ends early, normally), {@see KILLED}, {@see EXITED} or {@see REPLACED}. */
        public string $end,
        /** Milliseconds to wait before writing. */
        public int $delayMilliseconds = 0,
        /** The status written instead of the endpoint's ({@see REPLACED}). */
        public ?int $status = null,
        /** The headers written instead of the endpoint's ({@see REPLACED}). */
        public array $headers = [],
    ) {
    }
}
