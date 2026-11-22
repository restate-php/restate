<?php

declare(strict_types=1);

namespace Restate\Ingress;

use Restate\Exception;

/**
 * A request to the Restate ingress failed for a reason other than the invocation's own failure: the request never got
 * a response (no status), the ingress refused it (an unknown service, a malformed id, an invocation that does not
 * exist), something between the client and Restate answered with an error, or the response could not be decoded.
 *
 * When the invocation itself failed, the client throws its {@see \Restate\TerminalError} instead, as a call from a
 * handler does.
 */
class IngressException extends \RuntimeException implements Exception
{
    public function __construct(
        string $message,
        private readonly string $requestMethod,
        private readonly string $requestUri,
        private readonly ?int $statusCode = null,
        private readonly ?string $responseBody = null,
        private readonly ?string $errorMessage = null,
        private readonly ?string $errorSource = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function requestMethod(): string
    {
        return $this->requestMethod;
    }

    public function requestUri(): string
    {
        return $this->requestUri;
    }

    /** The HTTP status of the response; null when no response was received (a transport failure). */
    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    /** The response body as received; null when no response was received. */
    public function responseBody(): ?string
    {
        return $this->responseBody;
    }

    /** The `message` of Restate's JSON error body, if the response had one. */
    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Whose error Restate says it is (the `x-restate-error-source` header or the `source` of the error body), for
     * example "ingress"; null when the response did not say.
     */
    public function errorSource(): ?string
    {
        return $this->errorSource;
    }
}
