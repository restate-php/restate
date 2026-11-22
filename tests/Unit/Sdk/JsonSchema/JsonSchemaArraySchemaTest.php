<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\JsonSchema;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\JsonSchema;

final class JsonSchemaArraySchemaTest extends TestCase
{
    public function testCreatesArraySchemas(): void
    {
        self::assertSame(['type' => 'array'], JsonSchema::array()->toArray());
        self::assertSame('{"type":"array"}', JsonSchema::array()->toJson());
    }

    public function testArraySchemaSerializesSupportedKeywordsInStableOrder(): void
    {
        $schema = JsonSchema::array()
            ->title('Tags')
            ->description('A constrained list.')
            ->prefixItems(JsonSchema::string()->title('First'), JsonSchema::integer())
            ->items(JsonSchema::string())
            ->contains(JsonSchema::string()->const('featured'))
            ->minContains(1)
            ->maxContains(2)
            ->minItems(1)
            ->maxItems(5)
            ->uniqueItems()
            ->unevaluatedItems(false);

        self::assertSame([
            'title' => 'Tags',
            'description' => 'A constrained list.',
            'type' => 'array',
            'prefixItems' => [
                ['title' => 'First', 'type' => 'string'],
                ['type' => 'integer'],
            ],
            'items' => ['type' => 'string'],
            'contains' => ['type' => 'string', 'const' => 'featured'],
            'minContains' => 1,
            'maxContains' => 2,
            'minItems' => 1,
            'maxItems' => 5,
            'uniqueItems' => true,
            'unevaluatedItems' => false,
        ], $schema->toArray());
        self::assertSame('{"title":"Tags","description":"A constrained list.","type":"array","prefixItems":[{"title":"First","type":"string"},{"type":"integer"}],"items":{"type":"string"},"contains":{"type":"string","const":"featured"},"minContains":1,"maxContains":2,"minItems":1,"maxItems":5,"uniqueItems":true,"unevaluatedItems":false}', $schema->toJson());
    }

    public function testArraySchemaAcceptsBooleanSchemasInAllSubschemaPositions(): void
    {
        $schema = JsonSchema::array()
            ->items(true)
            ->prefixItems(false, JsonSchema::alwaysValid())
            ->contains(JsonSchema::neverValid())
            ->unevaluatedItems(true);

        self::assertSame([
            'type' => 'array',
            'prefixItems' => [false, true],
            'items' => true,
            'contains' => false,
            'unevaluatedItems' => true,
        ], $schema->toArray());
    }

    public function testArraySchemaSerializesGeneralJsonValueAnnotations(): void
    {
        $schema = JsonSchema::array()
            ->const(['fixed'])
            ->enum([['a'], ['b'], null])
            ->default(['a'])
            ->examples([['a']]);

        self::assertSame('{"type":"array","const":["fixed"],"enum":[["a"],["b"],null],"default":["a"],"examples":[["a"]]}', $schema->toJson());
    }

    public function testArraySchemaFactoryAcceptsPrefixItems(): void
    {
        self::assertSame([
            'type' => 'array',
            'prefixItems' => [['type' => 'string'], false],
        ], JsonSchema::array(JsonSchema::string(), false)->toArray());
    }

    public function testArraySchemaMethodsAreImmutable(): void
    {
        $original = JsonSchema::array();
        $changed = $original
            ->items(JsonSchema::string())
            ->prefixItems(JsonSchema::integer())
            ->contains(true)
            ->minContains(1)
            ->maxContains(2)
            ->minItems(1)
            ->maxItems(3)
            ->uniqueItems(false)
            ->unevaluatedItems(false);

        self::assertNotSame($original, $changed);
        self::assertSame(['type' => 'array'], $original->toArray());
        self::assertSame([
            'type' => 'array',
            'prefixItems' => [['type' => 'integer']],
            'items' => ['type' => 'string'],
            'contains' => true,
            'minContains' => 1,
            'maxContains' => 2,
            'minItems' => 1,
            'maxItems' => 3,
            'uniqueItems' => false,
            'unevaluatedItems' => false,
        ], $changed->toArray());
    }

    public function testArraySchemaCanBeSerializedAsDocument(): void
    {
        self::assertSame([
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'array',
            'items' => ['type' => 'string'],
        ], JsonSchema::document(JsonSchema::array()->items(JsonSchema::string()))->toArray());
    }

    public function testArraySchemaRejectsInvalidCounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::array()->minItems(-1);
    }

    public function testArraySchemaRejectsNonFiniteCounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::array()->maxContains(NAN);
    }

    public function testArraySchemaRejectsFractionalCounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::array()->maxItems(1.5);
    }

    public function testArraySchemaRejectsEmptyPrefixItems(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::array()->prefixItems();
    }
}
