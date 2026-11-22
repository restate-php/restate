<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\JsonSchema;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\JsonSchema;
use Restate\JsonSchema\StringFormat;
use Restate\JsonSchema\Document;

final class JsonSchemaStringSchemaTest extends TestCase
{
    public function testRootFactoryDefaultsToDraft202012StringSchema(): void
    {
        $schema = JsonSchema::string();

        self::assertSame(['type' => 'string'], $schema->toArray());
        self::assertSame('{"type":"string"}', $schema->toJson());
    }

    public function testDraft202012StringSchemaSerializesSupportedKeywordsInStableOrder(): void
    {
        $schema = JsonSchema::string()
            ->title('Email')
            ->description('Primary email address.')
            ->minLength(3)
            ->maxLength(255)
            ->pattern('^[^@]+@[^@]+$')
            ->format(StringFormat::Email)
            ->const('user@example.com')
            ->enum(['user@example.com', 'admin@example.com'])
            ->default('user@example.com')
            ->examples(['user@example.com', 'admin@example.com']);

        $expected = [
            'title' => 'Email',
            'description' => 'Primary email address.',
            'type' => 'string',
            'minLength' => 3,
            'maxLength' => 255,
            'pattern' => '^[^@]+@[^@]+$',
            'format' => 'email',
            'const' => 'user@example.com',
            'enum' => ['user@example.com', 'admin@example.com'],
            'default' => 'user@example.com',
            'examples' => ['user@example.com', 'admin@example.com'],
        ];

        self::assertSame($expected, $schema->toArray());
        self::assertSame('{"title":"Email","description":"Primary email address.","type":"string","minLength":3,"maxLength":255,"pattern":"^[^@]+@[^@]+$","format":"email","const":"user@example.com","enum":["user@example.com","admin@example.com"],"default":"user@example.com","examples":["user@example.com","admin@example.com"]}', $schema->toJson());
    }

    public function testDraft202012StringSchemaAcceptsDraftSpecificFormatEnumAndCustomFormats(): void
    {
        self::assertSame([
            'type' => 'string',
            'format' => 'uuid',
        ], JsonSchema::string()->format(StringFormat::Uuid)->toArray());

        self::assertSame([
            'type' => 'string',
            'format' => 'application-specific-id',
        ], JsonSchema::string()->format('application-specific-id')->toArray());
    }

    public function testStringSchemaSerializesContentVocabularyKeywords(): void
    {
        $schema = JsonSchema::string()
            ->contentEncoding('base64')
            ->contentMediaType('application/json')
            ->contentSchema(JsonSchema::object(JsonSchema::string()->property('name')->required()));

        self::assertSame([
            'type' => 'string',
            'contentEncoding' => 'base64',
            'contentMediaType' => 'application/json',
            'contentSchema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
            ],
        ], $schema->toArray());
        self::assertSame('{"type":"string","contentEncoding":"base64","contentMediaType":"application/json","contentSchema":{"type":"object","properties":{"name":{"type":"string"}},"required":["name"]}}', $schema->toJson());
    }

    public function testStringSchemaSerializesCompositionAndConditionalKeywordsInStableOrder(): void
    {
        $schema = JsonSchema::string()
            ->allOf(JsonSchema::string()->minLength(3), JsonSchema::alwaysValid())
            ->anyOf(JsonSchema::string()->format('email'), false)
            ->oneOf(JsonSchema::string()->const('admin'), JsonSchema::string()->const('user'))
            ->not(JsonSchema::string()->pattern('^tmp_'))
            ->if(JsonSchema::string()->pattern('@'))
            ->then(JsonSchema::string()->format('email'))
            ->else(JsonSchema::neverValid());

        $expected = [
            'type' => 'string',
            'allOf' => [
                ['type' => 'string', 'minLength' => 3],
                true,
            ],
            'anyOf' => [
                ['type' => 'string', 'format' => 'email'],
                false,
            ],
            'oneOf' => [
                ['type' => 'string', 'const' => 'admin'],
                ['type' => 'string', 'const' => 'user'],
            ],
            'not' => ['type' => 'string', 'pattern' => '^tmp_'],
            'if' => ['type' => 'string', 'pattern' => '@'],
            'then' => ['type' => 'string', 'format' => 'email'],
            'else' => false,
        ];

        self::assertSame($expected, $schema->toArray());
        self::assertSame('{"type":"string","allOf":[{"type":"string","minLength":3},true],"anyOf":[{"type":"string","format":"email"},false],"oneOf":[{"type":"string","const":"admin"},{"type":"string","const":"user"}],"not":{"type":"string","pattern":"^tmp_"},"if":{"type":"string","pattern":"@"},"then":{"type":"string","format":"email"},"else":false}', $schema->toJson());
    }

    public function testDocumentWrapperEmitsDraft202012DialectAtRoot(): void
    {
        $schema = JsonSchema::string()->title('Name');
        $document = JsonSchema::document($schema);

        self::assertSame([
            '$schema' => Document::DIALECT,
            'title' => 'Name',
            'type' => 'string',
        ], $document->toArray());
        self::assertSame('{"$schema":"https://json-schema.org/draft/2020-12/schema","title":"Name","type":"string"}', $document->toJson());
    }

    public function testFluentStringSchemaMethodsAreImmutable(): void
    {
        $original = JsonSchema::string();
        $changed = $original->title('Name')->minLength(1);

        self::assertNotSame($original, $changed);
        self::assertSame(['type' => 'string'], $original->toArray());
        self::assertSame([
            'title' => 'Name',
            'type' => 'string',
            'minLength' => 1,
        ], $changed->toArray());
    }
}
