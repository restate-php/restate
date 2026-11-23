<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Attributes\Handler;
use Restate\Attributes\Input;
use Restate\Attributes\Output as OutputAttribute;
use Restate\Attributes\Service;
use Restate\Attributes\Shared;
use Restate\Attributes\VirtualObject;
use Restate\Attributes\Workflow as WorkflowAttribute;
use Restate\Context;
use Restate\HandlerDefinition;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Reflection\ServiceReflector;
use Restate\Serde;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;
use Restate\HandlerType;

#[Service('ReflectedGreeter')]
final class ReflectorPayloadServiceFixture
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    #[Handler('schemaEcho')]
    #[Input(
        schema: ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
        required: true,
    )]
    #[OutputAttribute(schema: ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]])]
    public function schemaEcho(Context $ctx, array $payload): array
    {
        return $payload;
    }
}

#[VirtualObject('ReflectedCounter')]
final class ReflectorPayloadObjectFixture
{
    #[Handler('add')]
    #[Input(schema: ['type' => 'integer'])]
    public function add(ObjectContext $ctx, int $amount): int
    {
        return $amount;
    }

    #[Handler('get')]
    #[Shared]
    #[OutputAttribute(schema: ['type' => 'integer'])]
    public function get(ObjectSharedContext $ctx): int
    {
        return 1;
    }
}

#[Service('ReflectedInferredGreeter')]
final class ReflectorPayloadInferredServiceFixture
{
    #[Handler('greet')]
    public function greet(Context $ctx, string $name): string
    {
        return "Hello {$name}";
    }

    #[Handler('ping')]
    public function ping(Context $ctx): void
    {
    }

    #[Handler('schemaLessExplicit')]
    #[Input(required: true)]
    #[OutputAttribute]
    public function schemaLessExplicit(Context $ctx, ?string $name): ?string
    {
        return $name;
    }
}

final class ReflectorPayloadSerde implements Serde
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
        return 'application/x-reflector-serde';
    }
}

final class ReflectorPayloadSerdeWithRequiredConstructor implements Serde
{
    public function __construct(private readonly string $prefix)
    {
    }

    public function serialize(mixed $value): string
    {
        return $this->prefix . json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function deserialize(string $bytes): mixed
    {
        return $bytes;
    }

    public function contentType(): string
    {
        return 'application/x-reflector-serde';
    }
}

#[Service('ReflectedSerdeService')]
final class ReflectorPayloadSerdeServiceFixture
{
    #[Handler('custom')]
    #[Input(serde: ReflectorPayloadSerde::class, required: true)]
    #[OutputAttribute(serde: ReflectorPayloadSerde::class)]
    public function custom(Context $ctx, mixed $payload): mixed
    {
        return $payload;
    }
}

#[VirtualObject('ReflectedSerdeObject')]
final class ReflectorPayloadSerdeObjectFixture
{
    #[Handler('set')]
    #[Input(serde: ReflectorPayloadSerde::class, required: true)]
    public function set(ObjectContext $ctx, mixed $payload): mixed
    {
        return $payload;
    }

    #[Handler('get')]
    #[Shared]
    #[OutputAttribute(serde: ReflectorPayloadSerde::class)]
    public function get(ObjectSharedContext $ctx): string
    {
        return 'ok';
    }
}

#[WorkflowAttribute('ReflectedSerdeWorkflow')]
final class ReflectorPayloadSerdeWorkflowFixture
{
    #[Handler]
    #[Input(serde: ReflectorPayloadSerde::class, required: true)]
    public function run(WorkflowContext $ctx, mixed $payload): mixed
    {
        return $payload;
    }

    #[Handler('status')]
    #[Shared]
    #[OutputAttribute(serde: ReflectorPayloadSerde::class)]
    public function status(WorkflowSharedContext $ctx): string
    {
        return 'ok';
    }
}

final class ServiceReflectorPayloadTest extends TestCase
{
    public function testReflectsServicePayloadAttributes(): void
    {
        $schema = ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]];

        $definition = ServiceReflector::fromObject(new ReflectorPayloadServiceFixture());
        $handler = $definition->handlers[0];

