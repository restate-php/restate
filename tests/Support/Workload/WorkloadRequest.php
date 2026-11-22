<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Workload;

/** One request as Restate would send it to an endpoint: a protocol body for a handler of {@see WorkloadServices}. */
final readonly class WorkloadRequest
{
    /** @param list<int> $expectedFrames the frame types a correct response has, in order */
    public function __construct(
        /** The request class, for reports: `start`, `replay-runs:100`, … */
        public string $class,
        /** The path below the endpoint's own: `/invoke/Workload/echo`. */
        public string $path,
        public string $contentType,
        public string $invocationId,
        public string $body,
        public array $expectedFrames,
    ) {
    }

    /**
     * The HTTP headers Restate sends (lowercase names), apart from the transfer framing.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function headers(): array
    {
        return [['content-type', $this->contentType], ['x-restate-invocation-id', $this->invocationId]];
    }
}
