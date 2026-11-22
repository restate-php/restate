<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\JsonSchema;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\JsonSchema;
use Restate\JsonSchema\Document;
use stdClass;

final class JsonSchemaReferenceSchemaTest extends TestCase
{
    public function testReferencesAndDefinitionsSerializeExactly(): void
    {
        $node = JsonSchema::object(
            JsonSchema::string()->property('value')->required(),
            JsonSchema::ref('#/$defs/node')->property('next'),
        )->denyAdditionalProperties();

        $schema = JsonSchema::object()
            ->ref('#/$defs/node')
            ->defs(['node' => $node, 'anything' => true]);

        self::assertSame([
            '$ref' => '#/$defs/node',
            '$defs' => [
                'node' => [
                    'type' => 'object',
                    'properties' => [
                        'value' => ['type' => 'string'],
                        'next' => ['$ref' => '#/$defs/node'],
                    ],
                    'required' => ['value'],
                    'additionalProperties' => false,
                ],
                'anything' => true,
            ],
            'type' => 'object',
        ], $schema->toArray());
        self::assertSame('{"$ref":"#/$defs/node","$defs":{"node":{"type":"object","properties":{"value":{"type":"string"},"next":{"$ref":"#/$defs/node"}},"required":["value"],"additionalProperties":false},"anything":true},"type":"object"}', $schema->toJson());
    }

    public function testDefinitionMapsPreserveNumericStringKeysAsJsonObjectNames(): void
    {
        $schema = JsonSchema::object()->defs(['0' => true]);

        self::assertSame('{"$defs":{"0":true},"type":"object"}', $schema->toJson());

        $array = $schema->toArray();
        $defs = $array['$defs'];
        self::assertInstanceOf(stdClass::class, $defs);
        self::assertTrue($defs->{'0'});
    }

    public function testDynamicReferenceBuildersSerializeExactly(): void
    {
        $schema = JsonSchema::string()
            ->dynamicAnchor('node')
            ->dynamicRef('#node');

        self::assertSame([
            '$dynamicAnchor' => 'node',
            '$dynamicRef' => '#node',
            'type' => 'string',
        ], $schema->toArray());
        self::assertSame('{"$dynamicAnchor":"node","$dynamicRef":"#node","type":"string"}', $schema->toJson());
    }

    public function testCoreIdentifierVocabularyAndCommentKeywordsSerializeExactly(): void
    {
        $schema = JsonSchema::object()
            ->id('https://example.com/schemas/contact')
            ->vocabulary([
                'https://json-schema.org/draft/2020-12/vocab/core' => true,
                'https://json-schema.org/draft/2020-12/vocab/format-assertion' => false,
            ])
            ->anchor('contact')
            ->comment('Internal schema authoring note.')
            ->deprecated()
            ->readOnly()
            ->writeOnly(false);

        $array = $schema->toArray();

        self::assertSame('https://example.com/schemas/contact', $array['$id']);
        self::assertInstanceOf(stdClass::class, $array['$vocabulary']);
        self::assertTrue($array['$vocabulary']->{'https://json-schema.org/draft/2020-12/vocab/core'});
        self::assertFalse($array['$vocabulary']->{'https://json-schema.org/draft/2020-12/vocab/format-assertion'});
        self::assertSame('contact', $array['$anchor']);
        self::assertSame('Internal schema authoring note.', $array['$comment']);
        self::assertTrue($array['deprecated']);
        self::assertTrue($array['readOnly']);
        self::assertFalse($array['writeOnly']);
        self::assertSame('{"$id":"https://example.com/schemas/contact","$vocabulary":{"https://json-schema.org/draft/2020-12/vocab/core":true,"https://json-schema.org/draft/2020-12/vocab/format-assertion":false},"$anchor":"contact","$comment":"Internal schema authoring note.","deprecated":true,"readOnly":true,"writeOnly":false,"type":"object"}', $schema->toJson());
    }

