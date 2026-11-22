<?php

declare(strict_types=1);

namespace Restate;

use InvalidArgumentException;
use ReflectionType;

final readonly class JsonValueMappingContext
{
    public const string ROLE_INPUT = 'input';
    public const string ROLE_OUTPUT = 'output';

    public function __construct(
        public readonly ?ReflectionType $type,
        public readonly string $role,
        public readonly string $serviceName,
        public readonly string $handlerName,
        public readonly string $path = '$',
    ) {
        if (!in_array($role, [self::ROLE_INPUT, self::ROLE_OUTPUT], true)) {
            throw new InvalidArgumentException("Invalid JSON value mapping role {$role}.");
        }
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
