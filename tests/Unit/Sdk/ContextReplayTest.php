<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\Internal\InvocationOperations;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class ContextReplayTest extends TestCase
{
    public function testContextDefaultsToNotReplayingWhenBackendDoesNotExposeState(): void
    {
        $ctx = Contexts::service(self::createStub(InvocationOperations::class));

        self::assertFalse($ctx->isReplaying());
    }

    public function testContextDelegatesReplayStateWhenBackendExposesState(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->replaying = true;
        $ctx = Contexts::service($invocation);

        self::assertTrue($ctx->isReplaying());
        self::assertCount(1, $invocation->calls('isReplaying'));
    }
}
