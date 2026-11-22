<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\TerminalError;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class ContextAwakeableTest extends TestCase
{
    public function testAwakeableCreatesHandleFromBackendResult(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        $awakeable = $ctx->awakeable();

        self::assertSame('awakeable-1', $awakeable->id());
        self::assertSame(['sysAwakeable'], $invocation->operations());
    }

    public function testResolveAwakeableJsonEncodesPayload(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        $ctx->resolveAwakeable('awk-1', ['status' => 'ok']);

        self::assertSame([['awk-1', '{"status":"ok"}']], $invocation->calls('sysResolveAwakeable'));
    }

    public function testResolveAwakeableDefaultsPayloadToNull(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        $ctx->resolveAwakeable('awk-1');

        self::assertSame([['awk-1', 'null']], $invocation->calls('sysResolveAwakeable'));
    }

    public function testRejectAwakeableDelegatesTerminalError(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        $ctx->rejectAwakeable('awk-1', new TerminalError('denied', 409, ['key1' => 'value1']));

        self::assertSame([['awk-1', 409, 'denied', ['key1' => 'value1']]], $invocation->calls('sysRejectAwakeable'));
    }
}
