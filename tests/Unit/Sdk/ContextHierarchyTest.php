<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

/**
 * The handler contexts are interfaces in the hierarchy of the official SDKs: a context can be passed wherever one of
 * the contexts it extends is expected, and never where a context with more capabilities is.
 */
final class ContextHierarchyTest extends TestCase
{
    /** @return iterable<string, array{class-string, list<class-string>}> */
    public static function contexts(): iterable
    {
        yield 'Context' => [Context::class, []];
        yield 'ObjectSharedContext' => [ObjectSharedContext::class, [Context::class]];
        yield 'ObjectContext' => [ObjectContext::class, [Context::class, ObjectSharedContext::class]];
        yield 'WorkflowSharedContext' => [WorkflowSharedContext::class, [Context::class, ObjectSharedContext::class]];
        yield 'WorkflowContext' => [WorkflowContext::class, [Context::class, ObjectContext::class, ObjectSharedContext::class, WorkflowSharedContext::class]];
    }

    /** @param list<class-string> $extends */
    #[DataProvider('contexts')]
    public function testContextIsAnInterfaceExtendingExactlyTheContextsWithFewerCapabilities(string $context, array $extends): void
    {
        self::assertTrue(interface_exists($context));

        $contexts = [Context::class, ObjectSharedContext::class, ObjectContext::class, WorkflowSharedContext::class, WorkflowContext::class];
        $actual = array_values(array_filter($contexts, static fn (string $other): bool => $other !== $context && is_a($context, $other, true)));

        self::assertEqualsCanonicalizing($extends, $actual);
    }
}
