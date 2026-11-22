<?php

declare(strict_types=1);

namespace Restate\Reflection;

use ReflectionClass;

final readonly class DtoShape
{
    public const string MODE_CONSTRUCTOR = 'constructor';
    public const string MODE_PROPERTY = 'property';

    /**
     * @param ReflectionClass<object> $class
     * @param list<DtoField> $fields
     */
    public function __construct(
        public readonly ReflectionClass $class,
        public readonly string $mode,
        public readonly array $fields,
    ) {
    }
}
