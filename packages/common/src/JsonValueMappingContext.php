<?php

declare(strict_types=1);

namespace Restate;

use ReflectionType;

final readonly class JsonValueMappingContext
{
    public function __construct(
        public readonly ?ReflectionType $type,
        public readonly PayloadRole $role,
        public readonly string $serviceName,
        public readonly string $handlerName,
        public readonly string $path = '$',
    ) {
    }

    public function at(string $field, ?ReflectionType $type = null): self
    {
        return new self(
            $type ?? $this->type,
            $this->role,
            $this->serviceName,
            $this->handlerName,
            $field === '' ? $this->path : $this->path . '.' . $field,
        );
    }
}
