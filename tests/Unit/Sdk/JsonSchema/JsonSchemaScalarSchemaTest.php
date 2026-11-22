<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\JsonSchema;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\JsonSchema;
use Restate\JsonSchema\Type;

final class JsonSchemaScalarSchemaTest extends TestCase
{
    public function testCreatesScalarSchemas(): void
    {
        self::assertSame(['type' => 'integer'], JsonSchema::integer()->toArray());
        self::assertSame(['type' => 'number'], JsonSchema::number()->toArray());
        self::assertSame(['type' => 'boolean'], JsonSchema::boolean()->toArray());
        self::assertSame(['type' => 'null'], JsonSchema::null()->toArray());
        self::assertSame(['type' => ['string', 'null']], JsonSchema::union(Type::String, Type::Null)->toArray());
        self::assertTrue(JsonSchema::alwaysValid()->toArray());
        self::assertFalse(JsonSchema::neverValid()->toArray());
    }

    public function testIntegerSchemaSerializesSupportedKeywordsInStableOrder(): void
    {
        $schema = JsonSchema::integer()
            ->title('Count')
            ->description('A whole number.')
            ->multipleOf(2)
            ->minimum(0)
            ->exclusiveMinimum(-1)
            ->maximum(10)
            ->exclusiveMaximum(11)
            ->const(4)
            ->enum([2, 4, 6])
            ->default(2)
            ->examples([2, 4]);

        $expected = [
            'title' => 'Count',
            'description' => 'A whole number.',
            'type' => 'integer',
            'multipleOf' => 2,
            'minimum' => 0,
            'exclusiveMinimum' => -1,
            'maximum' => 10,
            'exclusiveMaximum' => 11,
            'const' => 4,
            'enum' => [2, 4, 6],
            'default' => 2,
            'examples' => [2, 4],
        ];

        self::assertSame($expected, $schema->toArray());
        self::assertSame('{"title":"Count","description":"A whole number.","type":"integer","multipleOf":2,"minimum":0,"exclusiveMinimum":-1,"maximum":10,"exclusiveMaximum":11,"const":4,"enum":[2,4,6],"default":2,"examples":[2,4]}', $schema->toJson());
    }

    public function testNumberSchemaSerializesSupportedKeywordsInStableOrder(): void
    {
        $schema = JsonSchema::number()
            ->title('Ratio')
            ->description('A finite number.')
            ->multipleOf(0.5)
            ->minimum(0)
            ->exclusiveMinimum(-0.5)
            ->maximum(10.5)
            ->exclusiveMaximum(11)
            ->const(1.5)
            ->enum([1, 1.5])
            ->default(1)
            ->examples([1, 1.5]);

        $expected = [
            'title' => 'Ratio',
            'description' => 'A finite number.',
            'type' => 'number',
            'multipleOf' => 0.5,
            'minimum' => 0,
            'exclusiveMinimum' => -0.5,
            'maximum' => 10.5,
            'exclusiveMaximum' => 11,
            'const' => 1.5,
            'enum' => [1, 1.5],
            'default' => 1,
            'examples' => [1, 1.5],
        ];

        self::assertSame($expected, $schema->toArray());
        self::assertSame('{"title":"Ratio","description":"A finite number.","type":"number","multipleOf":0.5,"minimum":0,"exclusiveMinimum":-0.5,"maximum":10.5,"exclusiveMaximum":11,"const":1.5,"enum":[1,1.5],"default":1,"examples":[1,1.5]}', $schema->toJson());
    }

    public function testBooleanValueSchemaIsDistinctFromBooleanSchema(): void
    {
        $schema = JsonSchema::boolean()
            ->title('Enabled')
            ->description('Feature flag.')
            ->const(true)
            ->enum([true, false])
            ->default(false)
            ->examples([true]);

        self::assertSame([
            'title' => 'Enabled',
            'description' => 'Feature flag.',
            'type' => 'boolean',
            'const' => true,
            'enum' => [true, false],
            'default' => false,
            'examples' => [true],
        ], $schema->toArray());
        self::assertSame('{"title":"Enabled","description":"Feature flag.","type":"boolean","const":true,"enum":[true,false],"default":false,"examples":[true]}', $schema->toJson());
        self::assertTrue(JsonSchema::alwaysValid()->toArray());
        self::assertSame('true', JsonSchema::alwaysValid()->toJson());
        self::assertFalse(JsonSchema::neverValid()->toArray());
        self::assertSame('false', JsonSchema::neverValid()->toJson());
    }

