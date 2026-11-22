<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\HandlerDefinition;
use Restate\ObjectContext;
use Restate\Payload;
use Restate\Serde;
use Restate\Service;
use Restate\VirtualObject;
use Restate\Workflow;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

final class HandlerDefinitionInferenceSerde implements Serde
{
    public function serialize(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function deserialize(string $bytes): mixed
    {
        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    }

    public function contentType(): string
    {
        return 'application/x-handler-inference-serde';
    }
}

final class HandlerDefinitionInferenceTest extends TestCase
{
    public function testInfersJsonPayloadShapeFromHandlerSignatureWithoutStoringSchemaMetadata(): void
    {
        $handler = new HandlerDefinition(
            'length',
            static fn (Context $ctx, string $name): int => strlen($name),
        );

        self::assertSame(
            ['required' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $handler->inputPayload->inputManifest(),
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $handler->outputPayload->outputManifest(),
        );
    }

    public function testPublicBuildersInferJsonPayloadsWithoutStoringSchemaMetadata(): void
    {
        $service = Service::new('Greeter')
            ->handler('length', static fn (Context $ctx, string $name): int => strlen($name))
            ->definition();

        $object = VirtualObject::new('Counter')
            ->handler('increment', static fn (ObjectContext $ctx, int $amount): bool => $amount > 0)
            ->definition();

        $workflow = Workflow::new('Signup')
            ->run(static fn (WorkflowContext $ctx, string $name): string => $name)
            ->sharedHandler('status', static fn (WorkflowSharedContext $ctx, bool $verbose): array => ['verbose' => $verbose])
            ->definition();

        self::assertSame(
            ['required' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $service->handlers[0]->inputPayload->inputManifest(),
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $service->handlers[0]->outputPayload->outputManifest(),
        );
        self::assertSame(
            ['required' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $object->handlers[0]->inputPayload->inputManifest(),
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $object->handlers[0]->outputPayload->outputManifest(),
        );
        self::assertSame(
            ['required' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $workflow->handlers[0]->inputPayload->inputManifest(),
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $workflow->handlers[0]->outputPayload->outputManifest(),
        );
        self::assertSame(
            ['required' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $workflow->handlers[1]->inputPayload->inputManifest(),
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $workflow->handlers[1]->outputPayload->outputManifest(),
        );
    }

    public function testNoPayloadParameterInfersEmptyInputAndVoidReturnInfersEmptyOutput(): void
    {
        $handler = new HandlerDefinition(
            'ping',
            static function (Context $ctx): void {
            },
        );

        self::assertSame(['required' => false], $handler->inputPayload->inputManifest());
        self::assertSame(['setContentTypeIfEmpty' => false], $handler->outputPayload->outputManifest());
    }

    public function testMissingReturnTypeKeepsUnknownJsonOutput(): void
    {
        $handler = new HandlerDefinition(
            'unknown',
            static function (Context $ctx, string $name) {
                return $name;
            },
        );

        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $handler->outputPayload->outputManifest(),
        );
    }

    public function testExplicitPayloadSchemasOverrideInferredSchemas(): void
    {
        $handler = new HandlerDefinition(
            'schemaEcho',
            static fn (Context $ctx, string $name): string => $name,
            inputPayload: Payload::json(['type' => 'object'], required: true),
            outputPayload: Payload::json(['type' => 'object']),
        );

        self::assertSame(
            ['required' => true, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'object']],
            $handler->inputPayload->inputManifest(),
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'object']],
            $handler->outputPayload->outputManifest(),
        );
    }

    public function testHandlerDefinitionsDoNotStoreInferredSchemasOnPayloads(): void
    {
        $handler = new HandlerDefinition(
            'length',
            static fn (Context $ctx, string $name): int => strlen($name),
        );

        self::assertNull($handler->inputPayload->explicitJsonSchema());
        self::assertTrue($handler->inputPayload->jsonSchema());
        self::assertNull($handler->outputPayload->explicitJsonSchema());
        self::assertTrue($handler->outputPayload->jsonSchema());
    }

    public function testSchemaLessExplicitJsonPayloadRemainsSchemaLessUntilDiscoveryResolution(): void
    {
        $handler = new HandlerDefinition(
            'schemaEcho',
            static fn (Context $ctx, string $name): string => $name,
            inputPayload: Payload::json(required: true),
            outputPayload: Payload::json(),
        );

        self::assertSame(
            ['required' => true, 'contentType' => 'application/json', 'jsonSchema' => true],
            $handler->inputPayload->inputManifest(),
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $handler->outputPayload->outputManifest(),
        );
    }

    public function testCustomBinaryAndEmptyPayloadsRemainExplicitContracts(): void
    {
        $handler = new HandlerDefinition(
            'convert',
            static fn (Context $ctx, string $name): string => $name,
            inputPayload: Payload::custom(HandlerDefinitionInferenceSerde::class),
            outputPayload: Payload::binary('application/octet-stream'),
        );

        self::assertSame(
            ['required' => false, 'contentType' => 'application/x-handler-inference-serde'],
            $handler->inputPayload->inputManifest(),
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/octet-stream'],
            $handler->outputPayload->outputManifest(),
        );
    }

    public function testExplicitEmptyPayloadsRemainEmptyContracts(): void
    {
        $handler = new HandlerDefinition(
            'convert',
            static fn (Context $ctx, string $name): string => $name,
            inputPayload: Payload::empty(),
            outputPayload: Payload::empty(),
        );

        self::assertSame(['required' => false], $handler->inputPayload->inputManifest());
        self::assertSame(['setContentTypeIfEmpty' => false], $handler->outputPayload->outputManifest());
    }

    public function testHandlerMustDeclareContextFirst(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler broken must declare a Restate context as the first parameter.');

        new HandlerDefinition('broken', static fn (string $name): string => $name);
    }

    public function testHandlerMustDeclareAtMostOnePayloadParameter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler broken must declare at most one payload parameter after the context parameter.');

        new HandlerDefinition('broken', static fn (Context $ctx, string $first, string $second): string => $first . $second);
    }

    public function testHandlerDefinitionKeepsReflectedPayloadTypes(): void
    {
        $handler = new HandlerDefinition(
            'length',
            static fn (Context $ctx, string $name): int => strlen($name),
        );

        self::assertInstanceOf(\ReflectionNamedType::class, $handler->inputType);
        self::assertSame('string', $handler->inputType->getName());
        self::assertInstanceOf(\ReflectionNamedType::class, $handler->outputType);
        self::assertSame('int', $handler->outputType->getName());
    }

    public function testHandlerDefinitionUsesNullInputTypeWhenThereIsNoPayloadParameter(): void
    {
        $handler = new HandlerDefinition(
            'ping',
            static function (Context $ctx): void {
            },
        );

        self::assertNull($handler->inputType);
        self::assertInstanceOf(\ReflectionNamedType::class, $handler->outputType);
        self::assertSame('void', $handler->outputType->getName());
    }
}
