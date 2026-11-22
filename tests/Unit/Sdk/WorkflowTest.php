<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\HandlerDefinition;
use Restate\ServiceDefinition;
use Restate\Workflow;

final class WorkflowTest extends TestCase
{
    public function testBuilderCreatesWorkflowDefinition(): void
    {
        $run = static fn (Context $ctx): string => 'started';
        $approve = static fn (Context $ctx): string => 'approved';

        $definition = Workflow::new('Checkout')
            ->run($run)
            ->sharedHandler('approve', $approve)
            ->definition();

        self::assertSame('Checkout', $definition->name);
        self::assertSame(ServiceDefinition::TYPE_WORKFLOW, $definition->type);
        self::assertCount(2, $definition->handlers);
        self::assertSame('run', $definition->handlers[0]->name);
        self::assertSame(HandlerDefinition::TYPE_WORKFLOW, $definition->handlers[0]->type);
        self::assertSame('approve', $definition->handlers[1]->name);
        self::assertSame(HandlerDefinition::TYPE_SHARED, $definition->handlers[1]->type);
    }

    public function testDuplicateSharedHandlerNamesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler Checkout/approve is already registered.');

        Workflow::new('Checkout')
            ->sharedHandler('approve', static fn (Context $ctx): string => 'approved')
            ->sharedHandler('approve', static fn (Context $ctx): string => 'approved');
    }

    public function testDuplicateRunHandlerIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler Checkout/run is already registered.');

        Workflow::new('Checkout')
            ->run(static fn (Context $ctx): string => 'started')
            ->run(static fn (Context $ctx): string => 'started');
    }

    public function testBuilderIsImmutable(): void
    {
        $workflow = Workflow::new('Checkout');
        $withRun = $workflow->run(static fn (Context $ctx): string => 'started');
        $withSharedHandler = $withRun->sharedHandler('approve', static fn (Context $ctx): string => 'approved');

        try {
            $workflow->definition();
            self::fail('Expected the original workflow builder to remain without a run handler.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Workflow Checkout must define a run handler.', $e->getMessage());
        }

        self::assertCount(1, $withRun->definition()->handlers);
        self::assertCount(2, $withSharedHandler->definition()->handlers);
    }

    public function testDefinitionRejectsWorkflowWithoutRunHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow Checkout must define a run handler.');

        Workflow::new('Checkout')
            ->sharedHandler('approve', static fn (Context $ctx): string => 'approved')
            ->definition();
    }
}