    public function testBooleanSchemaDocumentsRemainBooleanSchemas(): void
    {
        self::assertTrue(JsonSchema::document(JsonSchema::alwaysValid())->toArray());
        self::assertSame('true', JsonSchema::document(JsonSchema::alwaysValid())->toJson());
        self::assertFalse(JsonSchema::document(JsonSchema::neverValid())->toArray());
        self::assertSame('false', JsonSchema::document(JsonSchema::neverValid())->toJson());
    }

    public function testNullSchemaSerializesSupportedKeywordsInStableOrder(): void
    {
        $schema = JsonSchema::null()
            ->title('Nothing')
            ->description('Only null.')
            ->const()
            ->enum([null])
            ->default()
            ->examples([null]);

        self::assertSame([
            'title' => 'Nothing',
            'description' => 'Only null.',
            'type' => 'null',
            'const' => null,
            'enum' => [null],
            'default' => null,
            'examples' => [null],
        ], $schema->toArray());
        self::assertSame('{"title":"Nothing","description":"Only null.","type":"null","const":null,"enum":[null],"default":null,"examples":[null]}', $schema->toJson());
    }

    public function testUnionSchemaSerializesTypeArrays(): void
    {
        $schema = JsonSchema::union(Type::String, Type::Null)
            ->title('Optional name')
            ->description('String or null.');

        self::assertSame([
            'title' => 'Optional name',
            'description' => 'String or null.',
            'type' => ['string', 'null'],
        ], $schema->toArray());
        self::assertSame('{"title":"Optional name","description":"String or null.","type":["string","null"]}', $schema->toJson());
    }

    public function testSchemasCanBeMarkedNullable(): void
    {
        $string = JsonSchema::string();
        $nullableString = $string->nullable();

        self::assertNotSame($string, $nullableString);
        self::assertSame(['type' => 'string'], $string->toArray());
        self::assertSame(['type' => ['string', 'null']], $nullableString->toArray());
        self::assertSame(['type' => ['object', 'null']], JsonSchema::object()->nullable()->toArray());
        self::assertSame(['type' => ['array', 'null']], JsonSchema::array()->nullable()->toArray());
        self::assertSame(['type' => ['string', 'integer', 'null']], JsonSchema::union(Type::String, Type::Integer)->nullable()->toArray());
        self::assertSame(['type' => ['string', 'null']], JsonSchema::union(Type::String, Type::Null)->nullable()->toArray());
        self::assertSame(['type' => 'null'], JsonSchema::null()->nullable()->toArray());
    }

    public function testAddedFluentSchemaMethodsAreImmutable(): void
    {
        $integer = JsonSchema::integer();
        $number = JsonSchema::number();
        $boolean = JsonSchema::boolean();
        $null = JsonSchema::null();
        $union = JsonSchema::union(Type::String, Type::Null);

        self::assertNotSame($integer, $integer->minimum(1));
        self::assertSame(['type' => 'integer'], $integer->toArray());
        self::assertNotSame($number, $number->minimum(1.5));
        self::assertSame(['type' => 'number'], $number->toArray());
        self::assertNotSame($boolean, $boolean->default(true));
        self::assertSame(['type' => 'boolean'], $boolean->toArray());
        self::assertNotSame($null, $null->title('Nothing'));
        self::assertSame(['type' => 'null'], $null->toArray());
        self::assertNotSame($union, $union->title('Value'));
        self::assertSame(['type' => ['string', 'null']], $union->toArray());
    }

    public function testUnionSchemaRejectsDuplicateTypeEntries(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::union(Type::String, Type::String);
    }

    public function testUnionSchemaRejectsEmptyTypeArrays(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::union();
    }

    public function testNumberSchemaRejectsNonFiniteNumbers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::number()->minimum(NAN);
    }

    public function testNumberSchemaRejectsNonPositiveMultipleOf(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::number()->multipleOf(0);
    }

    public function testIntegerSchemaRejectsEmptyEnum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::integer()->enum([]);
    }

    public function testScalarSchemasAllowGeneralJsonValueAnnotations(): void
    {
        self::assertSame([
            'type' => 'boolean',
            'enum' => [true, 1, null],
        ], JsonSchema::boolean()->enum([true, 1, null])->toArray());

        self::assertSame([
            'type' => 'null',
            'examples' => [null, false],
        ], JsonSchema::null()->examples([null, false])->toArray());
    }
}
