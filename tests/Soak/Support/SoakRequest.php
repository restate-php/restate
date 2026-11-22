<?php

declare(strict_types=1);

namespace Restate\Tests\Soak\Support;

use Restate\Tests\Support\Workload\WorkloadRequest;
use Restate\Tests\Support\Workload\WorkloadResponse;

/** One request of a soak run: what to send, how to sign it, and what a correct answer is. */
final readonly class SoakRequest
{
    /**
     * @param 'valid'|'forged'|'none' $signing Restate's request identity ({@see SoakIdentity}), a forged one, or none
     * @param ?\Closure(WorkloadResponse): bool $expect null: the request's expected frames
     *                                                  ({@see WorkloadResponse::isExpectedFor()})
     * @param list<array{0: string, 1: string}> $headers more headers to send
     */
    public function __construct(
        public WorkloadRequest $request,
        public string $signing = 'valid',
        public ?\Closure $expect = null,
        public ?string $labelOverride = null,
        public array $headers = [],
    ) {
    }

    /** A request whose identity is forged: the endpoint must answer 401. */
    public static function forged(WorkloadRequest $request): self
    {
        return new self($request, 'forged', static fn (WorkloadResponse $response): bool => $response->status === 401, 'forged-identity');
    }

    public function isExpected(WorkloadResponse $response): bool
    {
        return $this->expect === null ? $response->isExpectedFor($this->request) : ($this->expect)($response);
    }

    /** The request class, for counts and failure messages. */
    public function label(): string
    {
        return $this->labelOverride ?? $this->request->class;
    }
}
