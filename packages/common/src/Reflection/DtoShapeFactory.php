<?php

declare(strict_types=1);

namespace Restate\Reflection;

use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Restate\JsonSchema\Attributes\JsonName;

final class DtoShapeFactory
{
    /**
     * @param ReflectionClass<object> $class
     * @param array<class-string, true> $seen
     */
    public static function shapeFor(ReflectionClass $class, array $seen = []): ?DtoShape
    {
        $name = $class->getName();
        if (isset($seen[$name]) || !$class->isInstantiable() || $class->isEnum() || $class->isInternal()) {
            return null;
        }

        $seen[$name] = true;

        return self::constructorShapeFor($class, $seen) ?? self::propertyShapeFor($class, $seen);
    }

    public static function supportsType(?ReflectionType $type): bool
    {
        return $type !== null && self::typeIsSupported($type, []);
    }

    /**
     * @param ReflectionClass<object> $class
     * @param array<class-string, true> $seen
     */
    private static function constructorShapeFor(ReflectionClass $class, array $seen): ?DtoShape
    {
        $constructor = $class->getConstructor();
        if ($constructor === null || !$constructor->isPublic()) {
            return null;
        }

        $fields = [];
        $mappedProperties = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$parameter->isPromoted() || $type === null) {
                return null;
            }

            $property = $class->getProperty($parameter->getName());
            if (!$property->isPublic() || !self::typeIsSupported($type, $seen)) {
                return null;
            }

            $fields[] = new DtoField(
                $parameter->getName(),
                $type,
                !$parameter->isDefaultValueAvailable(),
                $property,
                $parameter,
                self::jsonNameFor($property, $parameter),
            );
            $mappedProperties[$property->getName()] = true;
        }

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic() && $property->hasType() && !isset($mappedProperties[$property->getName()])) {
                return null;
            }
        }

        return new DtoShape($class, DtoShape::MODE_CONSTRUCTOR, $fields);
    }

    /**
     * @param ReflectionClass<object> $class
     * @param array<class-string, true> $seen
     */
    private static function propertyShapeFor(ReflectionClass $class, array $seen): ?DtoShape
    {
        $constructor = $class->getConstructor();
        if ($constructor !== null && !$constructor->isPublic()) {
            return null;
        }

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                if (!$parameter->isDefaultValueAvailable()) {
                    return null;
                }
            }
        }

        $properties = array_filter(
            $class->getProperties(ReflectionProperty::IS_PUBLIC),
            static fn (ReflectionProperty $property): bool => !$property->isStatic(),
        );
        usort($properties, static fn (ReflectionProperty $a, ReflectionProperty $b): int => $a->getName() <=> $b->getName());

        $fields = [];
        foreach ($properties as $property) {
            $type = $property->getType();
            if ($type === null || !self::typeIsSupported($type, $seen)) {
                return null;
            }

            $fields[] = new DtoField($property->getName(), $type, !$property->hasDefaultValue(), $property, jsonName: self::jsonNameFor($property));
        }

        if ($fields === []) {
            return null;
        }

        return new DtoShape($class, DtoShape::MODE_PROPERTY, $fields);
    }

    private static function jsonNameFor(ReflectionProperty $property, ?ReflectionParameter $parameter = null): string
    {
        $attributes = $parameter?->getAttributes(JsonName::class) ?? [];
        if ($attributes === []) {
            $attributes = $property->getAttributes(JsonName::class);
        }

        return $attributes === [] ? $property->getName() : $attributes[0]->newInstance()->name;
    }

    /** @param array<class-string, true> $seen */
    private static function typeIsSupported(ReflectionType $type, array $seen): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return self::namedTypeIsSupported($type, $seen);
        }

        if ($type instanceof ReflectionUnionType) {
            $mappedArmCount = 0;
            $hasNonNullBuiltinArm = false;
            foreach ($type->getTypes() as $arm) {
                if (!$arm instanceof ReflectionNamedType || !self::namedTypeIsSupported($arm, $seen)) {
                    return false;
                }

                if ($arm->isBuiltin()) {
                    if ($arm->getName() !== 'null') {
                        $hasNonNullBuiltinArm = true;
                    }

                    continue;
                }

                ++$mappedArmCount;
                if ($mappedArmCount > 1 || $hasNonNullBuiltinArm) {
                    return false;
                }
            }

            return $mappedArmCount === 0 || !$hasNonNullBuiltinArm;
        }

        return false;
    }

    /** @param array<class-string, true> $seen */
    private static function namedTypeIsSupported(ReflectionNamedType $type, array $seen): bool
    {
        $name = $type->getName();

        if (match ($name) {
            'string', 'int', 'float', 'bool', 'null', 'true', 'false' => true,
            default => false,
        }) {
            return true;
        }

        if ($type->isBuiltin()) {
            return false;
        }

        if (enum_exists($name)) {
            return new ReflectionEnum($name)->isBacked();
        }

        if (!class_exists($name)) {
            return false;
        }

        return self::shapeFor(new ReflectionClass($name), $seen) !== null;
    }
}
