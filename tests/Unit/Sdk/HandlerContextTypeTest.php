<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Attributes as Restate;
use Restate\Context;
use Restate\Internal\Context\ObjectContextImpl;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Reflection\ServiceReflector;
use Restate\Service;
use Restate\ServiceDefinition;
use Restate\VirtualObject;
use Restate\Workflow;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

#[Restate\VirtualObject('ContextTypeCounter')]
final class HandlerContextTypeSharedWriterFixture
{
    #[Restate\Handler('get')]
    #[Restate\Shared]
    public function get(ObjectContext $ctx): int
    {
        return 0;
    }
}

/**
 * A handler may declare the context its kind receives or any context that context extends. Declaring a context with
 * more capabilities (or an implementation class) would fail with a TypeError on every invocation, so building the
 * service definition rejects it.
 */
final class HandlerContextTypeTest extends TestCase
{
    /** @return iterable<string, array{\Closure(): ServiceDefinition}> */
    public static function acceptedContexts(): iterable
    {
        yield 'service handler, Context' => [static fn (): ServiceDefinition => Service::new('Greeter')->handler('greet', static fn (Context $ctx): null => null)->definition()];
        yield 'exclusive handler, ObjectContext' => [static fn (): ServiceDefinition => VirtualObject::new('Counter')->handler('add', static fn (ObjectContext $ctx): null => null)->definition()];
        yield 'exclusive handler, ObjectSharedContext' => [static fn (): ServiceDefinition => VirtualObject::new('Counter')->handler('add', static fn (ObjectSharedContext $ctx): null => null)->definition()];
        yield 'exclusive handler, Context' => [static fn (): ServiceDefinition => VirtualObject::new('Counter')->handler('add', static fn (Context $ctx): null => null)->definition()];
        yield 'shared object handler, ObjectSharedContext' => [static fn (): ServiceDefinition => VirtualObject::new('Counter')->sharedHandler('get', static fn (ObjectSharedContext $ctx): null => null)->definition()];
        yield 'shared object handler, nullable Context' => [static fn (): ServiceDefinition => VirtualObject::new('Counter')->sharedHandler('get', static fn (?Context $ctx): null => null)->definition()];
        yield 'workflow run, WorkflowContext' => [static fn (): ServiceDefinition => Workflow::new('Signup')->run(static fn (WorkflowContext $ctx): null => null)->definition()];
        yield 'workflow run, ObjectContext' => [static fn (): ServiceDefinition => Workflow::new('Signup')->run(static fn (ObjectContext $ctx): null => null)->definition()];
        yield 'workflow run, WorkflowSharedContext' => [static fn (): ServiceDefinition => Workflow::new('Signup')->run(static fn (WorkflowSharedContext $ctx): null => null)->definition()];
        yield 'shared workflow handler, WorkflowSharedContext' => [
            static fn (): ServiceDefinition => Workflow::new('Signup')
                ->run(static fn (WorkflowContext $ctx): null => null)
                ->sharedHandler('status', static fn (WorkflowSharedContext $ctx): null => null)
                ->definition(),
        ];
    }

    /** @param \Closure(): ServiceDefinition $define */
    #[DataProvider('acceptedContexts')]
    public function testHandlerMayDeclareTheContextItReceivesOrOneItExtends(\Closure $define): void
    {
        self::assertNotEmpty($define()->handlers);
    }

    /** @return iterable<string, array{\Closure(): ServiceDefinition, string}> */
    public static function rejectedContexts(): iterable
    {
        yield 'service handler, ObjectSharedContext' => [
            static fn (): ServiceDefinition => Service::new('Greeter')->handler('greet', static fn (ObjectSharedContext $ctx): null => null)->definition(),
            'Service Greeter/greet handler declares context Restate\ObjectSharedContext, but receives a Restate\Context.',
        ];
        yield 'shared object handler, ObjectContext' => [
            static fn (): ServiceDefinition => VirtualObject::new('Counter')->sharedHandler('get', static fn (ObjectContext $ctx): null => null)->definition(),
            'Virtual Object Counter/get handler declares context Restate\ObjectContext, but receives a Restate\ObjectSharedContext.',
        ];
        yield 'exclusive handler, WorkflowContext' => [
            static fn (): ServiceDefinition => VirtualObject::new('Counter')->handler('add', static fn (WorkflowContext $ctx): null => null)->definition(),
            'Virtual Object Counter/add handler declares context Restate\WorkflowContext, but receives a Restate\ObjectContext.',
        ];
        yield 'shared workflow handler, ObjectContext' => [
            static fn (): ServiceDefinition => Workflow::new('Signup')
                ->run(static fn (WorkflowContext $ctx): null => null)
                ->sharedHandler('status', static fn (ObjectContext $ctx): null => null)
                ->definition(),
            'Workflow Signup/status handler declares context Restate\ObjectContext, but receives a Restate\WorkflowSharedContext.',
        ];
        yield 'an implementation class' => [
            static fn (): ServiceDefinition => VirtualObject::new('Counter')->handler('add', static fn (ObjectContextImpl $ctx): null => null)->definition(),
            'Virtual Object Counter/add handler declares context Restate\Internal\Context\ObjectContextImpl, but receives a Restate\ObjectContext.',
        ];
        yield 'reflected shared object handler, ObjectContext' => [
            static fn (): ServiceDefinition => ServiceReflector::fromObject(new HandlerContextTypeSharedWriterFixture()),
            'Virtual Object ContextTypeCounter/get handler declares context Restate\ObjectContext, but receives a Restate\ObjectSharedContext.',
        ];
    }

    /** @param \Closure(): ServiceDefinition $define */
    #[DataProvider('rejectedContexts')]
    public function testHandlerMustNotDeclareAContextWithMoreCapabilitiesThanItReceives(\Closure $define, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $define();
    }
}
