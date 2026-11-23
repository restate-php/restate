<?php

declare(strict_types=1);

namespace Restate\Reflection;

use BackedEnum;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Restate\JsonMappingException;
use Restate\JsonValueMapper;
use Restate\JsonValueMappingContext;
use Restate\Reflection\Internal\DtoShape;
use Restate\Reflection\Internal\DtoShapeFactory;

final class BuiltinJsonValueMapper implements JsonValueMapper
{
    #[\Override]
    public function supports(JsonValueMappingContext $context): bool
    {
        return self::supportsType($context->type);
    }

    #[\Override]
    public function hydrate(mixed $value, JsonValueMappingContext $context): mixed
    {
        return $this->mapValue($value, $context, true);
    }

    #[\Override]
    public function normalize(mixed $value, JsonValueMappingContext $context): mixed
    {
        return $this->mapValue($value, $context, false);
    }

    public static function requiresMapping(?ReflectionType $type): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return self::namedTypeRequiresMapping($type);
        }

        if ($type instanceof ReflectionUnionType) {
            if (self::supportsType($type)) {
                return false;
            }

            foreach ($type->getTypes() as $arm) {
                if ($arm instanceof ReflectionNamedType && !$arm->isBuiltin() && strcasecmp($arm->getName(), \stdClass::class) !== 0 && (class_exists($arm->getName()) || enum_exists($arm->getName()))) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function namedTypeRequiresMapping(ReflectionNamedType $type): bool
    {
        $name = $type->getName();

        return !$type->isBuiltin() && strcasecmp($name, \stdClass::class) !== 0 && !DtoShapeFactory::supportsType($type);
    }

    private static function supportsType(?ReflectionType $type): bool
    {
        return DtoShapeFactory::supportsType($type);
    }

    private function mapValue(mixed $value, JsonValueMappingContext $context, bool $hydrate): mixed
    {
        $type = $context->type;
        if ($type instanceof ReflectionNamedType) {
            return $this->mapNamedValue($value, $type, $context, $hydrate);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $arm) {
                if (!$arm instanceof ReflectionNamedType) {
                    continue;
                }
                try {
                    return $this->mapNamedValue($value, $arm, $context, $hydrate);
                } catch (JsonMappingException) {
                }
            }
        }

        throw JsonMappingException::at($context->path, 'unsupported JSON value mapping type');
    }

    private function mapNamedValue(mixed $value, ReflectionNamedType $type, JsonValueMappingContext $context, bool $hydrate): mixed
    {
        if ($value === null && $type->allowsNull()) {
            return null;
        }

        $name = $type->getName();

        if (!$type->isBuiltin()) {
            if (enum_exists($name)) {
                return $hydrate
                    ? $this->hydrateBackedEnum($value, $name, $context)
                    : $this->normalizeBackedEnum($value, $name, $context);
            }

            if (class_exists($name)) {
                return $hydrate
                    ? $this->hydrateDto($value, $name, $context)
                    : $this->normalizeDto($value, $name, $context);
            }
        }

        return match ($name) {
            'string' => is_string($value) ? $value : throw $this->typeError($context, 'string', $value),
            'int' => is_int($value) ? $value : throw $this->typeError($context, 'integer', $value),
            'float' => is_int($value) || is_float($value) ? (float) $value : throw $this->typeError($context, 'number', $value),
            'bool' => is_bool($value) ? $value : throw $this->typeError($context, 'boolean', $value),
            'null' => $value === null ? null : throw $this->typeError($context, 'null', $value),
            'true' => $value === true ? true : throw $this->typeError($context, 'true', $value),
            'false' => $value === false ? false : throw $this->typeError($context, 'false', $value),
            default => throw JsonMappingException::at($context->path, 'unsupported JSON value mapping type'),
        };
    }

    /** @param class-string<\UnitEnum> $enum */
    private function hydrateBackedEnum(mixed $value, string $enum, JsonValueMappingContext $context): BackedEnum
    {
        $reflection = new ReflectionEnum($enum);
        $backingType = $reflection->getBackingType()?->getName();
        if (($backingType === 'int' && is_int($value)) || ($backingType === 'string' && is_string($value))) {
            foreach ($reflection->getCases() as $case) {
                if ($case instanceof ReflectionEnumBackedCase && $case->getBackingValue() === $value) {
                    $caseValue = $case->getValue();
                    if ($caseValue instanceof BackedEnum) {
                        return $caseValue;
                    }
                }
            }
        }

        throw JsonMappingException::at($context->path, 'expected one of ' . implode(', ', $this->backingValues($reflection)) . ', got ' . gettype($value));
    }

    /** @param class-string<\UnitEnum> $enum */
    private function normalizeBackedEnum(mixed $value, string $enum, JsonValueMappingContext $context): int|string
    {
        if (!$value instanceof $enum || !$value instanceof BackedEnum) {
            throw JsonMappingException::at($context->path, 'expected ' . $enum . ', got ' . gettype($value));
        }

        return $value->value;
    }

    /**
     * @param ReflectionEnum<\UnitEnum> $enum
     * @return list<string>
     */
    private function backingValues(ReflectionEnum $enum): array
    {
        $values = [];
        foreach ($enum->getCases() as $case) {
            if ($case instanceof ReflectionEnumBackedCase) {
                $values[] = (string) $case->getBackingValue();
            }
        }

        return $values;
    }

    /** @param class-string $class */
    private function hydrateDto(mixed $value, string $class, JsonValueMappingContext $context): object
    {
        $fromObject = $value instanceof \stdClass;
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        if (!is_array($value) || (!$fromObject && array_is_list($value))) {
            throw $this->typeError($context, 'object', $value);
        }

        $shape = DtoShapeFactory::shapeFor(new ReflectionClass($class));
        if ($shape === null) {
            throw JsonMappingException::at($context->path, 'unsupported JSON value mapping type');
        }

        if ($shape->mode === DtoShape::MODE_CONSTRUCTOR) {
            $arguments = [];
            foreach ($shape->fields as $field) {
                if (!array_key_exists($field->jsonName, $value)) {
                    if ($field->required) {
                        throw JsonMappingException::at($context->at($field->jsonName, $field->type)->path, 'required field is missing');
                    }

                    continue;
                }

                $arguments[$field->name] = $this->mapValue($value[$field->jsonName], $context->at($field->jsonName, $field->type), true);
            }

            return $shape->class->newInstanceArgs($arguments);
        }

        $instance = $shape->class->newInstance();
        foreach ($shape->fields as $field) {
            if (!array_key_exists($field->jsonName, $value)) {
                if ($field->required) {
                    throw JsonMappingException::at($context->at($field->jsonName, $field->type)->path, 'required field is missing');
                }

                continue;
            }

            $field->property->setValue($instance, $this->mapValue($value[$field->jsonName], $context->at($field->jsonName, $field->type), true));
        }

        return $instance;
    }

    /**
     * @param class-string $class
     * @return array<string, mixed>|\stdClass an object without fields is an empty \stdClass, so it encodes as {} rather than []
     */
    private function normalizeDto(mixed $value, string $class, JsonValueMappingContext $context): array|\stdClass
    {
        if (!$value instanceof $class) {
            throw JsonMappingException::at($context->path, 'expected ' . $class . ', got ' . gettype($value));
        }

        $shape = DtoShapeFactory::shapeFor(new ReflectionClass($class));
        if ($shape === null) {
            throw JsonMappingException::at($context->path, 'unsupported JSON value mapping type');
        }

        $normalized = [];
        foreach ($shape->fields as $field) {
            $fieldContext = $context->at($field->jsonName, $field->type);
            if (!$field->property->isInitialized($value)) {
                throw JsonMappingException::at($fieldContext->path, 'field is uninitialized');
            }

            $normalized[$field->jsonName] = $this->mapValue($field->property->getValue($value), $fieldContext, false);
        }

        return $normalized === [] ? new \stdClass() : $normalized;
    }

    private function typeError(JsonValueMappingContext $context, string $expected, mixed $value): JsonMappingException
    {
        return JsonMappingException::at($context->path, "expected {$expected}, got " . gettype($value));
    }
}
