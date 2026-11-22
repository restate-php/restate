<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark\Support;

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers every request with the same response and keeps nothing, so the ingress benchmarks
 * measure the client's own work (request building, serialization, response decoding) and not the network.
 */
final class FixedResponseHttpClient implements ClientInterface
{
    private readonly HttpFactory $factory;

    /** The last request sent, to check what the client built. */
    public ?RequestInterface $last = null;

    public function __construct(private readonly int $status, private readonly string $json)
    {
        $this->factory = new HttpFactory();
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->last = $request;

        return $this->factory->createResponse($this->status)
            ->withHeader('content-type', 'application/json')
            ->withBody($this->factory->createStream($this->json));
    }
}