        self::assertSame([
            'required' => true,
            'contentType' => 'application/json',
            'jsonSchema' => $schema,
        ], $handler->inputPayload->inputManifest());
        self::assertSame([
            'setContentTypeIfEmpty' => false,
            'contentType' => 'application/json',
            'jsonSchema' => $schema,
        ], $handler->outputPayload->outputManifest());
    }

    public function testReflectsVirtualObjectPayloadAttributesWithoutChangingHandlerTypes(): void
    {
        $definition = ServiceReflector::fromObject(new ReflectorPayloadObjectFixture());

        self::assertSame(HandlerType::Exclusive, $definition->handlers[0]->type);
        self::assertSame([
            'required' => false,
            'contentType' => 'application/json',
            'jsonSchema' => ['type' => 'integer'],
        ], $definition->handlers[0]->inputPayload->inputManifest());

        self::assertSame(HandlerType::Shared, $definition->handlers[1]->type);
        self::assertSame([
            'setContentTypeIfEmpty' => false,
            'contentType' => 'application/json',
            'jsonSchema' => ['type' => 'integer'],
        ], $definition->handlers[1]->outputPayload->outputManifest());
    }

    public function testDuplicateInputAttributesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler method handle must have at most one Input attribute.');

        ServiceReflector::fromObject(self::duplicateInputFixture());
    }

    public function testDuplicateOutputAttributesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler method handle must have at most one Output attribute.');

        ServiceReflector::fromObject(self::duplicateOutputFixture());
    }

    public function testReflectsServiceCustomSerdePayloadAttributes(): void
    {
        $definition = ServiceReflector::fromObject(new ReflectorPayloadSerdeServiceFixture());
        $handler = $definition->handlers[0];

        self::assertInstanceOf(ReflectorPayloadSerde::class, $handler->inputPayload->serde());
        self::assertInstanceOf(ReflectorPayloadSerde::class, $handler->outputPayload->serde());
        self::assertSame(['required' => true, 'contentType' => 'application/x-reflector-serde'], $handler->inputPayload->inputManifest());
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/x-reflector-serde'], $handler->outputPayload->outputManifest());
    }

    public function testReflectsVirtualObjectCustomSerdePayloadAttributes(): void
    {
        $definition = ServiceReflector::fromObject(new ReflectorPayloadSerdeObjectFixture());

        self::assertSame(HandlerType::Exclusive, $definition->handlers[0]->type);
        self::assertSame(['required' => true, 'contentType' => 'application/x-reflector-serde'], $definition->handlers[0]->inputPayload->inputManifest());
        self::assertSame(HandlerType::Shared, $definition->handlers[1]->type);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/x-reflector-serde'], $definition->handlers[1]->outputPayload->outputManifest());
    }

    public function testReflectsWorkflowCustomSerdePayloadAttributes(): void
    {
        $definition = ServiceReflector::fromObject(new ReflectorPayloadSerdeWorkflowFixture());

        self::assertSame(HandlerType::Workflow, $definition->handlers[0]->type);
        self::assertSame(['required' => true, 'contentType' => 'application/x-reflector-serde'], $definition->handlers[0]->inputPayload->inputManifest());
        self::assertSame(HandlerType::Shared, $definition->handlers[1]->type);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/x-reflector-serde'], $definition->handlers[1]->outputPayload->outputManifest());
    }

    #[DataProvider('invalidSerdeAttributeProvider')]
    public function testInvalidSerdeAttributeCombinationsAreRejected(string $class, string $source, string $message): void
    {
        $fixtureClass = self::ensureDynamicFixture(__NAMESPACE__ . '\\' . $class, $source);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ServiceReflector::fromObject(new $fixtureClass());
    }

    public function testReflectsJsonPayloadMetadataWithoutStoredSchemaInference(): void
    {
        $definition = ServiceReflector::fromObject(new ReflectorPayloadInferredServiceFixture());
        $handler = $definition->handlers[0];

        self::assertSame(
            ['required' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $handler->inputPayload->inputManifest(),
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $handler->outputPayload->outputManifest(),
        );
    }

    public function testReflectsEmptyPayloadMetadataFromNoPayloadAndVoidReturn(): void
    {
        $definition = ServiceReflector::fromObject(new ReflectorPayloadInferredServiceFixture());
        $handler = $definition->handlers[1];

        self::assertSame(['required' => false], $handler->inputPayload->inputManifest());
        self::assertSame(['setContentTypeIfEmpty' => false], $handler->outputPayload->outputManifest());
    }

    public function testReflectsSchemaLessExplicitPayloadAttributesWithoutStoredSchemaInference(): void
    {
        $definition = ServiceReflector::fromObject(new ReflectorPayloadInferredServiceFixture());
        $handler = $definition->handlers[2];

        self::assertSame(
            ['required' => true, 'contentType' => 'application/json', 'jsonSchema' => true],
            $handler->inputPayload->inputManifest(),
        );
        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $handler->outputPayload->outputManifest(),
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidSerdeAttributeProvider(): iterable
    {
        yield 'input serde with empty payload' => [
            'ReflectorInputSerdeEmptyFixture',
            <<<'PHP'
                namespace Restate\Tests\Unit\Sdk;

                #[\Restate\Attributes\Service('InputSerdeEmpty')]
                final class ReflectorInputSerdeEmptyFixture
                {
                    #[\Restate\Attributes\Handler('handle')]
                    #[\Restate\Attributes\Input(serde: \Restate\Tests\Unit\Sdk\ReflectorPayloadSerde::class, empty: true)]
                    public function handle(\Restate\Context $ctx): void
                    {
                    }
                }
                PHP,
            'Input custom serde payload cannot be empty.',
        ];

        yield 'input serde with binary payload' => [
            'ReflectorInputSerdeBinaryFixture',
            <<<'PHP'
                namespace Restate\Tests\Unit\Sdk;

                #[\Restate\Attributes\Service('InputSerdeBinary')]
                final class ReflectorInputSerdeBinaryFixture
                {
                    #[\Restate\Attributes\Handler('handle')]
                    #[\Restate\Attributes\Input(serde: \Restate\Tests\Unit\Sdk\ReflectorPayloadSerde::class, json: false)]
                    public function handle(\Restate\Context $ctx): void
                    {
                    }
                }
                PHP,
            'Input custom serde payload cannot be binary.',
        ];

        yield 'input serde with custom content type' => [
            'ReflectorInputSerdeContentTypeFixture',
            <<<'PHP'
                namespace Restate\Tests\Unit\Sdk;

                #[\Restate\Attributes\Service('InputSerdeContentType')]
                final class ReflectorInputSerdeContentTypeFixture
                {
                    #[\Restate\Attributes\Handler('handle')]
                    #[\Restate\Attributes\Input(serde: \Restate\Tests\Unit\Sdk\ReflectorPayloadSerde::class, contentType: 'application/x-other')]
                    public function handle(\Restate\Context $ctx): void
                    {
                    }
                }
                PHP,
            'Input custom serde payload cannot define a custom content type.',
        ];

        yield 'output serde with empty payload' => [
            'ReflectorOutputSerdeEmptyFixture',
            <<<'PHP'
                namespace Restate\Tests\Unit\Sdk;

                #[\Restate\Attributes\Service('OutputSerdeEmpty')]
                final class ReflectorOutputSerdeEmptyFixture
                {
                    #[\Restate\Attributes\Handler('handle')]
                    #[\Restate\Attributes\Output(serde: \Restate\Tests\Unit\Sdk\ReflectorPayloadSerde::class, empty: true)]
                    public function handle(\Restate\Context $ctx): void
                    {
                    }
                }
                PHP,
            'Output custom serde payload cannot be empty.',
        ];

        yield 'output serde with binary payload' => [
            'ReflectorOutputSerdeBinaryFixture',
            <<<'PHP'
                namespace Restate\Tests\Unit\Sdk;

                #[\Restate\Attributes\Service('OutputSerdeBinary')]
                final class ReflectorOutputSerdeBinaryFixture
                {
                    #[\Restate\Attributes\Handler('handle')]
                    #[\Restate\Attributes\Output(serde: \Restate\Tests\Unit\Sdk\ReflectorPayloadSerde::class, json: false)]
                    public function handle(\Restate\Context $ctx): void
                    {
                    }
                }
                PHP,
            'Output custom serde payload cannot be binary.',
        ];

        yield 'output serde with custom content type' => [
            'ReflectorOutputSerdeContentTypeFixture',
            <<<'PHP'
                namespace Restate\Tests\Unit\Sdk;

                #[\Restate\Attributes\Service('OutputSerdeContentType')]
                final class ReflectorOutputSerdeContentTypeFixture
                {
                    #[\Restate\Attributes\Handler('handle')]
                    #[\Restate\Attributes\Output(serde: \Restate\Tests\Unit\Sdk\ReflectorPayloadSerde::class, contentType: 'application/x-other')]
                    public function handle(\Restate\Context $ctx): void
                    {
                    }
                }
                PHP,
            'Output custom serde payload cannot define a custom content type.',
        ];

        yield 'input serde with required constructor arguments' => [
            'ReflectorInputSerdeConstructorFixture',
            <<<'PHP'
                namespace Restate\Tests\Unit\Sdk;

                #[\Restate\Attributes\Service('InputSerdeConstructor')]
                final class ReflectorInputSerdeConstructorFixture
                {
                    #[\Restate\Attributes\Handler('handle')]
                    #[\Restate\Attributes\Input(serde: \Restate\Tests\Unit\Sdk\ReflectorPayloadSerdeWithRequiredConstructor::class)]
                    public function handle(\Restate\Context $ctx): void
                    {
                    }
                }
                PHP,
            'Serde class Restate\\Tests\\Unit\\Sdk\\ReflectorPayloadSerdeWithRequiredConstructor must be instantiable without constructor arguments.',
        ];

        yield 'output serde with required constructor arguments' => [
            'ReflectorOutputSerdeConstructorFixture',
            <<<'PHP'
                namespace Restate\Tests\Unit\Sdk;

                #[\Restate\Attributes\Service('OutputSerdeConstructor')]
                final class ReflectorOutputSerdeConstructorFixture
                {
                    #[\Restate\Attributes\Handler('handle')]
                    #[\Restate\Attributes\Output(serde: \Restate\Tests\Unit\Sdk\ReflectorPayloadSerdeWithRequiredConstructor::class)]
                    public function handle(\Restate\Context $ctx): void
                    {
                    }
                }
                PHP,
            'Serde class Restate\\Tests\\Unit\\Sdk\\ReflectorPayloadSerdeWithRequiredConstructor must be instantiable without constructor arguments.',
        ];
    }

    private static function duplicateInputFixture(): object
    {
        $class = self::ensureDynamicFixture(__NAMESPACE__ . '\\ReflectorDuplicateInputFixture', <<<'PHP'
            namespace Restate\Tests\Unit\Sdk;

            #[\Restate\Attributes\Service('DuplicateInput')]
            final class ReflectorDuplicateInputFixture
            {
                #[\Restate\Attributes\Handler('handle')]
                #[\Restate\Attributes\Input]
                #[\Restate\Attributes\Input]
                public function handle(\Restate\Context $ctx): void
                {
                }
            }
            PHP);

        return new $class();
    }

    private static function duplicateOutputFixture(): object
    {
        $class = self::ensureDynamicFixture(__NAMESPACE__ . '\\ReflectorDuplicateOutputFixture', <<<'PHP'
            namespace Restate\Tests\Unit\Sdk;

            #[\Restate\Attributes\Service('DuplicateOutput')]
            final class ReflectorDuplicateOutputFixture
            {
                #[\Restate\Attributes\Handler('handle')]
                #[\Restate\Attributes\Output]
                #[\Restate\Attributes\Output]
                public function handle(\Restate\Context $ctx): void
                {
                }
            }
            PHP);

        return new $class();
    }

    /**
     * @return class-string
     */
    private static function ensureDynamicFixture(string $class, string $source): string
    {
        if (!class_exists($class, false)) {
            eval($source);
        }

        if (!class_exists($class, false)) {
            throw new \RuntimeException("Failed to define {$class}.");
        }

        return $class;
    }
}
