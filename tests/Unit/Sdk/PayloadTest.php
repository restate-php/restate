<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\JsonSchema;
use Restate\JsonSchemaMetadata;
use Restate\Payload;
use Restate\Serde;

final readonly class PayloadTestCustomSerde implements Serde
{
    #[\Override]
    public function serialize(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    #[\Override]
    public function deserialize(string $bytes): mixed
    {
        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    }

    #[\Override]
    public function contentType(): string
    {
        return 'application/x-payload-test';
    }
}

final readonly class PayloadTestCustomJsonSerde implements Serde
{
    #[\Override]
    public function serialize(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    #[\Override]
    public function deserialize(string $bytes): mixed
    {
        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    }

    #[\Override]
    public function contentType(): string
    {
        return 'application/json';
    }
}

final readonly class PayloadTestEmptyContentTypeSerde implements Serde
{
    #[\Override]
    public function serialize(mixed $value): string
    {
        return '';
    }

    #[\Override]
    public function deserialize(string $bytes): mixed
    {
        return $bytes;
    }

    #[\Override]
    public function contentType(): string
    {
        return '';
    }
}

final readonly class PayloadTestRequiresConstructorSerde implements Serde
{
    public function __construct(private readonly string $prefix)
    {
    }

    #[\Override]
    public function serialize(mixed $value): string
    {
        return $this->prefix . json_encode($value, JSON_THROW_ON_ERROR);
    }

    #[\Override]
    public function deserialize(string $bytes): mixed
    {
        return $bytes;
    }

    #[\Override]
    public function contentType(): string
    {
        return 'application/x-payload-test';
    }
}

final class PayloadTestNotSerde
{
}

final class PayloadTest extends TestCase
{
    public function testDefaultJsonPayloadManifest(): void
    {
        $payload = Payload::json();

        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $payload->inputManifest());
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $payload->outputManifest());
    }

    public function testJsonPayloadWithSchemaAndRequiredInput(): void
    {
        $schema = ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]];
        $payload = Payload::json($schema, required: true);

        self::assertSame(['required' => true, 'contentType' => 'application/json', 'jsonSchema' => $schema], $payload->inputManifest());
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => $schema], $payload->outputManifest());
    }

    public function testInputPayloadCanAdvertiseAcceptedContentTypePattern(): void
    {
        $payload = Payload::json()->withAcceptContentType('application/*+json, application/json');

        self::assertSame(
            ['required' => false, 'contentType' => 'application/*+json, application/json', 'jsonSchema' => true],
            $payload->inputManifest(),
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $payload->outputManifest(),
        );
    }

    public function testOutputPayloadCanSetContentTypeIfEmpty(): void
    {
        self::assertSame(
            ['setContentTypeIfEmpty' => true, 'contentType' => 'application/json', 'jsonSchema' => true],
            Payload::json()->withSetContentTypeIfEmpty()->outputManifest(),
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => true],
            Payload::empty()->withSetContentTypeIfEmpty()->outputManifest(),
        );
    }

    public function testPayloadRejectsEmptyAcceptedContentTypePattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Payload accept content type must not be empty.');

        Payload::json()->withAcceptContentType('');
    }

    public function testBinaryPayloadOmitsJsonSchema(): void
    {
        $payload = Payload::binary('application/octet-stream');

        self::assertSame(['required' => false, 'contentType' => 'application/octet-stream'], $payload->inputManifest());
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/octet-stream'], $payload->outputManifest());
    }

    public function testEmptyPayloadOmitsContentTypeAndSchema(): void
    {
        $payload = Payload::empty();

        self::assertSame(['required' => false], $payload->inputManifest());
        self::assertSame(['setContentTypeIfEmpty' => false], $payload->outputManifest());
    }

    public function testPayloadKindIntrospection(): void
    {
        $json = Payload::json();
        $binary = Payload::binary('application/octet-stream');
        $empty = Payload::empty();

        self::assertTrue($json->isJson());
        self::assertFalse($json->isBinary());
        self::assertFalse($json->isEmpty());

        self::assertFalse($binary->isJson());
        self::assertTrue($binary->isBinary());
        self::assertFalse($binary->isEmpty());

        self::assertFalse($empty->isJson());
        self::assertFalse($empty->isBinary());
        self::assertTrue($empty->isEmpty());
    }

    public function testBinaryPayloadRejectsEmptyContentType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Payload content type must not be empty.');

        Payload::binary('');
    }

    public function testCustomPayloadWithSerdeInstanceUsesSerdeMetadata(): void
    {
        $serde = new PayloadTestCustomSerde();
        $payload = Payload::custom($serde, required: true);

        self::assertSame($serde, $payload->serde());
        self::assertFalse($payload->isJson());
        self::assertFalse($payload->isBinary());
        self::assertFalse($payload->isEmpty());
        self::assertSame(['required' => true, 'contentType' => 'application/x-payload-test'], $payload->inputManifest());
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/x-payload-test'], $payload->outputManifest());
    }

    public function testCustomPayloadWithSerdeClassStringInstantiatesSerde(): void
    {
        $payload = Payload::custom(PayloadTestCustomSerde::class);

        self::assertInstanceOf(PayloadTestCustomSerde::class, $payload->serde());
        self::assertSame(['required' => false, 'contentType' => 'application/x-payload-test'], $payload->inputManifest());
    }

    public function testCustomJsonSerdeIncludesProvidedSchemaOnly(): void
    {
        $schema = ['type' => 'object', 'required' => ['name']];

        self::assertSame(
            ['required' => false, 'contentType' => 'application/json'],
            Payload::custom(PayloadTestCustomJsonSerde::class)->inputManifest(),
        );
        self::assertSame(
            ['required' => true, 'contentType' => 'application/json', 'jsonSchema' => $schema],
            Payload::custom(PayloadTestCustomJsonSerde::class, $schema, required: true)->inputManifest(),
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => $schema],
            Payload::custom(PayloadTestCustomJsonSerde::class, $schema)->outputManifest(),
        );
    }

    public function testCustomPayloadRejectsMissingSerdeClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Serde class Restate\\Tests\\Unit\\Sdk\\MissingSerde does not exist.');

        // @phpstan-ignore argument.type (a class that does not exist is the point)
        Payload::custom(__NAMESPACE__ . '\\MissingSerde');
    }

    public function testCustomPayloadRejectsNonSerdeClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Serde class Restate\\Tests\\Unit\\Sdk\\PayloadTestNotSerde must implement Restate\\Serde.');

        // @phpstan-ignore argument.type (a class that is not a Serde is the point)
        Payload::custom(PayloadTestNotSerde::class);
    }

    public function testCustomPayloadRejectsSerdeClassWithRequiredConstructor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Serde class Restate\\Tests\\Unit\\Sdk\\PayloadTestRequiresConstructorSerde must be instantiable without constructor arguments.');

        Payload::custom(PayloadTestRequiresConstructorSerde::class);
    }

    public function testCustomPayloadRejectsEmptySerdeContentType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Serde content type must not be empty.');

        Payload::custom(PayloadTestEmptyContentTypeSerde::class);
    }

    public function testPayloadExposesExplicitJsonSchemas(): void
    {
        $explicit = Payload::json(['type' => 'object']);
        $schemaObject = Payload::json(JsonSchema::integer());
        $booleanSchema = Payload::json(JsonSchema::neverValid());
        $schemaLess = Payload::json();

        self::assertSame(['type' => 'object'], $explicit->explicitJsonSchema());
        self::assertSame(['type' => 'object'], $explicit->jsonSchema());
        self::assertSame(['type' => 'integer'], $schemaObject->explicitJsonSchema());
        self::assertFalse($booleanSchema->explicitJsonSchema());

        self::assertNull($schemaLess->explicitJsonSchema());
        self::assertTrue($schemaLess->jsonSchema());
    }

    public function testInputAndOutputManifestsCanUseResolvedJsonSchema(): void
    {
        $payload = Payload::json();

        self::assertSame(
            ['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'integer']],
            $payload->inputManifest(JsonSchemaMetadata::fromSchema(JsonSchema::integer())),
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'integer']],
            $payload->outputManifest(JsonSchemaMetadata::fromSchema(JsonSchema::integer())),
        );
    }
}
