<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\JsonSchema;

use PHPUnit\Framework\TestCase;
use Restate\JsonSchema;
use Restate\JsonSchema\Attributes\JsonName;
use Restate\JsonSchema\Attributes\RawSchema;
use Restate\JsonSchema\Schema;
use Restate\JsonSchema\Generation\ProvidesExamples;
use Restate\JsonSchema\Generation\ProvidesSchema;
use Restate\JsonSchema\Generation\SchemaGenerator;

final readonly class JsonSchemaDtoSchemaGenerationContact
{
    public function __construct(
        public string $name,
        public int $age,
    ) {
    }
}

#[RawSchema([
    'title' => 'Contact',
    'description' => 'A contact payload.',
    'type' => 'object',
    'properties' => [
        'name' => ['type' => 'string', 'minLength' => 2],
        'age' => ['type' => 'integer'],
    ],
    'required' => ['name', 'age'],
])]
final readonly class JsonSchemaDtoSchemaGenerationAnnotatedContact
{
    public function __construct(
        public string $name,
        public int $age,
    ) {
    }
}

final readonly class JsonSchemaDtoSchemaGenerationRawFieldContact
{
    public function __construct(
        #[RawSchema(['description' => 'Display name.', 'type' => 'string', 'minLength' => 2])]
        public string $name,
    ) {
    }
}

final readonly class JsonSchemaDtoSchemaGenerationAliasedContact
{
    public function __construct(
        #[JsonName('user_name')]
        public string $name,
    ) {
    }
}

final class JsonSchemaDtoSchemaGenerationAliasedProfile
{
    #[JsonName('display_name')]
    public string $name;
}

final readonly class JsonSchemaDtoSchemaGenerationCustomContact implements ProvidesSchema
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

final readonly class JsonSchemaDtoSchemaGenerationExampleContact implements ProvidesExamples
{
    public function __construct(public string $name)
    {
    }

    public static function jsonSchemaExamples(): iterable
    {
        yield ['name' => 'Ada Lovelace'];
    }
}

final readonly class JsonSchemaDtoSchemaGenerationRawCollectionContact
{
    /**
     * @param list<string> $tags
     * @param array<string, int> $scoresByName
     */
    public function __construct(
        #[RawSchema(['type' => 'array', 'items' => ['type' => 'string']])]
        public array $tags,
        #[RawSchema(['type' => 'object', 'additionalProperties' => ['type' => 'integer']])]
        public array $scoresByName,
    ) {
    }
}

final readonly class JsonSchemaDtoSchemaGenerationAddress
{
    public function __construct(public string $city)
    {
    }
}

final readonly class JsonSchemaDtoSchemaGenerationContactWithAddress
{
    public function __construct(
        public string $name,
        public JsonSchemaDtoSchemaGenerationAddress $address,
    ) {
    }
}

final readonly class JsonSchemaDtoSchemaGenerationArrayContact
{
    /** @param list<string> $tags */
    public function __construct(public array $tags)
    {
    }
}

enum JsonSchemaDtoSchemaGenerationStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}

final readonly class JsonSchemaDtoSchemaGenerationRichContact
{
    /** @param list<string> $tags */
    public function __construct(
        public ?string $nickname,
        public string|int $externalId,
        public bool $active,
        public float $score,
        public array $tags,
        public object $metadata,
        public mixed $anything,
        public JsonSchemaDtoSchemaGenerationStatus $status = JsonSchemaDtoSchemaGenerationStatus::Active,
    ) {
    }
}

final class JsonSchemaDtoSchemaGenerationPublicProfile
{
    #[RawSchema(['description' => 'Display name.', 'type' => 'string'])]
    public string $name;

    public bool $active = true;

    public float $score;
}

