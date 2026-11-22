<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\JsonSchema;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\JsonSchema;
use Restate\JsonSchema\ObjectSchema;
use stdClass;

final class JsonSchemaObjectSchemaTest extends TestCase
{
    public function testDraft202012FactoryCreatesObjectSchemas(): void
    {
        $schema = JsonSchema::object();

        self::assertSame(['type' => 'object'], $schema->toArray());
        self::assertSame('{"type":"object"}', $schema->toJson());
    }

    public function testObjectSchemaSerializesNamedPropertiesAndCollectsRequiredMarkers(): void
    {
        $schema = JsonSchema::object(
            JsonSchema::string()->property('name')->required(),
            JsonSchema::integer()->title('Age')->property('age'),
        )->title('Person')->additionalProperties(false);

        self::assertSame([
            'title' => 'Person',
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['title' => 'Age', 'type' => 'integer'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ], $schema->toArray());
        self::assertSame('{"title":"Person","type":"object","properties":{"name":{"type":"string"},"age":{"title":"Age","type":"integer"}},"required":["name"],"additionalProperties":false}', $schema->toJson());
    }

    public function testRequiredMarkerDoesNotSerializeOutsideObjectContext(): void
    {
        $schema = JsonSchema::string()->property('name')->required();

        self::assertSame(['type' => 'string'], $schema->toArray());
        self::assertSame('{"type":"string"}', $schema->toJson());
    }

    public function testPropertyNamesAndTitlesAreIndependent(): void
    {
        $schema = JsonSchema::object(
            JsonSchema::string()->title('Display name')->property('displayName'),
        );

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'displayName' => ['title' => 'Display name', 'type' => 'string'],
            ],
        ], $schema->toArray());
    }

    public function testObjectPropertiesPreserveNumericStringNamesAsJsonObjectNames(): void
    {
        $schema = JsonSchema::object(JsonSchema::string()->property('0'));
        $array = $schema->toArray();
        $properties = $array['properties'];

        self::assertInstanceOf(stdClass::class, $properties);
        self::assertSame(['type' => 'string'], $properties->{'0'});
        self::assertSame('{"type":"object","properties":{"0":{"type":"string"}}}', $schema->toJson());
    }

    public function testObjectSchemaCanSetRequiredNamesWithoutPropertyDefinitions(): void
    {
        $schema = JsonSchema::object(JsonSchema::string()->property('known')->required())
            ->required('known', 'pattern_only')
            ->requireProperty('additional_only');

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'known' => ['type' => 'string'],
            ],
            'required' => ['known', 'pattern_only', 'additional_only'],
        ], $schema->toArray());
    }

    public function testObjectSchemaSerializesGeneralJsonValueAnnotations(): void
    {
        $schema = JsonSchema::object()
            ->const(['kind' => 'fixed'])
            ->enum([['kind' => 'a'], ['kind' => 'b'], null])
            ->default(['kind' => 'a'])
            ->examples([['kind' => 'a']]);

        self::assertSame('{"type":"object","const":{"kind":"fixed"},"enum":[{"kind":"a"},{"kind":"b"},null],"default":{"kind":"a"},"examples":[{"kind":"a"}]}', $schema->toJson());
    }

    public function testOptionalMarkerRemovesRequiredMarkerOnCopiedProperty(): void
    {
        $required = JsonSchema::string()->property('name')->required();
        $optional = $required->optional();

        self::assertSame([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ], JsonSchema::object($required)->toArray());
        self::assertSame([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ], JsonSchema::object($optional)->toArray());
    }

    public function testAdditionalPropertiesCanBeEnabledDisabledOrConstrained(): void
    {
        self::assertSame([
            'type' => 'object',
            'additionalProperties' => true,
        ], JsonSchema::object()->additionalProperties(true)->toArray());
        self::assertSame([
            'type' => 'object',
            'additionalProperties' => false,
        ], JsonSchema::object()->denyAdditionalProperties()->toArray());
        self::assertSame([
            'type' => 'object',
            'additionalProperties' => ['type' => 'string'],
        ], JsonSchema::object()->additionalProperties(JsonSchema::string())->toArray());
    }

    public function testObjectSchemaSerializesObjectConstraintKeywordsInStableOrder(): void
    {
        $schema = JsonSchema::object(JsonSchema::string()->property('name')->required())
            ->title('Record')
            ->minProperties(1)
            ->maxProperties(5)
            ->patternProperties([
                '^s_' => JsonSchema::string(),
                '^i_' => JsonSchema::integer(),
            ])
            ->dependentRequired(['credit_card' => ['billing_address']])
            ->additionalProperties(false)
            ->propertyNames(JsonSchema::string()->pattern('^[a-z_]+$'))
            ->dependentSchemas(['billing_address' => JsonSchema::object()->property('country', JsonSchema::string())])
            ->unevaluatedProperties(JsonSchema::neverValid());

        self::assertSame([
            'title' => 'Record',
            'type' => 'object',
            'minProperties' => 1,
            'maxProperties' => 5,
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'patternProperties' => [
                '^s_' => ['type' => 'string'],
                '^i_' => ['type' => 'integer'],
            ],
            'required' => ['name'],
            'dependentRequired' => [
                'credit_card' => ['billing_address'],
            ],
            'additionalProperties' => false,
            'propertyNames' => ['type' => 'string', 'pattern' => '^[a-z_]+$'],
            'dependentSchemas' => [
                'billing_address' => [
                    'type' => 'object',
                    'properties' => [
                        'country' => ['type' => 'string'],
                    ],
                ],
            ],
            'unevaluatedProperties' => false,
        ], $schema->toArray());
        self::assertSame('{"title":"Record","type":"object","minProperties":1,"maxProperties":5,"properties":{"name":{"type":"string"}},"patternProperties":{"^s_":{"type":"string"},"^i_":{"type":"integer"}},"required":["name"],"dependentRequired":{"credit_card":["billing_address"]},"additionalProperties":false,"propertyNames":{"type":"string","pattern":"^[a-z_]+$"},"dependentSchemas":{"billing_address":{"type":"object","properties":{"country":{"type":"string"}}}},"unevaluatedProperties":false}', $schema->toJson());
    }

    public function testObjectSchemaSerializesCompositionAndConditionalKeywordsInStableOrder(): void
    {
        $schema = JsonSchema::object()
            ->allOf(JsonSchema::object()->property('id', JsonSchema::integer()), true)
            ->if(JsonSchema::object()->property('kind', JsonSchema::string()->const('email')))
            ->then(JsonSchema::object()->property('email', JsonSchema::string()->format('email')))
            ->else(false);

        self::assertSame([
            'type' => 'object',
            'allOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                    ],
                ],
                true,
            ],
            'if' => [
                'type' => 'object',
                'properties' => [
                    'kind' => ['type' => 'string', 'const' => 'email'],
                ],
            ],
            'then' => [
                'type' => 'object',
                'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email'],
                ],
            ],
            'else' => false,
        ], $schema->toArray());
        self::assertSame('{"type":"object","allOf":[{"type":"object","properties":{"id":{"type":"integer"}}},true],"if":{"type":"object","properties":{"kind":{"type":"string","const":"email"}}},"then":{"type":"object","properties":{"email":{"type":"string","format":"email"}}},"else":false}', $schema->toJson());
    }

    public function testObjectSchemaSerializesIntegerLikeMapNamesAsJsonObjects(): void
    {
        $schema = JsonSchema::object()
            ->patternProperties(['0' => JsonSchema::string()])
            ->dependentRequired(['1' => ['2']])
            ->dependentSchemas(['3' => true]);

        self::assertSame('{"type":"object","patternProperties":{"0":{"type":"string"}},"dependentRequired":{"1":["2"]},"dependentSchemas":{"3":true}}', $schema->toJson());
    }

    public function testObjectSchemaSerializesEmptyObjectConstraintMapsAsJsonObjects(): void
    {
        $schema = JsonSchema::object()
            ->patternProperties([])
            ->dependentRequired([])
            ->dependentSchemas([]);

        self::assertSame('{"type":"object","patternProperties":{},"dependentRequired":{},"dependentSchemas":{}}', $schema->toJson());
    }

    public function testObjectSchemaRejectsUnnamedProperties(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ObjectSchema::new([JsonSchema::string()]);
    }

    public function testObjectSchemaRejectsDuplicateProperties(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::object(
            JsonSchema::string()->property('name'),
            JsonSchema::integer()->property('name'),
        );
    }

    public function testObjectSchemaRejectsInvalidObjectConstraintCounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::object()->minProperties(-1);
    }

    public function testObjectSchemaRejectsInvalidDependentRequiredLists(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::object()->dependentRequired(['credit_card' => ['billing_address', 'billing_address']]);
    }

    public function testSchemaCompositionRejectsEmptyLists(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::object()->allOf();
    }

    public function testObjectSchemaRejectsDuplicateObjectConstraintNames(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::object()->patternProperty('^x_', JsonSchema::string())->patternProperty('^x_', JsonSchema::integer());
    }

    public function testObjectSchemaMethodsAreImmutable(): void
    {
        $original = JsonSchema::object();
        $changed = $original->property(JsonSchema::string()->property('name')->required())->additionalProperties(false);

        self::assertNotSame($original, $changed);
        self::assertSame(['type' => 'object'], $original->toArray());
        self::assertSame([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
            'additionalProperties' => false,
        ], $changed->toArray());
    }
}
