<?php

declare(strict_types=1);

namespace Restate\Reflection\Internal;

use ReflectionClass;

/**
 * The reflected JSON Wire Shape of a Mapping-Safe DTO: how it is constructed and its fields.
 *
 * @internal
 */
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
