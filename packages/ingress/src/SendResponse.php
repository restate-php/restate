<?php

declare(strict_types=1);

namespace Restate\Ingress;

use Restate\Serde;

/**
 * Restate's answer to a send: the id of the invocation it accepted, whether it is new, and a handle to it.
 *
 * @template-covariant T = mixed the invocation's result as the sending request's output serde decodes it
 */
final readonly class SendResponse
{
    /** @param Invocation<T> $invocation */
    public function __construct(
        private string $invocationId,
        private SendStatus $status,
        private Invocation $invocation,
    ) {
    }

    public function invocationId(): string
    {
        return $this->invocationId;
    }

    public function status(): SendStatus
    {
        return $this->status;
    }

    /**
     * The sent invocation; decodes with the output serde of the request that sent it.
     *
     * @return Invocation<T>
     */
    public function invocation(): Invocation
    {
        return $this->invocation;
    }

    /**
     * Waits for the sent invocation to complete and returns its result.
     *
     * @return ($serde is null ? T : mixed)
     *
     * @throws \Restate\TerminalError the invocation's failure
     * @throws IngressException
     */
    public function attach(?Serde $serde = null): mixed
    {
        return $this->invocation->attach($serde);
    }

    /**
     * Reads the sent invocation's output without waiting.
     *
     * @return ($serde is null ? Output<T> : Output<mixed>)
     *
     * @throws \Restate\TerminalError the invocation's failure
     * @throws IngressException
     */
    public function output(?Serde $serde = null): Output
    {
        return $this->invocation->output($serde);
    }
}