final class JsonSchemaDtoSchemaGenerationTest extends TestCase
{
    public function testGeneratesObjectSchemaFromConstructorPromotedDto(): void
    {
        $schema = JsonSchema::schemaFor(JsonSchemaDtoSchemaGenerationContact::class);

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
            'required' => ['name', 'age'],
        ], json_decode($schema->toJson(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testRawSchemaAttributeReplacesClassSchema(): void
    {
        $schema = JsonSchema::schemaFor(JsonSchemaDtoSchemaGenerationAnnotatedContact::class);

        self::assertSame([
            'title' => 'Contact',
            'description' => 'A contact payload.',
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 2],
                'age' => ['type' => 'integer'],
            ],
            'required' => ['name', 'age'],
        ], json_decode($schema->toJson(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testRawSchemaAttributeReplacesFieldSchema(): void
    {
        $schema = JsonSchema::schemaFor(JsonSchemaDtoSchemaGenerationRawFieldContact::class);

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'name' => ['description' => 'Display name.', 'type' => 'string', 'minLength' => 2],
            ],
            'required' => ['name'],
        ], json_decode($schema->toJson(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testJsonNameAttributeRenamesDtoProperties(): void
    {
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'user_name' => ['type' => 'string'],
            ],
            'required' => ['user_name'],
        ], JsonSchema::schemaFor(JsonSchemaDtoSchemaGenerationAliasedContact::class)->toArray());

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'display_name' => ['type' => 'string'],
            ],
            'required' => ['display_name'],
        ], JsonSchema::schemaFor(JsonSchemaDtoSchemaGenerationAliasedProfile::class)->toArray());
    }

    public function testDtoCanProvideAuthoritativeCustomSchema(): void
    {
        $schema = JsonSchema::schemaFor(JsonSchemaDtoSchemaGenerationCustomContact::class);

        self::assertSame([
            'title' => 'Custom contact',
            'type' => 'object',
            'properties' => [
                'custom' => ['type' => 'string'],
            ],
            'required' => ['custom'],
        ], json_decode($schema->toJson(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testDtoCanProvideJsonSchemaExamples(): void
    {
        $schema = JsonSchema::schemaFor(JsonSchemaDtoSchemaGenerationExampleContact::class);

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
            'examples' => [
                ['name' => 'Ada Lovelace'],
            ],
        ], json_decode($schema->toJson(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testRawSchemaAttributeCanDescribeCollectionsAndMaps(): void
    {
        $schema = JsonSchema::schemaFor(JsonSchemaDtoSchemaGenerationRawCollectionContact::class);

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'scoresByName' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
            ],
            'required' => ['tags', 'scoresByName'],
        ], json_decode($schema->toJson(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testNestedDtosAreReferencedThroughDefinitions(): void
    {
        $schema = JsonSchema::schemaFor(JsonSchemaDtoSchemaGenerationContactWithAddress::class);

        self::assertSame([
            '$defs' => [
                'JsonSchemaDtoSchemaGenerationAddress' => [
                    'type' => 'object',
                    'properties' => [
                        'city' => ['type' => 'string'],
                    ],
                    'required' => ['city'],
                ],
            ],
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'address' => ['$ref' => '#/$defs/JsonSchemaDtoSchemaGenerationAddress'],
            ],
            'required' => ['name', 'address'],
        ], $schema->toArray());
    }

    public function testNativeArrayTypesGenerateGenericArraySchemas(): void
    {
        $schema = JsonSchema::schemaFor(JsonSchemaDtoSchemaGenerationArrayContact::class);

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'tags' => ['type' => 'array'],
            ],
            'required' => ['tags'],
        ], $schema->toArray());
    }

    public function testGeneratesAdditionalScalarNullableUnionMixedAndEnumTypes(): void
    {
        $schema = JsonSchema::schemaFor(JsonSchemaDtoSchemaGenerationRichContact::class);

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'nickname' => ['type' => ['string', 'null']],
                'externalId' => ['type' => ['string', 'integer']],
                'active' => ['type' => 'boolean'],
                'score' => ['type' => 'number'],
                'tags' => ['type' => 'array'],
                'metadata' => ['type' => 'object'],
                'anything' => [],
                'status' => ['type' => 'string', 'enum' => ['active', 'disabled']],
            ],
            'required' => ['nickname', 'externalId', 'active', 'score', 'tags', 'metadata', 'anything'],
        ], json_decode($schema->toJson(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testGeneratesSchemaDirectlyFromBackedEnums(): void
    {
        $schema = JsonSchema::schemaFor(JsonSchemaDtoSchemaGenerationStatus::class);

        self::assertSame([
            'type' => 'string',
            'enum' => ['active', 'disabled'],
        ], $schema->toArray());
    }

    public function testGeneratesSchemaFromPublicPropertiesWhenConstructorHasNoPayloadParameters(): void
    {
        $schema = JsonSchema::schemaFor(JsonSchemaDtoSchemaGenerationPublicProfile::class);

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'name' => ['description' => 'Display name.', 'type' => 'string'],
                'active' => ['type' => 'boolean'],
                'score' => ['type' => 'number'],
            ],
            'required' => ['name', 'score'],
        ], json_decode($schema->toJson(), true, flags: JSON_THROW_ON_ERROR));
    }
}
