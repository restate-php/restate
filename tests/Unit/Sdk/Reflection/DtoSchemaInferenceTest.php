<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\Reflection;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Restate\JsonSchema;
use Restate\JsonSchema\Attributes\JsonName;
use Restate\JsonSchema\Attributes\RawSchema;
use Restate\JsonSchema\Schema;
use Restate\JsonSchema\Generation\ProvidesSchema;
use Restate\JsonSchema\Generation\SchemaGenerator;
use Restate\Reflection\Internal\JsonSchemaInferer;

enum DtoSchemaRole: string
{
    case Admin = 'admin';
    case Member = 'member';
}

enum DtoSchemaRank: int
{
    case One = 1;
    case Two = 2;
}

final readonly class DtoSchemaProfile
{
    public function __construct(public string $displayName)
    {
    }
}

final readonly class DtoSchemaConstructorUser
{
    public function __construct(
        public string $name,
        public ?string $email,
        public DtoSchemaRole $role = DtoSchemaRole::Member,
        public DtoSchemaProfile $profile = new DtoSchemaProfile('default'),
    ) {
    }
}

final class DtoSchemaPropertyUser
{
    public string $name;
    public ?int $age = null;
    public DtoSchemaRank $rank = DtoSchemaRank::One;
    public ?DtoSchemaRole $role = null;
}

final class DtoSchemaPropertyWithRequiredConstructor
{
    public string $name;

    public function __construct(string $id)
    {
        $this->name = $id;
    }
}

final class DtoSchemaStaticPropertyUser
{
    public string $name;
    public static string $kind = 'user';
}

final class DtoSchemaConstructorWithStaticProperty
{
    public static string $kind = 'user';

    public function __construct(public string $name)
    {
    }
}

final readonly class DtoSchemaUnsupportedArray
{
    /** @param list<string> $items */
    public function __construct(public array $items)
    {
    }
}

final readonly class DtoSchemaRecursiveNode
{
    public function __construct(public ?DtoSchemaRecursiveNode $parent)
    {
    }
}

#[RawSchema([
    'title' => 'Annotated contact',
    'description' => 'A contact payload.',
    'type' => 'object',
    'properties' => [
        'name' => ['description' => 'Display name.', 'type' => 'string', 'minLength' => 2],
    ],
    'required' => ['name'],
])]
final readonly class DtoSchemaAnnotatedContact
{
    public function __construct(
        public string $name,
    ) {
    }
}

final readonly class DtoSchemaAnnotatedArray
{
    /** @param list<string> $items */
    public function __construct(
        #[RawSchema(['type' => 'array', 'items' => ['type' => 'string']])]
        public array $items,
    ) {
    }
}

final readonly class DtoSchemaCustomUnsupportedArray implements ProvidesSchema
{
    /** @param list<string> $items */
    public function __construct(public array $items)
    {
    }

    public static function jsonSchema(SchemaGenerator $generator): Schema
    {
        return JsonSchema::object(
            JsonSchema::array()->items(JsonSchema::string())->property('items')->required(),
        );
    }
}

final readonly class DtoSchemaAliasedUser
{
    public function __construct(
        #[JsonName('user_name')]
        public string $name,
    ) {
    }
}

final readonly class DtoSchemaCustomContact implements ProvidesSchema
{
    public function __construct(public string $ignored)
    {
    }

    public static function jsonSchema(SchemaGenerator $generator): Schema
    {
        return JsonSchema::object(
            JsonSchema::string()->property('custom')->required(),
        )->title('Custom contact');
    }
}

final class DtoSchemaFixture
{
    public function constructorDto(DtoSchemaConstructorUser $value): void
    {
    }
    public function nullableConstructorDto(?DtoSchemaConstructorUser $value): void
    {
    }
    public function nullableEnum(?DtoSchemaRole $value): void
    {
    }
    public function propertyDto(DtoSchemaPropertyUser $value): void
    {
    }
    public function propertyDtoWithRequiredConstructor(DtoSchemaPropertyWithRequiredConstructor $value): void
    {
    }
    public function staticPropertyDto(DtoSchemaStaticPropertyUser $value): void
    {
    }
    public function constructorDtoWithStaticProperty(DtoSchemaConstructorWithStaticProperty $value): void
    {
    }
    public function unsupportedArray(DtoSchemaUnsupportedArray $value): void
    {
    }
    public function recursiveNode(DtoSchemaRecursiveNode $value): void
    {
    }
    public function annotatedDto(DtoSchemaAnnotatedContact $value): void
    {
    }
    public function annotatedArray(DtoSchemaAnnotatedArray $value): void
    {
    }
    public function aliasedDto(DtoSchemaAliasedUser $value): void
    {
    }
    public function customDto(DtoSchemaCustomContact $value): void
    {
    }
    public function customUnsupportedArray(DtoSchemaCustomUnsupportedArray $value): void
    {
    }
}

