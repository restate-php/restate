<?php

declare(strict_types=1);

namespace Restate\Internal\Client;

use Restate\Serde;

/**
 * The bytes of a binary handler payload as a string, unchanged, with the handler's content type.
 *
 * @internal
 */
final readonly class BytesSerde implements Serde
{
    public function __construct(private string $contentType)
    {
    }

    #[\Override]
    public function serialize(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('A binary payload must be a string, got ' . get_debug_type($value) . '.');
        }

        return $value;
    }

    #[\Override]
    public function deserialize(string $bytes): string
    {
        return $bytes;
    }

    #[\Override]
    public function contentType(): string
    {
        return $this->contentType;
    }
}
