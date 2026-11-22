<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Workload;

use Restate\Tests\Support\Protocol\FrameCodec;

/** An endpoint's answer to a {@see WorkloadRequest}. */
final readonly class WorkloadResponse
{
    /** @param list<array{0: string, 1: string}> $headers lowercase names */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as [$header, $value]) {
            if ($header === strtolower($name)) {
                return $value;
            }
        }

        return null;
    }

    /** @return ?list<int> the body's frame types, or null when it is not made of whole protocol frames */
    public function frameTypes(): ?array
    {
        try {
            return FrameCodec::messageTypes($this->body);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Whether this is the response the request should get: status 200, the protocol content type, and the expected
     * frames. A soak run counts the requests where it is not.
     */
    public function isExpectedFor(WorkloadRequest $request): bool
    {
        return $this->status === 200
            && str_starts_with($this->header('content-type') ?? '', 'application/vnd.restate.invocation.')
            && $this->frameTypes() === $request->expectedFrames;
    }

    /** For an assertion message. */
    public function describe(): string
    {
        $types = $this->frameTypes();

        return sprintf(
            'HTTP %d, content-type %s, frames %s',
            $this->status,
            $this->header('content-type') ?? 'none',
            $types === null ? 'unparseable: ' . substr($this->body, 0, 200) : '[' . implode(', ', array_map(static fn (int $type): string => sprintf('0x%04x', $type), $types)) . ']',
        );
    }
}
