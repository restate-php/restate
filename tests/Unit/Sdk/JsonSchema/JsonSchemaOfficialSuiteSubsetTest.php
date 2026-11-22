<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\JsonSchema;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\JsonSchema;
use Restate\JsonSchema\Document;
use Restate\JsonSchema\Schema;
use Restate\JsonSchema\Type;
use RuntimeException;
use stdClass;

final class JsonSchemaOfficialSuiteSubsetTest extends TestCase
{
    #[DataProvider('officialSchemaBuilders')]
    public function testSelectedOfficialSchemaFixturesCanBeBuiltExactly(string $file, string $description, Document|Schema $schema): void
    {
        $case = self::fixtureCase($file, $description);

        self::assertJsonStringEqualsJsonString(
            self::jsonValueToJson($case->schema),
            $schema->toJson(),
        );
    }

    /** @return iterable<string, array{string, string, Document|Schema}> */
    public static function officialSchemaBuilders(): iterable
    {
        yield "draft2020-12 boolean schema 'true'" => [
            'draft2020-12/boolean_schema.json',
            "boolean schema 'true'",
            JsonSchema::alwaysValid(),
        ];

        yield "draft2020-12 boolean schema 'false'" => [
            'draft2020-12/boolean_schema.json',
            "boolean schema 'false'",
            JsonSchema::neverValid(),
        ];

        yield 'draft2020-12 integer type' => [
            'draft2020-12/type.json',
            'integer type matches integers',
            JsonSchema::document(JsonSchema::integer()),
        ];

        yield 'draft2020-12 number type' => [
            'draft2020-12/type.json',
            'number type matches numbers',
            JsonSchema::document(JsonSchema::number()),
        ];

        yield 'draft2020-12 string type' => [
            'draft2020-12/type.json',
            'string type matches strings',
            JsonSchema::document(JsonSchema::string()),
        ];

        yield 'draft2020-12 object type' => [
            'draft2020-12/type.json',
            'object type matches objects',
            JsonSchema::document(JsonSchema::object()),
        ];

        yield 'draft2020-12 array type' => [
            'draft2020-12/type.json',
            'array type matches arrays',
            JsonSchema::document(JsonSchema::array()),
        ];

        yield 'draft2020-12 boolean type' => [
            'draft2020-12/type.json',
            'boolean type matches booleans',
            JsonSchema::document(JsonSchema::boolean()),
        ];

        yield 'draft2020-12 null type' => [
            'draft2020-12/type.json',
            'null type matches only the null object',
            JsonSchema::document(JsonSchema::null()),
        ];

        yield 'draft2020-12 integer or string union' => [
            'draft2020-12/type.json',
            'multiple types can be specified in an array',
            JsonSchema::document(JsonSchema::union(Type::Integer, Type::String)),
        ];

        yield 'draft2020-12 single-entry type array' => [
            'draft2020-12/type.json',
            'type as array with one item',
            JsonSchema::document(JsonSchema::union(Type::String)),
        ];

        yield 'draft2020-12 array or object union' => [
            'draft2020-12/type.json',
            'type: array or object',
            JsonSchema::document(JsonSchema::union(Type::Array, Type::Object)),
        ];

        yield 'draft2020-12 array object or null union' => [
            'draft2020-12/type.json',
            'type: array, object or null',
            JsonSchema::document(JsonSchema::union(Type::Array, Type::Object, Type::Null)),
        ];

        yield 'draft2020-12 nested items' => [
            'draft2020-12/items.json',
            'nested items',
            JsonSchema::document(
                JsonSchema::array()->items(
                    JsonSchema::array()->items(
                        JsonSchema::array()->items(
                            JsonSchema::array()->items(JsonSchema::number()),
                        ),
                    ),
                ),
            ),
        ];
    }

    private static function fixtureCase(string $file, string $description): stdClass
    {
        foreach (self::fixtureCases($file) as $case) {
            if ($case->description === $description) {
                return $case;
            }
        }

        throw new RuntimeException(sprintf('Missing JSON Schema Test Suite fixture "%s" in %s.', $description, $file));
    }

    /** @return list<stdClass> */
    private static function fixtureCases(string $file): array
    {
        $path = __DIR__ . '/../../../Fixtures/json-schema-test-suite/' . $file;
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException(sprintf('Unable to read JSON Schema Test Suite fixture %s.', $path));
        }

        $cases = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!is_array($cases)) {
            throw new RuntimeException(sprintf('JSON Schema Test Suite fixture %s must decode to a list.', $path));
        }

        $list = [];
        foreach ($cases as $case) {
            if (!$case instanceof stdClass) {
                throw new RuntimeException(sprintf('JSON Schema Test Suite fixture %s must list objects.', $path));
            }
            $list[] = $case;
        }

        return $list;
    }

    /** @throws JsonException */
    private static function jsonValueToJson(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
