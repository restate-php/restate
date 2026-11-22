<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Ingress;

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that records every request and answers from a queue of responses (or throws a queued transport
 * failure).
 */
final class RecordingHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $responses = [];

    /** @param array<string, string> $headers */
    public function respond(int $status, string $body = '', array $headers = []): self
    {
        $response = new HttpFactory()->createResponse($status)->withBody(new HttpFactory()->createStream($body));
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $this->responses[] = $response;

        return $this;
    }

    public function respondJson(int $status, string $json): self
    {
        return $this->respond($status, $json, ['content-type' => 'application/json']);
    }

    public function fail(ClientExceptionInterface $exception): self
    {
        $this->responses[] = $exception;

        return $this;
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->responses) ?? throw new \LogicException('No response queued for ' . $request->getMethod() . ' ' . $request->getUri());

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests) ?? throw new \LogicException('No request was sent.')];
    }
}
