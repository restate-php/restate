<?php

declare(strict_types=1);

namespace Restate\Ingress;

use Restate\Ingress\Internal\Transport;
use Restate\Serde;

/**
 * An invocation the client can wait for or read the output of: by invocation id ({@see IngressClient::invocation()},
 * {@see SendResponse::invocation()}), by the idempotency key it was sent with
 * ({@see IngressClient::idempotentInvocation()}), or a workflow run ({@see IngressClient::workflowInvocation()}).
 *
 * Restate keeps a completed invocation's result for a retention period (configurable per service and handler); past
 * it, Restate no longer finds the invocation and both methods fail with status 404.
 *
 * @template-covariant T = mixed the result as the serde this handle was made with decodes it
 */
final readonly class Invocation
{
    /**
     * @internal Built by {@see IngressClient}.
     *
     * @param string|\Closure(): string $path the invocation's ingress path without `/attach` or `/output`, or what
     *                                       finds it when needed (a scoped target is looked up by its id first)
     */
    public function __construct(
        private Transport $transport,
        private string|\Closure $path,
        private ?Serde $serde = null,
    ) {
    }

    /**
     * Waits for the invocation to complete and returns its result (`GET …/attach`).
     *
     * @param ?Serde $serde decodes the result; default: the serde this handle was made with, else the client's
     *
     * @return ($serde is null ? T : mixed)
     *
     * @throws \Restate\TerminalError the invocation's failure
     * @throws IngressException
     */
    public function attach(?Serde $serde = null): mixed
    {
        return $this->transport->attach($this->path(), $serde ?? $this->serde ?? $this->transport->serde);
    }

    /**
     * Reads the invocation's output without waiting (`GET …/output`): not ready while it still runs.
     *
     * @param ?Serde $serde decodes the result; default: the serde this handle was made with, else the client's
     *
     * @return ($serde is null ? Output<T> : Output<mixed>)
     *
     * @throws \Restate\TerminalError the invocation's failure
     * @throws IngressException
     */
    public function output(?Serde $serde = null): Output
    {
        return $this->transport->output($this->path(), $serde ?? $this->serde ?? $this->transport->serde);
    }

    private function path(): string
    {
        return is_string($this->path) ? $this->path : ($this->path)();
    }
}
