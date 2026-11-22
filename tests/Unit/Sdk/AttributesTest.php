<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Restate\Attributes\Handler;
use Restate\Attributes\Input;
use Restate\Attributes\Output as OutputAttribute;
use Restate\Attributes\Service;
use Restate\Attributes\Shared;
use Restate\Attributes\VirtualObject;
use Restate\Attributes\Workflow as WorkflowAttribute;
use Restate\InvocationConfiguration;
use Restate\InvocationRetryPolicy;

#[Service('Greeter', documentation: 'Greeter service.', metadata: ['domain' => 'examples'], invocationRetryPolicy: new InvocationRetryPolicy(initialIntervalMillis: 100), invocationConfiguration: new InvocationConfiguration(inactivityTimeoutMillis: 1000, ingressPrivate: true))]
final class AttributeServiceFixture
{
    #[Handler('greet', documentation: 'Greets a person.', metadata: ['owner' => 'sdk'], invocationRetryPolicy: new InvocationRetryPolicy(maxAttempts: 3, onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_KILL), invocationConfiguration: new InvocationConfiguration(abortTimeoutMillis: 500, idempotencyRetentionMillis: 5000))]
    public function greet(): void
    {
    }
}

#[VirtualObject('Counter')]
final class AttributeVirtualObjectFixture
{
    #[Handler('add')]
    public function add(): void
    {
    }

    #[Handler('get')]
    #[Shared]
    public function get(): void
    {
    }
}

#[WorkflowAttribute('Checkout')]
final class AttributeWorkflowFixture
{
    #[Handler('run')]
    public function run(): void
    {
    }
}

final class AttributesTest extends TestCase
{
    public function testServiceAttributeStoresName(): void
    {
        $attribute = new ReflectionClass(AttributeServiceFixture::class)
            ->getAttributes(Service::class)[0]
            ->newInstance();

        self::assertSame('Greeter', $attribute->name);
        self::assertSame('Greeter service.', $attribute->documentation);
        self::assertSame(['domain' => 'examples'], $attribute->metadata);
        self::assertSame(['retryPolicyInitialInterval' => 100], $attribute->invocationRetryPolicy?->manifestFields());
        self::assertSame(['inactivityTimeout' => 1000, 'ingressPrivate' => true], $attribute->invocationConfiguration?->manifestFields());
    }

    public function testVirtualObjectAttributeStoresName(): void
    {
        $attribute = new ReflectionClass(AttributeVirtualObjectFixture::class)
            ->getAttributes(VirtualObject::class)[0]
            ->newInstance();

        self::assertSame('Counter', $attribute->name);
    }

    public function testWorkflowAttributeStoresName(): void
    {
        $attribute = new ReflectionClass(AttributeWorkflowFixture::class)
            ->getAttributes(WorkflowAttribute::class)[0]
            ->newInstance();

        self::assertSame('Checkout', $attribute->name);
    }

    public function testHandlerAttributeStoresName(): void
    {
        $attribute = new ReflectionMethod(AttributeServiceFixture::class, 'greet')
            ->getAttributes(Handler::class)[0]
            ->newInstance();

        self::assertSame('greet', $attribute->name);
        self::assertSame('Greets a person.', $attribute->documentation);
        self::assertSame(['owner' => 'sdk'], $attribute->metadata);
        self::assertSame([
            'retryPolicyMaxAttempts' => 3,
            'retryPolicyOnMaxAttempts' => 'KILL',
        ], $attribute->invocationRetryPolicy?->manifestFields());
        self::assertSame(['abortTimeout' => 500, 'idempotencyRetention' => 5000], $attribute->invocationConfiguration?->manifestFields());
    }

    public function testInvocationConfigurationRejectsNegativeDurations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invocation configuration inactivity timeout must be greater than or equal to 0 milliseconds.');

