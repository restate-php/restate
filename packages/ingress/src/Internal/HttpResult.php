<?php

declare(strict_types=1);

namespace Restate\Ingress\Internal;

use Restate\Ingress\IngressException;

/**
 * A response of the Restate ingress, read in full, with the request it answers.
 *
 * @internal
 */
final readonly class HttpResult
{
    /** @param string $errorSource the `x-restate-error-source` header, or '' */
    public function __construct(
        public string $method,
        public string $uri,
        public int $status,
        public string $body,
        public string $errorSource = '',
    ) {
    }

    /** An exception about this response: "Restate ingress request GET … <problem>". */
    public function exception(string $problem, ?string $errorMessage = null, ?string $errorSource = null, ?\Throwable $previous = null): IngressException
    {
        return new IngressException(
            sprintf('Restate ingress request %s %s %s', $this->method, $this->uri, $problem),
            $this->method,
            $this->uri,
            $this->status,
            $this->body,
            $errorMessage,
            $errorSource,
            $previous,
        );
    }
}
