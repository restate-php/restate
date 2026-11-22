<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Common\Fixtures;

use Restate\Serde;

/** A Custom Serializer: a string as `text:<value>`. */
final class TaggedTextSerde implements Serde
{
    public function serialize(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('TaggedTextSerde writes strings only.');
        }

        return 'text:' . $value;
    }

    public function deserialize(string $bytes): string
    {
        if (!str_starts_with($bytes, 'text:')) {
            throw new \InvalidArgumentException("Not tagged text: {$bytes}");
        }

        return substr($bytes, 5);
    }

    public function contentType(): string
    {
        return 'text/plain';
    }
}
