<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\HandlerDefinition;
use Restate\Payload;
use Restate\Service;
use Restate\VirtualObject;
use Restate\Workflow;

final class ServiceBuilderTest extends TestCase
{
    public function testServiceHandlerStoresPayloadMetadata(): void
    {
        $input = Payload::json(['type' => 'string'], required: true);
        $output = Payload::binary('application/octet-stream');

        $definition = Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => $name, $input, $output)->definition();

        self::assertSame($input, $definition->handlers[0]->inputPayload);
        self::assertSame($output, $definition->handlers[0]->outputPayload);
    }

    public function testVirtualObjectHandlersStorePayloadMetadata(): void
    {
        $definition = VirtualObject::new('Counter')
            ->handler('add', static fn (Context $ctx, int $amount): int => $amount, Payload::json(['type' => 'integer']))
            ->sharedHandler('get', static fn (Context $ctx): int => 1, output: Payload::json(['type' => 'integer']))
            ->definition();

        self::assertSame(HandlerDefinition::TYPE_EXCLUSIVE, $definition->handlers[0]->type);
        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'integer']], $definition->handlers[0]->inputPayload->inputManifest());
        self::assertSame(HandlerDefinition::TYPE_SHARED, $definition->handlers[1]->type);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'integer']], $definition->handlers[1]->outputPayload->outputManifest());
    }

    public function testWorkflowHandlersStorePayloadMetadata(): void
    {
        $runInput = Payload::json(['type' => 'string']);
        $statusOutput = Payload::json(['type' => 'string']);

        $definition = Workflow::new('Checkout')
            ->run(static fn (Context $ctx, string $order): string => $order, $runInput)
            ->sharedHandler('status', static fn (Context $ctx): string => 'ok', output: $statusOutput)
            ->definition();

        self::assertSame(HandlerDefinition::TYPE_WORKFLOW, $definition->handlers[0]->type);
        self::assertSame($runInput, $definition->handlers[0]->inputPayload);
        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']], $definition->handlers[0]->inputPayload->inputManifest());
        self::assertSame(HandlerDefinition::TYPE_SHARED, $definition->handlers[1]->type);
        self::assertSame($statusOutput, $definition->handlers[1]->outputPayload);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']], $definition->handlers[1]->outputPayload->outputManifest());
    }

    #[DataProvider('reservedWorkflowHandlerNameProvider')]
    public function testWorkflowRejectsReservedSharedHandlerNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow handler {$name} is reserved by Restate.");

        Workflow::new('Checkout')->sharedHandler($name, static fn (Context $ctx): null => null);
    }

    /** @return iterable<string, array{string}> */
    public static function reservedWorkflowHandlerNameProvider(): iterable
    {
        yield 'workflow submit' => ['workflowSubmit'];
        yield 'workflow attach' => ['workflowAttach'];
        yield 'workflow output' => ['workflowOutput'];
    }
}
