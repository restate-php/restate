<?php

declare(strict_types=1);

namespace Restate;

interface Serde
{
    public function serialize(mixed $value): string;

    public function deserialize(string $bytes): mixed;

    public function contentType(): string;
}
