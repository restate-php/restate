<?php

declare(strict_types=1);

namespace Restate;

final class JsonSerde implements Serde
{
    public static function default(): self
    {
        return new self();
    }

    #[\Override]
    public function serialize(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    #[\Override]
    public function deserialize(string $bytes): mixed
    {
        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    }

    #[\Override]
    public function contentType(): string
    {
        return 'application/json';
    }
}
