<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class PsrTestResponse implements ResponseInterface
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        private int $statusCode = 200,
        private array $headers = [],
        private StreamInterface $body = new PsrTestStream(),
        private string $reasonPhrase = '',
        private string $protocolVersion = '1.1',
    ) {
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    #[\Override]
    public function withProtocolVersion(string $version): ResponseInterface
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;

        return $clone;
    }

    /** @return array<string, list<string>> */
    #[\Override]
    public function getHeaders(): array
    {
        return $this->headers;
    }

    #[\Override]
    public function hasHeader(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->headers);
    }

    /** @return list<string> */
    #[\Override]
    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    #[\Override]
    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    #[\Override]
    public function withHeader(string $name, $value): ResponseInterface
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = is_array($value) ? array_values(array_map('strval', $value)) : [$value];

        return $clone;
    }

    #[\Override]
    public function withAddedHeader(string $name, $value): ResponseInterface
    {
        $clone = clone $this;
        $key = strtolower($name);
        $values = is_array($value) ? array_values(array_map('strval', $value)) : [$value];
        $clone->headers[$key] = [...($clone->headers[$key] ?? []), ...$values];

        return $clone;
    }

    #[\Override]
    public function withoutHeader(string $name): ResponseInterface
    {
        $clone = clone $this;
        unset($clone->headers[strtolower($name)]);

        return $clone;
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    #[\Override]
    public function withBody(StreamInterface $body): ResponseInterface
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    #[\Override]
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    #[\Override]
    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->reasonPhrase = $reasonPhrase;

        return $clone;
    }

    #[\Override]
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }
}
