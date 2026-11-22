<?php

declare(strict_types=1);

namespace Restate\JsonSchema\Attributes;

use InvalidArgumentException;

#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final class JsonName
{
    public function __construct(
        public readonly string $name,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('JSON field name must not be empty.');
        }
    }
}
