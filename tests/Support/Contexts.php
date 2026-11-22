<?php

declare(strict_types=1);

namespace Restate\Tests\Support;

use Restate\Context;
use Restate\Internal\Context\ContextImpl;
use Restate\Internal\Context\ObjectContextImpl;
use Restate\Internal\Context\ObjectSharedContextImpl;
use Restate\Internal\Context\WorkflowContextImpl;
use Restate\Internal\Context\WorkflowSharedContextImpl;
use Restate\Internal\ExecutionHooks;
use Restate\Internal\InvocationOperations;
use Restate\JournalValueCodec;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Request;
use Restate\Serde;
use Restate\Target;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

/**
 * Builds the handler contexts the SDK hands to handlers, on the given operations (usually a {@see ScriptedInvocation}).
 */
final class Contexts
{
    /**
     * @param Serde|class-string<Serde>|null $defaultSerde
     * @param JournalValueCodec|class-string<JournalValueCodec>|null $journalValueCodec
     */
    public static function service(InvocationOperations $vm, int $randomSeed = 0, Serde|string|null $defaultSerde = null, JournalValueCodec|string|null $journalValueCodec = null, ?ExecutionHooks $hooks = null): Context
    {
        return new ContextImpl($vm, self::request(Target::service('TestService', 'handle')), $randomSeed, $defaultSerde, $journalValueCodec, $hooks);
    }

    /**
     * @param Serde|class-string<Serde>|null $defaultSerde
     * @param JournalValueCodec|class-string<JournalValueCodec>|null $journalValueCodec
     */
    public static function object(InvocationOperations $vm, string $key, int $randomSeed = 0, Serde|string|null $defaultSerde = null, JournalValueCodec|string|null $journalValueCodec = null): ObjectContext
    {
        return new ObjectContextImpl($vm, self::request(Target::object('TestObject', $key, 'handle')), $randomSeed, $defaultSerde, $journalValueCodec);
    }

    /**
     * @param Serde|class-string<Serde>|null $defaultSerde
     * @param JournalValueCodec|class-string<JournalValueCodec>|null $journalValueCodec
     */
    public static function objectShared(InvocationOperations $vm, string $key, int $randomSeed = 0, Serde|string|null $defaultSerde = null, JournalValueCodec|string|null $journalValueCodec = null): ObjectSharedContext
    {
        return new ObjectSharedContextImpl($vm, self::request(Target::object('TestObject', $key, 'handle')), $randomSeed, $defaultSerde, $journalValueCodec);
    }

    /**
     * @param Serde|class-string<Serde>|null $defaultSerde
     * @param JournalValueCodec|class-string<JournalValueCodec>|null $journalValueCodec
     */
    public static function workflow(InvocationOperations $vm, string $key, int $randomSeed = 0, Serde|string|null $defaultSerde = null, JournalValueCodec|string|null $journalValueCodec = null): WorkflowContext
    {
        return new WorkflowContextImpl($vm, self::request(Target::workflow('TestWorkflow', $key, 'handle')), $randomSeed, $defaultSerde, $journalValueCodec);
    }

    /**
     * @param Serde|class-string<Serde>|null $defaultSerde
     * @param JournalValueCodec|class-string<JournalValueCodec>|null $journalValueCodec
     */
    public static function workflowShared(InvocationOperations $vm, string $key, int $randomSeed = 0, Serde|string|null $defaultSerde = null, JournalValueCodec|string|null $journalValueCodec = null): WorkflowSharedContext
    {
        return new WorkflowSharedContextImpl($vm, self::request(Target::workflow('TestWorkflow', $key, 'handle')), $randomSeed, $defaultSerde, $journalValueCodec);
    }

    /** A request with the given target and nothing else. */
    public static function request(Target $target): Request
    {
        return new Request('inv_test', $target, [], [], '');
    }

    /**
     * The narrowest public context interface the context implements, so a test can tell which context a handler got.
     *
     * @return class-string<Context>
     */
    public static function interfaceOf(Context $ctx): string
    {
        foreach ([WorkflowContext::class, WorkflowSharedContext::class, ObjectContext::class, ObjectSharedContext::class] as $interface) {
            if ($ctx instanceof $interface) {
                return $interface;
            }
        }

        return Context::class;
    }
}
