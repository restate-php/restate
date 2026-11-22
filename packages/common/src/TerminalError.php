<?php

declare(strict_types=1);

namespace Restate;

use Restate\Internal\TerminalMetadata;

class TerminalError extends \RuntimeException implements Exception
{
    /** @var array<array-key, string> */
    private readonly array $metadata;

    /**
     * @param array<array-key, mixed> $metadata string values; numeric-string keys such as '404' are allowed (PHP stores them as ints)
     * @param ?\Throwable $previous the exception this one classifies, for example in an {@see ErrorMapper}
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 500,
        array $metadata = [],
        ?\Throwable $previous = null,
    ) {
        if ($statusCode < 400 || $statusCode > 599) {
            throw new \InvalidArgumentException('Terminal status code must be between 400 and 599.');
        }

        if (!TerminalMetadata::isValid($metadata)) {
            throw new \InvalidArgumentException('Terminal metadata must be a string-to-string map.');
        }

        $this->metadata = $metadata;

        parent::__construct($message, $statusCode, $previous);
    }

    public function code(): int
    {
        return $this->statusCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * The metadata map. Keys are strings on the wire; PHP holds numeric-string keys such as "404" as int keys.
     *
     * @return array<array-key, string>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
