<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use Restate\Internal\Context\DurableFutureImpl;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\DurableFuture;
use Restate\DurableFutureAggregateException;
use Restate\Internal\AwaitCombinator;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\Context\ContextImpl;
use Restate\Internal\SuspensionException;
use Restate\Serde;
use Restate\Target;
use Restate\TerminalError;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class ContextFutureTest extends TestCase
{
    public function testCallFutureDelegatesToSysCallAndReturnsFutureThatDecodesSuccess(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysCall', NotificationResult::success('{"message":"Hello Mary"}'));
        $ctx = Contexts::service($invocation);

        $future = $ctx->callFuture(Target::service('Greeter', 'greet'), ['name' => 'Mary']);

        self::assertSame(['message' => 'Hello Mary'], $future->awaitResult());
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), '{"name":"Mary"}', null]], $invocation->calls('sysCall'));
        self::assertSame([1], $invocation->awaited());
        self::assertSame([1], $invocation->taken());
    }

    public function testCallDelegatesSemanticallyToCallFutureAndPreservesBehavior(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysCall', NotificationResult::success('{"status":"ok"}'));
        $ctx = new ContextWithCallFutureSpy($invocation, Contexts::request(Target::service('TestService', 'handle')));

        self::assertSame(['status' => 'ok'], $ctx->call(Target::service('Greeter', 'greet'), ['name' => 'Mary']));
        self::assertSame(1, $ctx->callFutureCalls);
        self::assertEquals([[new CallTarget(Target::service('Greeter', 'greet')), '{"name":"Mary"}', null]], $invocation->calls('sysCall'));
    }

    public function testSleepFutureDelegatesToSysSleepAndReturnsFutureThatAwaitsVoid(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysSleep', NotificationResult::void());
        $ctx = Contexts::service($invocation);

        $future = $ctx->sleepFuture(250);

        self::assertNull($future->awaitResult());
        self::assertSame([[250]], $invocation->calls('sysSleep'));
        self::assertSame([1], $invocation->awaited());
        self::assertSame([1], $invocation->taken());
    }

    public function testSleepDelegatesSemanticallyToSleepFutureAndPreservesValidation(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysSleep', NotificationResult::void());
        $ctx = new ContextWithSleepFutureSpy($invocation, Contexts::request(Target::service('TestService', 'handle')));

        $ctx->sleep(250);

        self::assertSame(1, $ctx->sleepFutureCalls);
        self::assertSame([[250]], $invocation->calls('sysSleep'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sleep delay must be greater than or equal to 0 milliseconds.');

        Contexts::service(ScriptedInvocation::new())->sleep(-1);
    }

    public function testAwakeableFutureReturnsIdAndFutureThatDecodesSuccess(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysAwakeable', NotificationResult::success('{"status":"awake"}'));
        $ctx = Contexts::service($invocation);

        $awakeable = $ctx->awakeableFuture();

        self::assertSame('awakeable-1', $awakeable['id']);
        self::assertSame(['status' => 'awake'], $awakeable['future']->awaitResult());
        self::assertSame(['sysAwakeable', 'doAwait', 'takeNotificationResult'], $invocation->operations());
        self::assertSame([1], $invocation->awaited());
        self::assertSame([1], $invocation->taken());
    }

    public function testSelectReturnsOriginalKeyForCompletedHandleFromTheBackendAwait(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(22, NotificationResult::void());
        $ctx = Contexts::service($invocation);

        $selected = $ctx->select([
            'slow' => DurableFutureImpl::voidResult($invocation, 11, 'sleep', 'Sleep suspended.'),
            'fast' => DurableFutureImpl::jsonResult($invocation, 22, 'Service call', 'Service call suspended.'),
        ]);

        self::assertSame('fast', $selected);
        self::assertEquals([AwaitCombinator::FirstCompleted->tree([11, 22])], $invocation->awaitedTrees());
    }

    public function testSelectReturnsOriginalIntegerKeyForCompletedHandle(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(22, NotificationResult::void());
        $ctx = Contexts::service($invocation);

        $selected = $ctx->select([
            10 => DurableFutureImpl::voidResult($invocation, 11, 'sleep', 'Sleep suspended.'),
            20 => DurableFutureImpl::jsonResult($invocation, 22, 'Service call', 'Service call suspended.'),
        ]);

        self::assertSame(20, $selected);
        self::assertEquals([AwaitCombinator::FirstCompleted->tree([11, 22])], $invocation->awaitedTrees());
    }

    public function testSelectReturnsTheFirstKeyWhenSeveralFuturesAreCompleted(): void
    {
        $invocation = ScriptedInvocation::new();
        // Awaiting the timer processes every result that arrived before it.
        $invocation->complete(33, NotificationResult::void())->complete(11, NotificationResult::void())->complete(99, NotificationResult::void());
        $ctx = Contexts::service($invocation);
        $ctx->select(['timer' => DurableFutureImpl::voidResult($invocation, 99, 'sleep', 'Sleep suspended.')]);

        self::assertSame('first', $ctx->select([
            'first' => DurableFutureImpl::voidResult($invocation, 11, 'sleep', 'Sleep suspended.'),
            'second' => DurableFutureImpl::voidResult($invocation, 22, 'sleep', 'Sleep suspended.'),
            'third' => DurableFutureImpl::voidResult($invocation, 33, 'sleep', 'Sleep suspended.'),
        ]));
    }

    public function testSelectDoesNotConsumeNotificationResults(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(22, NotificationResult::success('{"status":"ok"}'));
        $future = DurableFutureImpl::jsonResult($invocation, 22, 'Service call', 'Service call suspended.');
        $ctx = Contexts::service($invocation);

        self::assertSame('selected', $ctx->select(['selected' => $future]));
        self::assertSame([], $invocation->taken());

        self::assertSame(['status' => 'ok'], $future->awaitResult());
        self::assertSame([22], $invocation->taken());
    }

    public function testSelectReturnsAlreadyCompletedFutureWithoutAwaiting(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(11, NotificationResult::void());
        $completed = DurableFutureImpl::voidResult($invocation, 11, 'sleep', 'Sleep suspended.');
        $ctx = Contexts::service($invocation);

        $completed->awaitResult();

        self::assertSame('done', $ctx->select([
            'done' => $completed,
            'pending' => DurableFutureImpl::voidResult($invocation, 22, 'sleep', 'Sleep suspended.'),
        ]));
        self::assertSame([], $invocation->awaitedTrees());
    }

    public function testDurableFutureAllReturnsValuesInInputOrderAsFuturesComplete(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(20, NotificationResult::success('"fast"'));
        $invocation->complete(10, NotificationResult::success('"slow"'));

        $results = Contexts::service($invocation)->all([
            'slow' => DurableFutureImpl::jsonResult($invocation, 10, 'Service call', 'Service call suspended.'),
            'fast' => DurableFutureImpl::jsonResult($invocation, 20, 'Service call', 'Service call suspended.'),
        ]);

        self::assertSame(['slow' => 'slow', 'fast' => 'fast'], $results);
        self::assertEquals([AwaitCombinator::AllSucceededOrFirstFailed->tree([10, 20])], $invocation->awaitedTrees(), 'one await for the whole set');
        self::assertSame([10, 20], $invocation->taken());
    }

    public function testDurableFutureAllThrowsTheFirstFailureWithoutWaitingForTheRest(): void
    {
        $invocation = ScriptedInvocation::new();
        // The third future never completes in this attempt.
        $invocation->complete(10, NotificationResult::success('"ok"'));
        $invocation->complete(20, NotificationResult::failure(409, 'denied'));

        try {
            Contexts::service($invocation)->all([
                'first' => DurableFutureImpl::jsonResult($invocation, 10, 'Service call', 'Service call suspended.'),
                'second' => DurableFutureImpl::jsonResult($invocation, 20, 'Service call', 'Service call suspended.'),
                'third' => DurableFutureImpl::jsonResult($invocation, 30, 'Service call', 'Service call suspended.'),
            ]);
            self::fail('Expected the failure.');
        } catch (TerminalError $exception) {
            self::assertSame('denied', $exception->getMessage());
            self::assertSame(409, $exception->statusCode());
        }
        self::assertEquals([AwaitCombinator::AllSucceededOrFirstFailed->tree([10, 20, 30])], $invocation->awaitedTrees());
    }

    public function testDurableFutureAllThrowsTheFailureThatCompletedFirstNotTheFirstKey(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(30, NotificationResult::failure(410, 'first to complete'));
        $invocation->complete(10, NotificationResult::failure(409, 'first key'));

        $this->expectException(TerminalError::class);
        $this->expectExceptionMessage('first to complete');

        Contexts::service($invocation)->all([
            'first' => DurableFutureImpl::jsonResult($invocation, 10, 'Service call', 'Service call suspended.'),
            'second' => DurableFutureImpl::jsonResult($invocation, 20, 'Service call', 'Service call suspended.'),
            'third' => DurableFutureImpl::jsonResult($invocation, 30, 'Service call', 'Service call suspended.'),
        ]);
    }

    public function testDurableFutureAllAwaitsOnlyTheFuturesNotResolvedYet(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(10, NotificationResult::success('"early"'));
        $early = DurableFutureImpl::jsonResult($invocation, 10, 'Service call', 'Service call suspended.');
        $early->awaitResult();
        $invocation->complete(20, NotificationResult::success('"late"'));

        self::assertSame(
            ['early' => 'early', 'late' => 'late'],
            Contexts::service($invocation)->all(['early' => $early, 'late' => DurableFutureImpl::jsonResult($invocation, 20, 'Service call', 'Service call suspended.')]),
        );
        self::assertEquals([AwaitCombinator::AllSucceededOrFirstFailed->tree([20])], $invocation->awaitedTrees());
    }

    public function testDurableFutureAllSuspendsUntilEveryFutureSucceeded(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(10, NotificationResult::success('"ok"'));

        try {
            Contexts::service($invocation)->all([
                'first' => DurableFutureImpl::jsonResult($invocation, 10, 'Service call', 'Service call suspended.'),
                'second' => DurableFutureImpl::jsonResult($invocation, 20, 'Service call', 'Service call suspended.'),
            ]);
            self::fail('Expected the suspension.');
        } catch (SuspensionException $exception) {
            self::assertSame('Context::all() suspended.', $exception->getMessage());
        }
        self::assertSame([], $invocation->taken());
    }

    public function testDurableFutureRaceReturnsFirstCompletedValue(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(20, NotificationResult::success('"fast"'));

        $result = Contexts::service($invocation)->race([
            'slow' => DurableFutureImpl::jsonResult($invocation, 10, 'Service call', 'Service call suspended.'),
            'fast' => DurableFutureImpl::jsonResult($invocation, 20, 'Service call', 'Service call suspended.'),
        ]);

        self::assertSame('fast', $result);
        self::assertEquals([AwaitCombinator::FirstCompleted->tree([10, 20])], $invocation->awaitedTrees());
        self::assertSame([20], $invocation->awaited());
        self::assertSame([20], $invocation->taken());
    }

    public function testDurableFutureRaceThrowsAFailureThatCompletedFirst(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(20, NotificationResult::failure(409, 'denied'));
        $invocation->complete(10, NotificationResult::success('"ok"'));

        $this->expectException(TerminalError::class);
        $this->expectExceptionMessage('denied');

        Contexts::service($invocation)->race([
            'first' => DurableFutureImpl::jsonResult($invocation, 10, 'Service call', 'Service call suspended.'),
            'second' => DurableFutureImpl::jsonResult($invocation, 20, 'Service call', 'Service call suspended.'),
        ]);
    }

    public function testDurableFutureAnySkipsFailedFuturesUntilSuccess(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(10, NotificationResult::failure(409, 'not yet', ['attempt' => '1']));
        $invocation->complete(20, NotificationResult::success('"ok"'));

        self::assertSame('ok', Contexts::service($invocation)->any([
            'failed' => DurableFutureImpl::jsonResult($invocation, 10, 'Service call', 'Service call suspended.'),
            'successful' => DurableFutureImpl::jsonResult($invocation, 20, 'Service call', 'Service call suspended.'),
        ]));
        self::assertEquals([AwaitCombinator::FirstCompleted->tree([10, 20]), AwaitCombinator::FirstCompleted->tree([20])], $invocation->awaitedTrees());
        self::assertSame([10, 20], $invocation->taken());
    }

    public function testDurableFutureAnyListsTheFailuresInTheOrderTheFuturesCompleted(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(20, NotificationResult::failure(410, 'second'));
        $invocation->complete(10, NotificationResult::failure(409, 'first'));

        try {
            Contexts::service($invocation)->any([
                'first' => DurableFutureImpl::jsonResult($invocation, 10, 'Service call', 'Service call suspended.'),
                'second' => DurableFutureImpl::jsonResult($invocation, 20, 'Service call', 'Service call suspended.'),
            ]);
            self::fail('Expected aggregate failure.');
        } catch (DurableFutureAggregateException $exception) {
            self::assertSame(['second', 'first'], array_keys($exception->failures()));
        }
    }

    public function testDurableFutureAnyThrowsAggregateWhenAllFuturesFail(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(10, NotificationResult::failure(409, 'first failed', ['first' => 'yes']));
        $invocation->complete(20, NotificationResult::failure(410, 'second failed', ['second' => 'yes']));

        try {
            Contexts::service($invocation)->any([
                'first' => DurableFutureImpl::jsonResult($invocation, 10, 'Service call', 'Service call suspended.'),
                'second' => DurableFutureImpl::jsonResult($invocation, 20, 'Service call', 'Service call suspended.'),
            ]);
            self::fail('Expected aggregate failure.');
        } catch (DurableFutureAggregateException $exception) {
            $failures = $exception->failures();
            self::assertSame(['first', 'second'], array_keys($failures));
            self::assertInstanceOf(TerminalError::class, $failures['first']);
            self::assertSame(409, $failures['first']->statusCode());
            self::assertSame(['first' => 'yes'], $failures['first']->metadata());
            self::assertInstanceOf(TerminalError::class, $failures['second']);
            self::assertSame(410, $failures['second']->statusCode());
            self::assertSame(['second' => 'yes'], $failures['second']->metadata());
        }
    }

    public function testDurableFutureAllSettledCollectsFailuresWithMetadata(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(20, NotificationResult::success('"ok"'));
        $invocation->complete(10, NotificationResult::failure(409, 'denied', ['reason' => 'test']));

        $results = Contexts::service($invocation)->allSettled([
            'failed' => DurableFutureImpl::jsonResult($invocation, 10, 'Service call', 'Service call suspended.'),
            'successful' => DurableFutureImpl::jsonResult($invocation, 20, 'Service call', 'Service call suspended.'),
        ]);

        self::assertSame('rejected', $results['failed']['status']);
        self::assertInstanceOf(TerminalError::class, $results['failed']['reason']);
        self::assertSame(['reason' => 'test'], $results['failed']['reason']->metadata());
        self::assertSame(['status' => 'fulfilled', 'value' => 'ok'], $results['successful']);
        self::assertEquals([AwaitCombinator::AllCompleted->tree([10, 20])], $invocation->awaitedTrees(), 'one await for the whole set');
    }

    public function testDurableFutureAllSettledSuspendsUntilEveryFutureCompleted(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(10, NotificationResult::failure(409, 'denied'));

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('Context::allSettled() suspended.');

        Contexts::service($invocation)->allSettled([
            'failed' => DurableFutureImpl::jsonResult($invocation, 10, 'Service call', 'Service call suspended.'),
            'pending' => DurableFutureImpl::jsonResult($invocation, 20, 'Service call', 'Service call suspended.'),
        ]);
    }

    public function testDurableFutureAllSettledPropagatesCancellation(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->whenPending = AwaitResult::cancelSignalReceived();

        $this->expectException(TerminalError::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('Invocation cancelled');

        Contexts::service($invocation)->allSettled(['timer' => DurableFutureImpl::voidResult($invocation, 11, 'sleep', 'Sleep suspended.')]);
    }

    public function testDurableFutureWaitCompletedReturnsCompletedAndPendingWithoutConsumingResult(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(20, NotificationResult::success('"fast"'));
        $slow = DurableFutureImpl::jsonResult($invocation, 10, 'Service call', 'Service call suspended.');
        $fast = DurableFutureImpl::jsonResult($invocation, 20, 'Service call', 'Service call suspended.');

        $result = Contexts::service($invocation)->waitCompleted(['slow' => $slow, 'fast' => $fast]);

        self::assertSame(['fast' => $fast], $result['completed']);
        self::assertSame(['slow' => $slow], $result['pending']);
        self::assertSame([], $invocation->taken());
        self::assertSame('fast', $fast->awaitResult());
    }

    public function testDurableFutureCompletedAndEmptyCollectionHelpers(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->complete(10, NotificationResult::success('"done"'));
        $done = DurableFutureImpl::jsonResult($invocation, 10, 'Service call', 'Service call suspended.');
        $pending = DurableFutureImpl::jsonResult($invocation, 20, 'Service call', 'Service call suspended.');

        self::assertSame('done', $done->awaitResult());
        self::assertTrue($done->isCompleted());
        self::assertFalse($pending->isCompleted());

        self::assertSame('done', Contexts::service($invocation)->race(['done' => $done, 'pending' => $pending]));
        self::assertSame(['completed' => ['done' => $done], 'pending' => ['pending' => $pending]], Contexts::service($invocation)->waitCompleted(['done' => $done, 'pending' => $pending]));
        self::assertSame([], Contexts::service($invocation)->all([]));
        self::assertSame([], Contexts::service($invocation)->allSettled([]));
        self::assertSame(['completed' => [], 'pending' => []], Contexts::service($invocation)->waitCompleted([]));
    }

    public function testDurableFutureRaceRejectsEmptyFutureList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Context::race() requires at least one durable future.');

        Contexts::service(ScriptedInvocation::new())->race([]);
    }

    public function testDurableFutureAnyRejectsEmptyFutureListWithAggregate(): void
    {
        try {
            Contexts::service(ScriptedInvocation::new())->any([]);
            self::fail('Expected aggregate failure.');
        } catch (DurableFutureAggregateException $exception) {
            self::assertSame([], $exception->failures());
        }
    }

    public function testDurableFutureCombinatorsRejectDuplicateHandles(): void
    {
        $invocation = ScriptedInvocation::new();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Context::all() futures must have unique handles.');

        Contexts::service($invocation)->all([
            'first' => DurableFutureImpl::voidResult($invocation, 11, 'sleep', 'Sleep suspended.'),
            'second' => DurableFutureImpl::jsonResult($invocation, 11, 'Service call', 'Service call suspended.'),
        ]);
    }

    public function testDurableFutureCombinatorsRejectFuturesFromDifferentContexts(): void
    {
        $invocation = ScriptedInvocation::new();
        $otherInvocation = ScriptedInvocation::new();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Context::allSettled() futures must belong to this context.');

        Contexts::service($invocation)->allSettled([
            'first' => DurableFutureImpl::voidResult($invocation, 11, 'sleep', 'Sleep suspended.'),
            'second' => DurableFutureImpl::voidResult($otherInvocation, 22, 'sleep', 'Sleep suspended.'),
        ]);
    }

    public function testDurableFutureRacePropagatesCancellationSignal(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->whenPending = AwaitResult::cancelSignalReceived();

        $this->expectException(TerminalError::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('Invocation cancelled');

        Contexts::service($invocation)->race(['timer' => DurableFutureImpl::voidResult($invocation, 11, 'sleep', 'Sleep suspended.')]);
    }

    public function testSelectRejectsEmptyFutureList(): void
    {
        $ctx = Contexts::service(ScriptedInvocation::new());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Context::select() requires at least one durable future.');

        $ctx->select([]);
    }

    public function testSelectRejectsFuturesTheSdkDidNotCreate(): void
    {
        $ctx = Contexts::service(ScriptedInvocation::new());
        $foreign = new class () implements DurableFuture {
            public function awaitResult(): mixed
            {
                return null;
            }

            public function isCompleted(): bool
            {
                return true;
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Context::select() futures must belong to this context.');

        $ctx->select(['foreign' => $foreign]);
    }

    public function testSelectRejectsDuplicateHandles(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = Contexts::service($invocation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Context::select() futures must have unique handles.');

        $ctx->select([
            'first' => DurableFutureImpl::voidResult($invocation, 11, 'sleep', 'Sleep suspended.'),
            'second' => DurableFutureImpl::jsonResult($invocation, 11, 'Service call', 'Service call suspended.'),
        ]);
    }

    public function testSelectRejectsFuturesFromDifferentContext(): void
    {
        $ctx = Contexts::service(ScriptedInvocation::new());
        $otherInvocation = ScriptedInvocation::new();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Context::select() futures must belong to this context.');

        $ctx->select(['other' => DurableFutureImpl::voidResult($otherInvocation, 11, 'sleep', 'Sleep suspended.')]);
    }

    public function testSelectSuspendedThrowsSuspensionException(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->whenPending = AwaitResult::suspended();
        $ctx = Contexts::service($invocation);

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('Context::select() suspended.');

        $ctx->select(['timer' => DurableFutureImpl::voidResult($invocation, 11, 'sleep', 'Sleep suspended.')]);
    }
}

final class ContextWithCallFutureSpy extends ContextImpl
{
    public int $callFutureCalls = 0;

    /**
     * @param Serde|class-string<Serde>|null $inputSerde
     * @param Serde|class-string<Serde>|null $outputSerde
     * @param array<string, string>|list<array{0: string, 1: string}> $headers
     */
    public function callFuture(Target $target, mixed $payload, ?string $idempotencyKey = null, Serde|string|null $inputSerde = null, Serde|string|null $outputSerde = null, array $headers = [], ?string $limitKey = null): DurableFuture
    {
        $this->callFutureCalls++;
        return parent::callFuture($target, $payload, $idempotencyKey, $inputSerde, $outputSerde, $headers, $limitKey);
    }
}

final class ContextWithSleepFutureSpy extends ContextImpl
{
    public int $sleepFutureCalls = 0;

    public function sleepFuture(int $delayMillis): DurableFuture
    {
        $this->sleepFutureCalls++;
        if ($delayMillis < 0) {
            throw new InvalidArgumentException('Sleep delay must be greater than or equal to 0 milliseconds.');
        }

        return DurableFutureImpl::voidResult($this->operations(), $this->operations()->sysSleep($delayMillis), 'sleep', 'Sleep suspended.');
    }
}