final class DtoSchemaInferenceTest extends TestCase
{
    public function testInfersConstructorDtoSchema(): void
    {
        self::assertSame([
            '$defs' => [
                'DtoSchemaProfile' => [
                    'type' => 'object',
                    'properties' => ['displayName' => ['type' => 'string']],
                    'required' => ['displayName'],
                ],
            ],
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => ['string', 'null']],
                'role' => ['type' => 'string', 'enum' => ['admin', 'member']],
                'profile' => ['$ref' => '#/$defs/DtoSchemaProfile'],
            ],
            'required' => ['name', 'email'],
        ], JsonSchemaInferer::schemaFor($this->parameterType('constructorDto')));
    }

    public function testInfersNullableConstructorDtoSchema(): void
    {
        $schema = JsonSchemaInferer::schemaFor($this->parameterType('nullableConstructorDto'));

        self::assertIsArray($schema);
        self::assertSame(['object', 'null'], $schema['type']);
        self::assertSame(['name', 'email'], $schema['required']);
    }

    public function testInfersNullableBackedEnumSchema(): void
    {
        self::assertSame([
            'type' => ['string', 'null'],
            'enum' => ['admin', 'member', null],
        ], JsonSchemaInferer::schemaFor($this->parameterType('nullableEnum')));
    }

    public function testInfersPropertyDtoSchema(): void
    {
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => ['integer', 'null']],
                'rank' => ['type' => 'integer', 'enum' => [1, 2]],
                'role' => [
                    'anyOf' => [
                        ['type' => 'string', 'enum' => ['admin', 'member']],
                        ['type' => 'null'],
                    ],
                ],
            ],
            'required' => ['name'],
        ], JsonSchemaInferer::schemaFor($this->parameterType('propertyDto')));
    }

    public function testRejectsPropertyDtoWithRequiredConstructor(): void
    {
        self::assertTrue(JsonSchemaInferer::schemaFor($this->parameterType('propertyDtoWithRequiredConstructor')));
    }

    public function testIgnoresStaticPropertiesInPropertyDtoSchema(): void
    {
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ], JsonSchemaInferer::schemaFor($this->parameterType('staticPropertyDto')));
    }

    public function testIgnoresStaticPropertiesWhenValidatingConstructorDtoSchema(): void
    {
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ], JsonSchemaInferer::schemaFor($this->parameterType('constructorDtoWithStaticProperty')));
    }

    public function testBuiltinDtoInferenceUsesCanonicalJsonSchemaGenerator(): void
    {
        self::assertSame([
            'title' => 'Annotated contact',
            'description' => 'A contact payload.',
            'type' => 'object',
            'properties' => [
                'name' => ['description' => 'Display name.', 'type' => 'string', 'minLength' => 2],
            ],
            'required' => ['name'],
        ], JsonSchemaInferer::schemaFor($this->parameterType('annotatedDto')));

        self::assertSame([
            'title' => 'Custom contact',
            'type' => 'object',
            'properties' => [
                'custom' => ['type' => 'string'],
            ],
            'required' => ['custom'],
        ], JsonSchemaInferer::schemaFor($this->parameterType('customDto')));
    }

    public function testMappingUnsafeDtoShapesDegradeToUnknownSchema(): void
    {
        self::assertTrue(JsonSchemaInferer::schemaFor($this->parameterType('unsupportedArray')));
    }

    public function testDtoLevelSchemaHooksDoNotBypassMappingSafeEndpointInferenceGate(): void
    {
        self::assertTrue(JsonSchemaInferer::schemaFor($this->parameterType('annotatedArray')));
        self::assertTrue(JsonSchemaInferer::schemaFor($this->parameterType('customUnsupportedArray')));
    }

    public function testInfersJsonNameAnnotatedDtoSchema(): void
    {
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'user_name' => ['type' => 'string'],
            ],
            'required' => ['user_name'],
        ], JsonSchemaInferer::schemaFor($this->parameterType('aliasedDto')));
    }

    public function testUnsupportedDtoShapesDegradeToUnknownSchema(): void
    {
        self::assertTrue(JsonSchemaInferer::schemaFor($this->parameterType('recursiveNode')));
    }

    private function parameterType(string $method): \ReflectionType
    {
        $reflection = new ReflectionMethod(DtoSchemaFixture::class, $method);

        return $reflection->getParameters()[0]->getType() ?? throw new \RuntimeException('Missing parameter type.');
    }
}