    public function testReferenceSchemasCanCarrySiblingApplicators(): void
    {
        self::assertSame([
            '$ref' => '#/$defs/base',
            'allOf' => [
                ['type' => 'object'],
            ],
            'if' => true,
            'then' => ['type' => 'string'],
        ], JsonSchema::ref('#/$defs/base')
            ->allOf(JsonSchema::object())
            ->if(true)
            ->then(JsonSchema::string())
            ->toArray());
    }

    public function testRawSchemaPreservesJsonEncodableObjectContentAndDraft(): void
    {
        $defs = new stdClass();
        $defs->{'0'} = true;

        $schema = JsonSchema::raw([
            'type' => 'object',
            '$defs' => $defs,
            'x-list' => [1, null, 'value'],
        ]);

        self::assertSame('{"type":"object","$defs":{"0":true},"x-list":[1,null,"value"]}', $schema->toJson());
    }

    public function testDocumentWrapperEmitsDialectForRawObjectRoots(): void
    {
        self::assertSame([
            '$schema' => Document::DIALECT,
            'type' => 'object',
        ], JsonSchema::document(JsonSchema::raw(['type' => 'object']))->toArray());
        self::assertSame('{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object"}', JsonSchema::document(JsonSchema::raw(['type' => 'object']))->toJson());
    }

    public function testRawSchemaAllowsMatchingEmbeddedDialectAndDocumentWrapperDeduplicatesIt(): void
    {
        $schema = JsonSchema::raw([
            '$schema' => Document::DIALECT,
            'type' => 'object',
        ]);

        self::assertSame('{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object"}', $schema->toJson());
        self::assertSame('{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object"}', JsonSchema::document($schema)->toJson());
    }

    public function testRawSchemaRejectsConflictingEmbeddedDialect(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::raw([
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
        ]);
    }

    public function testRawSchemaRejectsInvalidRootValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::raw(true);
    }

    public function testRawSchemaRejectsJsonArrayRoots(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::raw([['type' => 'string']]);
    }

    public function testRawEmptyObjectSerializesAsJsonObject(): void
    {
        self::assertSame('{}', JsonSchema::raw([])->toJson());
    }

    public function testReferenceMethodsAreImmutable(): void
    {
        $original = JsonSchema::object();
        $changed = $original->ref('#/$defs/value')->def('value', JsonSchema::string());

        self::assertNotSame($original, $changed);
        self::assertSame(['type' => 'object'], $original->toArray());
        self::assertSame([
            '$ref' => '#/$defs/value',
            '$defs' => ['value' => ['type' => 'string']],
            'type' => 'object',
        ], $changed->toArray());
    }

    public function testRawSchemaArrayAccessReturnsCopies(): void
    {
        $schema = JsonSchema::raw(['nested' => ['value' => 'original']]);

        $first = $schema->toArray();
        $first->nested->value = 'changed';

        self::assertSame('{"nested":{"value":"original"}}', $schema->toJson());
    }

    /** @return iterable<string, array{0: \Closure(): \Restate\JsonSchema\Schema}> */
    public static function referenceSchemasRetargeted(): iterable
    {
        yield 'ref' => [static fn () => \Restate\JsonSchema\ReferenceSchema::new('#/a')->description('kept')->ref('#/b')];
        yield 'dynamicRef' => [static fn () => \Restate\JsonSchema\ReferenceSchema::new('#/b')->description('kept')->dynamicRef('#node')];
    }

    /** @param \Closure(): \Restate\JsonSchema\Schema $schema */
    #[\PHPUnit\Framework\Attributes\DataProvider('referenceSchemasRetargeted')]
    public function testRetargetingAReferenceSchemaKeepsItsOtherKeywords(\Closure $schema): void
    {
        $keywords = $schema()->toArray();

        self::assertSame('kept', $keywords['description'] ?? null);
        self::assertSame('#/b', $keywords['$ref'] ?? null);
    }
}
