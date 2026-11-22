<?php

declare(strict_types=1);

namespace Restate\Tests\Support;

use PHPUnit\Framework\TestCase;
use Restate\Internal\AwaitCombinator;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\Backend\RunCompletionStatus;
use Restate\Target;

final class ScriptedInvocationTest extends TestCase
{
    public function testOperationsAllocateHandlesAndAreRecordedInOrder(): void
    {
        $fake = ScriptedInvocation::new();
        $target = new CallTarget(Target::service('Greeter', 'greet'));

        self::assertSame(1, $fake->sysCall($target, '"Mary"'));
        self::assertSame(2, $fake->sysSleep(100));
        $fake->setState('count', '1');

        self::assertSame(['sysCall', 'sysSleep', 'setState'], $fake->operations());
        self::assertSame([[$target, '"Mary"', null]], $fake->calls('sysCall'));
        self::assertSame([2], $fake->handles('sysSleep'));
    }

    public function testAnAwaitProcessesArrivedResultsInOrderUpToTheAwaitedHandle(): void
    {
        $fake = ScriptedInvocation::new();
        $fake->complete(10, NotificationResult::success('"a"'))->complete(20, NotificationResult::void());

        self::assertEquals(AwaitResult::completed(), $fake->doAwait(20));
        self::assertTrue($fake->isCompleted(10), 'arrived before the awaited handle');
        self::assertEquals(NotificationResult::void(), $fake->takeNotificationResult(20));
        self::assertFalse($fake->isCompleted(20), 'taken');
        self::assertEquals(AwaitResult::suspended(), $fake->doAwait(30), 'never arrives');
    }

    public function testATreeProcessesOneResultAtATimeUntilItResolves(): void
    {
        $fake = ScriptedInvocation::new();
        $fake->complete(20, NotificationResult::failure(409, 'denied'))->complete(10, NotificationResult::void());

        self::assertEquals(AwaitResult::completed([20]), $fake->doAwaitTree(AwaitCombinator::FirstCompleted->tree([10, 20])));
        self::assertTrue($fake->isCompleted(20));
        self::assertFalse($fake->isCompleted(10), 'not processed yet');

        $fake->whenPending = AwaitResult::cancelSignalReceived();
        self::assertEquals(AwaitResult::cancelSignalReceived(), $fake->doAwaitTree(AwaitCombinator::AllCompleted->tree([10, 30])));
    }

    public function testATreeReportsItsCompletedHandlesInTreeOrderAndNeverATakenOne(): void
    {
        $fake = ScriptedInvocation::new();
        $fake->complete(30, NotificationResult::void())->complete(10, NotificationResult::void())->complete(20, NotificationResult::void());

        self::assertEquals(AwaitResult::completed([20, 30, 10]), $fake->doAwaitTree(AwaitCombinator::AllCompleted->tree([20, 30, 10])), 'tree order, not arrival order');

        $fake->takeNotificationResult(30);
        self::assertEquals(AwaitResult::completed([10]), $fake->doAwaitTree(AwaitCombinator::FirstCompleted->tree([30, 10])));
        $fake->whenPending = AwaitResult::suspended();
        self::assertEquals(AwaitResult::suspended(), $fake->doAwaitTree(AwaitCombinator::FirstCompleted->tree([30])), 'a taken result resolves nothing');
    }

    public function testScriptedStepsInterruptBeforeTheResult(): void
    {
        $fake = ScriptedInvocation::new()
            ->onNext('sysCall', AwaitState::CancelSignalReceived, NotificationResult::success('1'))
            ->onEvery('sysCall', NotificationResult::invocationId('inv-1'));
        $target = new CallTarget(Target::service('Greeter', 'greet'));
        $first = $fake->sysCall($target, '');
        $second = $fake->sysCall($target, '');

        self::assertEquals(AwaitResult::cancelSignalReceived(), $fake->doAwait($first));
        self::assertEquals(AwaitResult::completed(), $fake->doAwait($first));
        self::assertEquals(NotificationResult::success('1'), $fake->takeNotificationResult($first));
        self::assertEquals(AwaitResult::completed(), $fake->doAwait($second));
        self::assertEquals(NotificationResult::invocationId('inv-1'), $fake->takeNotificationResult($second));
    }

    public function testARunExecutesAndCompletesWithItsProposal(): void
    {
        $fake = ScriptedInvocation::new();
        $run = $fake->sysRun('charge');

        self::assertEquals(AwaitResult::executeRun($run), $fake->doAwait($run));
        $fake->proposeRunCompletionSuccess($run, '"ok"');
        self::assertEquals(AwaitResult::completed(), $fake->doAwait($run));
        self::assertEquals(NotificationResult::success('"ok"'), $fake->takeNotificationResult($run));
    }

    public function testAnExhaustedRetryableRunCompletesWithTheFailure(): void
    {
        $fake = ScriptedInvocation::new();
        $fake->retryableRunStatus = RunCompletionStatus::Completed;
        $run = $fake->sysRun('charge');
        $fake->doAwait($run);

        self::assertSame(RunCompletionStatus::Completed, $fake->proposeRunCompletionRetryable($run, 'declined', 10, code: 570));
        $fake->doAwait($run);
        self::assertEquals(NotificationResult::failure(500, 'declined'), $fake->takeNotificationResult($run));
    }

    public function testStateAndPromisesBehaveLikeTheJournal(): void
    {
        $fake = ScriptedInvocation::new();
        $fake->state = ['a' => '1'];
        $fake->setState('b', '2');
        $fake->clearState('a');
        $keys = $fake->sysGetStateKeys();
        $resolve = $fake->sysResolvePromise('p', '"done"');
        $peek = $fake->sysPeekPromise('p');

        $fake->doAwait($peek);
        self::assertEquals(NotificationResult::stateKeys(['b']), $fake->takeNotificationResult($keys));
        self::assertEquals(NotificationResult::void(), $fake->takeNotificationResult($resolve));
        self::assertEquals(NotificationResult::success('"done"'), $fake->takeNotificationResult($peek));
    }

    public function testMisuseFailsLoudly(): void
    {
        $fake = ScriptedInvocation::new();
        $sleep = $fake->sysSleep(1);

        self::assertThrows(static fn () => $fake->takeNotificationResult($sleep), 'Handle 1 has not completed: await it first.');
        self::assertThrows(static fn () => $fake->proposeRunCompletionSuccess($sleep, ''), 'Handle 1 is not a run');
        self::assertThrows(static fn () => $fake->onNext('setState'), 'setState() does not return a handle.');
        self::assertThrows(static fn () => $fake->calls('sysCal'), 'sysCal() is not an invocation operation.');
        self::assertThrows(static fn () => $fake->onNext('sysCall', NotificationResult::void(), AwaitState::Suspended), 'A result must be the last step.');
        self::assertThrows(static fn () => ScriptedInvocation::unused()->sysSleep(1), 'InvocationOperations::sysSleep() is not expected in this test.');
    }

    private static function assertThrows(\Closure $operation, string $message): void
    {
        try {
            $operation();
        } catch (\LogicException $e) {
            self::assertStringContainsString($message, $e->getMessage());

            return;
        }

        self::fail("Expected: {$message}");
    }
}