        new InvocationConfiguration(inactivityTimeoutMillis: -1);
    }

    public function testSharedAttributeCanMarkVirtualObjectHandler(): void
    {
        $attributes = new ReflectionMethod(AttributeVirtualObjectFixture::class, 'get')
            ->getAttributes(Shared::class);

        self::assertCount(1, $attributes);
    }

    public function testInputAttributeCreatesJsonPayload(): void
    {
        $schema = ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]];
        $attribute = new Input(schema: $schema, required: true);

        self::assertSame($schema, $attribute->schema);
        self::assertTrue($attribute->required);
        self::assertSame('application/json', $attribute->contentType);
        self::assertTrue($attribute->json);
        self::assertFalse($attribute->empty);
        self::assertSame([
            'required' => true,
            'contentType' => 'application/json',
            'jsonSchema' => $schema,
        ], $attribute->payload()->inputManifest());
    }

    public function testInputAttributeCanAdvertiseAcceptedContentTypePattern(): void
    {
        $attribute = new Input(accept: 'application/*+json, application/json');

        self::assertSame([
            'required' => false,
            'contentType' => 'application/*+json, application/json',
            'jsonSchema' => true,
        ], $attribute->payload()->inputManifest());
    }

    public function testOutputAttributeCreatesJsonPayload(): void
    {
        $schema = ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]];
        $attribute = new OutputAttribute(schema: $schema);

        self::assertSame($schema, $attribute->schema);
        self::assertSame('application/json', $attribute->contentType);
        self::assertTrue($attribute->json);
        self::assertFalse($attribute->empty);
        self::assertSame([
            'setContentTypeIfEmpty' => false,
            'contentType' => 'application/json',
            'jsonSchema' => $schema,
        ], $attribute->payload()->outputManifest());
    }

    public function testOutputAttributeCanSetContentTypeIfEmpty(): void
    {
        $attribute = new OutputAttribute(setContentTypeIfEmpty: true);

        self::assertSame([
            'setContentTypeIfEmpty' => true,
            'contentType' => 'application/json',
            'jsonSchema' => true,
        ], $attribute->payload()->outputManifest());
    }

    public function testInputAttributeCreatesBinaryPayload(): void
    {
        $attribute = new Input(contentType: 'application/octet-stream', json: false);

        self::assertSame([
            'required' => false,
            'contentType' => 'application/octet-stream',
        ], $attribute->payload()->inputManifest());
    }

    public function testInputAttributeCreatesEmptyPayload(): void
    {
        $attribute = new Input(empty: true);

        self::assertSame(['required' => false], $attribute->payload()->inputManifest());
    }

    public function testOutputAttributeCreatesEmptyPayload(): void
    {
        $attribute = new OutputAttribute(empty: true);

        self::assertSame(['setContentTypeIfEmpty' => false], $attribute->payload()->outputManifest());
    }

    public function testOutputAttributeCreatesBinaryPayload(): void
    {
        $attribute = new OutputAttribute(contentType: 'application/octet-stream', json: false);

        self::assertSame([
            'setContentTypeIfEmpty' => false,
            'contentType' => 'application/octet-stream',
        ], $attribute->payload()->outputManifest());
    }

    public function testPayloadAttributesRejectInvalidCombinations(): void
    {
        /** @var list<array{0: Input|OutputAttribute, 1: string}> $cases */
        $cases = [
            [new Input(schema: ['type' => 'string'], empty: true), 'Input payload cannot be empty and define a JSON schema.'],
            [new OutputAttribute(schema: ['type' => 'string'], empty: true), 'Output payload cannot be empty and define a JSON schema.'],
            [new Input(contentType: 'application/octet-stream', empty: true), 'Input payload cannot be empty and define a custom content type.'],
            [new OutputAttribute(contentType: 'application/octet-stream', empty: true), 'Output payload cannot be empty and define a custom content type.'],
            [new Input(required: true, empty: true), 'Input empty payload cannot be required.'],
            [new Input(contentType: 'application/problem+json'), 'Input JSON payload content type must be application/json.'],
            [new OutputAttribute(contentType: 'application/problem+json'), 'Output JSON payload content type must be application/json.'],
            [new Input(schema: ['type' => 'string'], json: false), 'Input binary payload cannot define a JSON schema.'],
            [new OutputAttribute(schema: ['type' => 'string'], json: false), 'Output binary payload cannot define a JSON schema.'],
            [new Input(contentType: 'application/octet-stream', json: false, required: true), 'Input binary payload cannot be required.'],
            [new Input(contentType: '', json: false), 'Input payload content type must not be empty.'],
            [new Input(accept: ''), 'Input payload accept content type must not be empty.'],
            [new Input(accept: 'application/json', empty: true), 'Input payload cannot be empty and define an accept content type.'],
            [new OutputAttribute(contentType: '', json: false), 'Output payload content type must not be empty.'],
        ];

        foreach ($cases as [$attribute, $message]) {
            try {
                $attribute->payload();
                self::fail("Expected {$message}");
            } catch (InvalidArgumentException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }
}
