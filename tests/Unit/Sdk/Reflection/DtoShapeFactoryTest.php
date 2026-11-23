<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\Reflection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionType;
use Restate\JsonSchema\Attributes\JsonName;
use Restate\Reflection\Internal\DtoField;
use Restate\Reflection\Internal\DtoShape;
use Restate\Reflection\Internal\DtoShapeFactory;

enum DtoShapeFactoryRole: string
{
    case Admin = 'admin';
    case Member = 'member';
}

final readonly class DtoShapeFactoryProfile
{
    public function __construct(public string $displayName)
    {
    }
}

final readonly class DtoShapeFactoryConstructorUser
{
    public function __construct(
        #[JsonName('user_name')]
        public string $name,
        public ?string $email,
        public DtoShapeFactoryRole $role = DtoShapeFactoryRole::Member,
        public ?DtoShapeFactoryProfile $profile = null,
        public string|int|null $externalId = null,
    ) {
    }
}

final class DtoShapeFactoryPropertyUser
{
    #[JsonName('user_name')]
    public string $name;

    public ?int $age = null;
}

final readonly class DtoShapeFactoryArrayField
{
    /** @param list<string> $items */
    public function __construct(public array $items)
    {
    }
}

final readonly class DtoShapeFactoryObjectField
{
    public function __construct(public object $metadata)
    {
    }
}

final readonly class DtoShapeFactoryMixedField
{
    public function __construct(public mixed $value)
    {
    }
}

final readonly class DtoShapeFactoryRecursiveNode
{
    public function __construct(public ?DtoShapeFactoryRecursiveNode $parent)
    {
    }
}

final readonly class DtoShapeFactoryUnionA
{
    public function __construct(public string $name)
    {
    }
}

final readonly class DtoShapeFactoryUnionB
{
    public function __construct(public string $name)
    {
    }
}

final readonly class DtoShapeFactoryAmbiguousUnion
{
    public function __construct(public DtoShapeFactoryUnionA|DtoShapeFactoryUnionB $value)
    {
    }
}

final readonly class DtoShapeFactoryClassScalarUnion
{
    public function __construct(public DtoShapeFactoryUnionA|string $value)
    {
    }
}

final class DtoShapeFactoryUntypedProperty
{
    /** @var mixed */
    public $name;
}

final class DtoShapeFactoryRequiredConstructorProperty
{
    public string $name;

    public function __construct(string $id)
    {
        $this->name = $id;
    }
}

final class DtoShapeFactoryTypeFixture
{
    public function scalarUnion(string|int|null $value): void
    {
    }

    public function constructorDto(DtoShapeFactoryConstructorUser $value): void
    {
    }

    public function nullableDto(?DtoShapeFactoryConstructorUser $value): void
    {
    }

    public function ambiguousDtoUnion(DtoShapeFactoryUnionA|DtoShapeFactoryUnionB $value): void
    {
    }

    public function classScalarUnion(DtoShapeFactoryUnionA|string $value): void
    {
    }
}

final class DtoShapeFactoryTest extends TestCase
{
    /**
     * @param class-string $className
     * @param list<string> $fieldNames
     * @param list<string> $jsonNames
     */
    #[DataProvider('mappingSafeDtoProvider')]
    public function testBuildsShapeForMappingSafeDtos(string $className, string $mode, array $fieldNames, array $jsonNames): void
    {
        $shape = DtoShapeFactory::shapeFor(new ReflectionClass($className));

        self::assertNotNull($shape);
        self::assertSame($mode, $shape->mode);
        self::assertSame($fieldNames, array_map(static fn (DtoField $field): string => $field->name, $shape->fields));
        self::assertSame($jsonNames, array_map(static fn (DtoField $field): string => $field->jsonName, $shape->fields));
    }

    /** @return iterable<string, array{class-string, string, list<string>, list<string>}> */
    public static function mappingSafeDtoProvider(): iterable
    {
        yield 'constructor dto' => [
            DtoShapeFactoryConstructorUser::class,
            DtoShape::MODE_CONSTRUCTOR,
            ['name', 'email', 'role', 'profile', 'externalId'],
            ['user_name', 'email', 'role', 'profile', 'externalId'],
        ];

        yield 'property dto' => [
            DtoShapeFactoryPropertyUser::class,
            DtoShape::MODE_PROPERTY,
            ['age', 'name'],
            ['age', 'user_name'],
        ];
    }

    /** @param class-string $className */
    #[DataProvider('mappingUnsafeDtoProvider')]
    public function testRejectsMappingUnsafeDtos(string $className): void
    {
        self::assertNull(DtoShapeFactory::shapeFor(new ReflectionClass($className)));
    }

    /** @return iterable<string, array{class-string}> */
    public static function mappingUnsafeDtoProvider(): iterable
    {
        yield 'native array field' => [DtoShapeFactoryArrayField::class];
        yield 'object field' => [DtoShapeFactoryObjectField::class];
        yield 'mixed field' => [DtoShapeFactoryMixedField::class];
        yield 'recursive dto' => [DtoShapeFactoryRecursiveNode::class];
        yield 'ambiguous dto union' => [DtoShapeFactoryAmbiguousUnion::class];
        yield 'class scalar union' => [DtoShapeFactoryClassScalarUnion::class];
        yield 'untyped property' => [DtoShapeFactoryUntypedProperty::class];
        yield 'required constructor property dto' => [DtoShapeFactoryRequiredConstructorProperty::class];
    }

    #[DataProvider('typeSupportProvider')]
    public function testClassifiesMappingSafeTypes(string $method, bool $supported): void
    {
        self::assertSame($supported, DtoShapeFactory::supportsType($this->parameterType($method)));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function typeSupportProvider(): iterable
    {
        yield 'scalar union' => ['scalarUnion', true];
        yield 'constructor dto' => ['constructorDto', true];
        yield 'nullable dto' => ['nullableDto', true];
        yield 'ambiguous dto union' => ['ambiguousDtoUnion', false];
        yield 'class scalar union' => ['classScalarUnion', false];
    }

    private function parameterType(string $method): ReflectionType
    {
        return new ReflectionMethod(DtoShapeFactoryTypeFixture::class, $method)->getParameters()[0]->getType()
            ?? throw new \RuntimeException('Missing parameter type.');
    }
}
