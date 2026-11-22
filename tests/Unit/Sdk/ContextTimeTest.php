<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\Internal\Backend\NotificationResult;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class ContextTimeTest extends TestCase
{
    public function testNowUsesDurableRunOnFirstExecution(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);
        $before = (int) floor(microtime(true) * 1000);

        $timestamp = $ctx->now();

        $after = (int) floor(microtime(true) * 1000);
        self::assertGreaterThanOrEqual($before - 100, $timestamp);
        self::assertLessThanOrEqual($after + 100, $timestamp);
        [$run] = $invocation->handles('sysRun');
        self::assertSame([['date.now']], $invocation->calls('sysRun'));
        self::assertSame([], $invocation->calls('sysSleep'));
        self::assertSame([$run, $run], $invocation->awaited());
        self::assertSame([$run], $invocation->taken());
        $completions = $invocation->calls('proposeRunCompletionSuccess');
        self::assertCount(1, $completions);
        self::assertSame($run, $completions[0][0]);
        self::assertIsString($completions[0][1]);
        self::assertJson($completions[0][1]);
        self::assertSame($timestamp, json_decode($completions[0][1], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testNowReplayReturnsStoredValueWithoutProposingNewCompletion(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysRun', NotificationResult::success(json_encode(1700000000123, JSON_THROW_ON_ERROR)));
        $ctx = Contexts::service($invocation);

        $timestamp = $ctx->now();

        self::assertSame(1700000000123, $timestamp);
        [$run] = $invocation->handles('sysRun');
        self::assertSame([['date.now']], $invocation->calls('sysRun'));
        self::assertSame([$run], $invocation->awaited());
        self::assertSame([$run], $invocation->taken());
        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
    }

    public function testNowCanBeUsedBeforeExplicitRun(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        $ctx->now();
        $result = $ctx->run('after-now', static fn (): string => 'ok');

        self::assertSame('ok', $result);
        self::assertSame([['date.now'], ['after-now']], $invocation->calls('sysRun'));
        self::assertCount(2, $invocation->calls('proposeRunCompletionSuccess'));
    }

    public function testNowCanBeUsedBeforeSleep(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        $ctx->now();
        $ctx->sleep(25);

        [$run] = $invocation->handles('sysRun');
        [$sleep] = $invocation->handles('sysSleep');
        self::assertSame([['date.now']], $invocation->calls('sysRun'));
        self::assertSame([[25]], $invocation->calls('sysSleep'));
        self::assertSame([$run, $run, $sleep], $invocation->awaited());
        self::assertSame([$run, $sleep], $invocation->taken());
    }
}
