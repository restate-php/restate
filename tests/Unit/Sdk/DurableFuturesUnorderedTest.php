<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use Restate\Internal\Context\DurableFutureImpl;
use PHPUnit\Framework\TestCase;
use Restate\DurableFuture;
use Restate\DurableFuturesUnordered;
use Restate\Internal\AwaitCombinator;
use Restate\Internal\Backend\NotificationResult;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class DurableFuturesUnorderedTest extends TestCase
{
    public function testPushReturnsStableIndexesAndTracksRemainingFutures(): void
    {
        $invocation = ScriptedInvocation::new();
        $unordered = new DurableFuturesUnordered(Contexts::service($invocation));

        self::assertTrue($unordered->isEmpty());
        self::assertSame(0, $unordered->count());

        self::assertSame(0, $unordered->push(DurableFutureImpl::voidResult($invocation, 10, 'sleep', 'Sleep suspended.')));
        self::assertSame(1, $unordered->push(DurableFutureImpl::voidResult($invocation, 20, 'sleep', 'Sleep suspended.')));
        self::assertSame(2, $unordered->push(DurableFutureImpl::voidResult($invocation, 30, 'sleep', 'Sleep suspended.')));

        self::assertFalse($unordered->isEmpty());
        self::assertSame(3, $unordered->count());
    }

    public function testFromAndPushAllSeedCollectionWithStableIndexes(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);
        $unordered = DurableFuturesUnordered::from($ctx, [
            'first' => DurableFutureImpl::voidResult($invocation, 10, 'sleep', 'Sleep suspended.'),
        ]);

        self::assertSame(1, $unordered->count());
        self::assertSame([
            'second' => 1,
            'third' => 2,
        ], $unordered->pushAll([
            'second' => DurableFutureImpl::voidResult($invocation, 20, 'sleep', 'Sleep suspended.'),
            'third' => DurableFutureImpl::voidResult($invocation, 30, 'sleep', 'Sleep suspended.'),
        ]));
        self::assertSame(3, $unordered->count());
    }

    public function testNextSelectsCompletedFutureAwaitsItAndRemovesIt(): void
    {
        $invocation = ScriptedInvocation::new();
        // The results arrive in this order.
        $invocation->complete(20, NotificationResult::success('{"winner":"fast"}'));
        $invocation->complete(30, NotificationResult::success('{"winner":"middle"}'));
        $unordered = new DurableFuturesUnordered(Contexts::service($invocation));

        $unordered->push(DurableFutureImpl::jsonResult($invocation, 10, 'Service call', 'Service call suspended.'));
        $unordered->push(DurableFutureImpl::jsonResult($invocation, 20, 'Service call', 'Service call suspended.'));
        $unordered->push(DurableFutureImpl::jsonResult($invocation, 30, 'Service call', 'Service call suspended.'));

        self::assertSame(['index' => 1, 'value' => ['winner' => 'fast']], $unordered->next());
        self::assertSame(2, $unordered->count());
        self::assertFalse($unordered->isEmpty());
        self::assertEquals([AwaitCombinator::FirstCompleted->tree([10, 20, 30])], $invocation->awaitedTrees());
        self::assertSame([20], $invocation->awaited());
        self::assertSame([20], $invocation->taken());

        self::assertSame(['index' => 2, 'value' => ['winner' => 'middle']], $unordered->next());
        self::assertSame(1, $unordered->count());
        self::assertFalse($unordered->isEmpty());
        self::assertEquals([AwaitCombinator::FirstCompleted->tree([10, 20, 30]), AwaitCombinator::FirstCompleted->tree([10, 30])], $invocation->awaitedTrees());
        self::assertSame([20, 30], $invocation->awaited());
        self::assertSame([20, 30], $invocation->taken());
    }

    public function testNextReturnsAlreadyCompletedFutureWithoutAwaiting(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(10, NotificationResult::void());
        $completed = DurableFutureImpl::voidResult($invocation, 10, 'sleep', 'Sleep suspended.');
        $completed->awaitResult();
        $unordered = new DurableFuturesUnordered(Contexts::service($invocation));

        $unordered->push($completed);
        $unordered->push(DurableFutureImpl::voidResult($invocation, 20, 'sleep', 'Sleep suspended.'));

        self::assertSame(['index' => 0, 'value' => null], $unordered->next());
        self::assertSame([], $invocation->awaitedTrees());
        self::assertSame(1, $unordered->count());
    }

    public function testNextReturnsNullWhenEmpty(): void
    {
        $unordered = new DurableFuturesUnordered(Contexts::service(ScriptedInvocation::new()));

        self::assertNull($unordered->next());
    }
}
