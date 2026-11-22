<?php

declare(strict_types=1);

namespace Restate\Reflection;

use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

final readonly class DtoField
{
    public readonly string $jsonName;

    public function __construct(
        public readonly string $name,
        public readonly ReflectionType $type,
        public readonly bool $required,
        public readonly ReflectionProperty $property,
        public readonly ?ReflectionParameter $parameter = null,
        ?string $jsonName = null,
    ) {
        $this->jsonName = $jsonName ?? $name;
    }
}
