<?php

declare(strict_types=1);

namespace Restate\Tests\Extension;

use PHPUnit\Framework\Attributes\DataProvider;
use Restate\Internal\AwaitCombinator;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\Extension\Backend;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\Backend\RunCompletionStatus;
use Restate\Internal\BackendErrorCategory;
use Restate\Internal\BackendException;
use Restate\RunRetryPolicy;
use Restate\Target;
use Restate\Tests\Support\Protocol\FrameCodec;

/**
 * The extension backend against a real VM: protocol frames in, frames and results out. Where the output is
 * deterministic it is compared byte for byte with what {@see FrameCodec} encodes, so the test-side encoder and
 * shared-core agree too.
 */
final class ExtensionInvocationTest extends ExtensionTestCase
{
    public function testInputThenOutputSuccess(): void
    {
        $invocation = self::newInvocation();
        self::assertSame(200, $invocation->responseHead()->statusCode);
        self::assertContains(['content-type', self::V5], $invocation->responseHead()->headers);

        $invocation->notifyInput(FrameCodec::startFrame(self::INVOCATION_ID, 1, 'alice'));
        $invocation->notifyInput(FrameCodec::inputCommandFrame('"Mary"', [['x-trace', 'abc']]));
        $invocation->notifyInputClosed();
        self::assertTrue($invocation->isReadyToExecute());

        $input = $invocation->input();
        self::assertSame(self::INVOCATION_ID, $input->invocationId);
        self::assertSame('alice', $input->key);
        self::assertSame([['x-trace', 'abc']], $input->headers);
        self::assertSame('"Mary"', $input->input);
        self::assertSame([null, null, null], [$input->scope, $input->limitKey, $input->idempotencyKey]);
        self::assertFalse($invocation->isReplaying());

        $invocation->writeOutputSuccess('"Hello Mary"');
        $invocation->end();

        self::assertSame(bin2hex(FrameCodec::responseBody([FrameCodec::outputSuccessFrame('"Hello Mary"')])), bin2hex(self::written($invocation)));
        self::assertNull($invocation->takeOutput(), 'the response is complete');
    }

    public function testInputCarriesTheScopeLimitKeyAndIdempotencyKeyFromV7(): void
    {
        $input = static function (string $contentType): array {
            $invocation = self::newInvocation($contentType);
            $invocation->notifyInput(FrameCodec::startFrame(self::INVOCATION_ID, 1, scope: 'tenant-1', limitKey: 'user-7', idempotencyKey: 'once'));
            $invocation->notifyInput(FrameCodec::inputCommandFrame('"Mary"'));
            $invocation->notifyInputClosed();
            self::assertTrue($invocation->isReadyToExecute());

            $input = $invocation->input();

            return [$input->scope, $input->limitKey, $input->idempotencyKey];
        };

        self::assertSame(['tenant-1', 'user-7', 'once'], $input(self::V7));
        // v7 fields: before v7 shared-core ignores them.
        self::assertSame([null, null, null], $input(self::V6));
        self::assertSame([null, null, null], $input(self::V5));
    }

    public function testProtocolVersionsAreTheRangeTheVmNegotiates(): void
    {
        $versions = \Restate\Extension\protocol_versions();

        self::assertSame(['min' => 5, 'max' => 8], $versions);
        // What discovery advertises.
        self::assertSame($versions, new Backend()->protocolVersions());
        foreach (range($versions['min'], $versions['max']) as $version) {
            self::assertContains(
                ['content-type', "application/vnd.restate.invocation.v{$version}"],
                self::newInvocation("application/vnd.restate.invocation.v{$version}")->responseHead()->headers,
            );
        }
        foreach ([$versions['min'] - 1, $versions['max'] + 1] as $version) {
            $exception = self::backendException(static fn () => self::newInvocation("application/vnd.restate.invocation.v{$version}"));
            self::assertSame(415, $exception->getCode(), "v{$version}");
        }
    }

    public function testTerminalFailureOutputCarriesCodeMessageAndMetadata(): void
    {
        $invocation = self::running(contentType: self::V6);

        $invocation->writeOutputFailure(404, 'not found', ['404' => 'missing']);
        $invocation->end();

        self::assertSame(
            bin2hex(FrameCodec::responseBody([FrameCodec::terminalFailureFrame(404, 'not found', ['404' => 'missing'])])),
            bin2hex(self::written($invocation)),
        );
    }

    public function testRetryableErrorEndsTheAttemptWithAnErrorMessage(): void
    {
        $invocation = self::running();

        $invocation->notifyError('boom', '#0 handler()');

        self::assertSame(bin2hex(FrameCodec::errorFrame(500, 'boom', '#0 handler()')), bin2hex(self::written($invocation)));
    }

    public function testRetryableErrorMayAskForTheDelayBeforeTheNextAttempt(): void
    {
        $invocation = self::running();

        $invocation->notifyError('rate limited', '#0 handler()', 2_000);

        self::assertSame(
            bin2hex(FrameCodec::errorFrame(500, 'rate limited', '#0 handler()', nextRetryDelayMillis: 2_000)),
            bin2hex(self::written($invocation)),
        );
    }

    public function testRunIsExecutedProposedAndAcknowledged(): void
    {
        $invocation = self::running(open: true);

        $handle = $invocation->sysRun('charge');
        self::assertEquals(AwaitResult::executeRun($handle), $invocation->doAwait($handle));
        $invocation->proposeRunCompletionSuccess($handle, '42');

        self::assertSame(
            bin2hex(FrameCodec::runCommandFrame(1, 'charge') . FrameCodec::runCompletionSuccessFrame(1, '42')),
            bin2hex(self::written($invocation)),
        );
        self::assertEquals(
            NotificationResult::success('42'),
            self::completedAfter($invocation, $handle, FrameCodec::completionFrame(FrameCodec::RUN_COMPLETION, 1, value: '42')),
        );
    }

    public function testProposedRunSuspendsWhenTheRequestBodyIsClosed(): void
    {
        $invocation = self::running();

        $handle = $invocation->sysRun('charge');
        $invocation->doAwait($handle);
        $invocation->proposeRunCompletionFailure($handle, 402, 'card declined');

        self::assertEquals(AwaitResult::suspended(), $invocation->doAwait($handle));
        self::assertSame(
            [FrameCodec::RUN_COMMAND, FrameCodec::PROPOSE_RUN_COMPLETION, FrameCodec::SUSPENSION],
            FrameCodec::messageTypes(self::written($invocation)),
        );
    }

    public function testReplayedRunCompletesWithItsStoredResult(): void
    {
        $invocation = self::running([
            FrameCodec::runCommandFrame(1, 'charge'),
            FrameCodec::completionFrame(FrameCodec::RUN_COMPLETION, 1, failure: ['code' => 402, 'message' => 'card declined', 'metadata' => ['reason' => 'funds']]),
        ]);
        self::assertTrue($invocation->isReplaying());

        $handle = $invocation->sysRun('charge');

        self::assertEquals(
            NotificationResult::failure(402, 'card declined', ['reason' => 'funds']),
            self::completed($invocation, $handle),
        );
        self::assertSame('', self::written($invocation));
    }

    public function testRetryableRunFailureSchedulesARetryUntilThePolicyIsExhausted(): void
    {
        $retry = self::running();
        $handle = $retry->sysRun('charge');
        $retry->doAwait($handle);
        self::assertSame(RunCompletionStatus::AttemptEnded, $retry->proposeRunCompletionRetryable($handle, 'timeout', 5));

        $exhausted = self::running();
        $handle = $exhausted->sysRun('charge');
        $exhausted->doAwait($handle);
        self::assertSame(RunCompletionStatus::Completed, $exhausted->proposeRunCompletionRetryable($handle, 'timeout', 5, RunRetryPolicy::none()));
    }

    public function testExhaustedRetryableRunFailureBecomesATerminalFailureWithItsCodeAndMetadata(): void
    {
        $invocation = self::running(contentType: self::V6);
        $handle = $invocation->sysRun('charge');
        $invocation->doAwait($handle);

        self::assertSame(
            RunCompletionStatus::Completed,
            $invocation->proposeRunCompletionRetryable($handle, 'unavailable', 5, RunRetryPolicy::none(), code: 503, metadata: ['retry-reason' => 'maintenance', '503' => 'numeric key']),
        );
        self::assertSame(
            bin2hex(FrameCodec::runCommandFrame(1, 'charge') . FrameCodec::runCompletionFailureFrame(1, 503, 'unavailable', ['retry-reason' => 'maintenance', '503' => 'numeric key'])),
            bin2hex(self::written($invocation)),
        );
    }

    public function testRetryableRunFailureWithoutAFailureCodeIsSubmittedAs500(): void
    {
        $invocation = self::running();
        $handle = $invocation->sysRun('charge');
        $invocation->doAwait($handle);

        self::assertSame(RunCompletionStatus::Completed, $invocation->proposeRunCompletionRetryable($handle, 'boom', 5, RunRetryPolicy::none(), code: 0));
        self::assertSame(
            bin2hex(FrameCodec::runCommandFrame(1, 'charge') . FrameCodec::runCompletionFailureFrame(1, 500, 'boom')),
            bin2hex(self::written($invocation)),
        );
    }

    public function testRetryableRunFailureAsksForItsRetryAfterInsteadOfThePolicyInterval(): void
    {
        $invocation = self::running();
        $handle = $invocation->sysRun('charge');
        $invocation->doAwait($handle);

        self::assertSame(
            RunCompletionStatus::AttemptEnded,
            $invocation->proposeRunCompletionRetryable($handle, 'rate limited', 5, RunRetryPolicy::fixedDelay(100, 3), 2_000),
        );
        self::assertSame(
            bin2hex(FrameCodec::runCommandFrame(1, 'charge') . FrameCodec::errorFrame(
                500,
                'rate limited',
                relatedCommandIndex: 1,
                relatedCommandName: 'charge',
                relatedCommandType: FrameCodec::RUN_COMMAND,
                nextRetryDelayMillis: 2_000,
            )),
            bin2hex(self::written($invocation)),
        );
    }

    public function testSleepSuspendsUntilItsTimerFires(): void
    {
        $invocation = self::running();

        $handle = $invocation->sysSleep(60_000);

        self::assertEquals(AwaitResult::suspended(), $invocation->doAwait($handle));
        self::assertSame([FrameCodec::SLEEP_COMMAND, FrameCodec::SUSPENSION], FrameCodec::messageTypes(self::written($invocation)));
    }

    public function testCallCompletesWithTheCalleeResult(): void
    {
        $invocation = self::running(open: true);

        $handle = $invocation->sysCall(self::greeter(), '"Bob"');

        self::assertSame(bin2hex(FrameCodec::callCommandFrame('Greeter', 'greet', '"Bob"', null, null, 1, 2)), bin2hex(self::written($invocation)));
        $invocation->notifyInput(FrameCodec::completionFrame(FrameCodec::CALL_INVOCATION_ID_COMPLETION, 1, invocationId: 'inv-2'));
        self::assertEquals(
            NotificationResult::success('"Hello Bob"'),
            self::completedAfter($invocation, $handle, FrameCodec::completionFrame(FrameCodec::CALL_COMPLETION, 2, value: '"Hello Bob"')),
        );
    }

    public function testSendCompletesWithTheNewInvocationId(): void
    {
        $invocation = self::running(open: true);

        $handle = $invocation->sysSend(self::greeter(), '"Bob"', null);

        self::assertSame([FrameCodec::ONE_WAY_CALL_COMMAND], FrameCodec::messageTypes(self::written($invocation)));
        self::assertEquals(
            NotificationResult::invocationId('inv-2'),
            self::completedAfter($invocation, $handle, FrameCodec::completionFrame(FrameCodec::CALL_INVOCATION_ID_COMPLETION, 1, invocationId: 'inv-2')),
        );
    }

    public function testEagerStateIsReadWrittenAndClearedWithoutWaiting(): void
    {
        $invocation = self::running(key: 'alice', state: ['count' => '5', 'name' => '"Alice"']);

        self::assertEquals(NotificationResult::success('5'), self::completed($invocation, $invocation->sysGetState('count')));
        self::assertEquals(NotificationResult::void(), self::completed($invocation, $invocation->sysGetState('missing')));

        $invocation->setState('count', '6');
        self::assertEquals(NotificationResult::success('6'), self::completed($invocation, $invocation->sysGetState('count')));

        $invocation->clearState('name');
        self::assertEquals(NotificationResult::void(), self::completed($invocation, $invocation->sysGetState('name')));
        // shared-core (0.10.0 to 8.7.2) lists a cleared eager key; the core drops it.
        self::assertEquals(NotificationResult::stateKeys(['count']), self::completed($invocation, $invocation->sysGetStateKeys()));

        $invocation->clearAllState();
        self::assertEquals(NotificationResult::stateKeys([]), self::completed($invocation, $invocation->sysGetStateKeys()));

        self::assertSame(
            bin2hex(implode('', [
                FrameCodec::getEagerStateFrame('count', '5'),
                FrameCodec::getEagerStateFrame('missing', null),
                FrameCodec::setStateCommandFrame('count', '6'),
                FrameCodec::getEagerStateFrame('count', '6'),
                FrameCodec::clearStateCommandFrame('name'),
                FrameCodec::getEagerStateFrame('name', null),
                FrameCodec::getEagerStateKeysFrame(['count', 'name']),
                FrameCodec::clearAllStateCommandFrame(),
                FrameCodec::getEagerStateKeysFrame([]),
            ])),
            bin2hex(self::written($invocation)),
        );
    }

    public function testLazyStateWaitsForRestateAndSuspendsWithoutIt(): void
    {
        $open = self::running(open: true, key: 'alice', lazyState: true);
        $keys = $open->sysGetStateKeys();
        self::assertSame(bin2hex(FrameCodec::getLazyStateKeysFrame(1)), bin2hex(self::written($open)));
        self::assertEquals(
            NotificationResult::stateKeys(['a', 'b']),
            self::completedAfter($open, $keys, FrameCodec::completionFrame(FrameCodec::GET_LAZY_STATE_KEYS_COMPLETION, 1, stateKeys: ['a', 'b'])),
        );

        $closed = self::running(key: 'alice', lazyState: true);
        $value = $closed->sysGetState('count');
        self::assertEquals(AwaitResult::suspended(), $closed->doAwait($value));
        self::assertSame([FrameCodec::GET_LAZY_STATE_COMMAND, FrameCodec::SUSPENSION], FrameCodec::messageTypes(self::written($closed)));
    }

    public function testPromisesAreReadPeekedAndCompleted(): void
    {
        $invocation = self::running(open: true, key: 'order-1');

        self::assertEquals(
            NotificationResult::success('"ok"'),
            self::completedAfter($invocation, $invocation->sysGetPromise('approval'), FrameCodec::completionFrame(FrameCodec::GET_PROMISE_COMPLETION, 1, value: '"ok"')),
        );
        self::assertEquals(
            NotificationResult::void(),
            self::completedAfter($invocation, $invocation->sysPeekPromise('payment'), FrameCodec::completionFrame(FrameCodec::PEEK_PROMISE_COMPLETION, 2)),
        );
        self::assertEquals(
            NotificationResult::void(),
            self::completedAfter($invocation, $invocation->sysResolvePromise('payment', '"paid"'), FrameCodec::completionFrame(FrameCodec::COMPLETE_PROMISE_COMPLETION, 3)),
        );
        self::assertEquals(
            NotificationResult::failure(409, 'already completed'),
            self::completedAfter(
                $invocation,
                $invocation->sysRejectPromise('payment', 400, 'declined'),
                FrameCodec::completionFrame(FrameCodec::COMPLETE_PROMISE_COMPLETION, 4, failure: ['code' => 409, 'message' => 'already completed']),
            ),
        );

        self::assertSame(
            bin2hex(implode('', [
                FrameCodec::getPromiseCommandFrame('approval', 1),
                FrameCodec::peekPromiseCommandFrame('payment', 2),
                FrameCodec::completePromiseSuccessCommandFrame('payment', '"paid"', 3),
                FrameCodec::completePromiseFailureCommandFrame('payment', 400, 'declined', 4),
            ])),
            bin2hex(self::written($invocation)),
        );
    }

    public function testAwakeableCompletesWithTheValueItIsResolvedWith(): void
    {
        $invocation = self::running(open: true);

        $awakeable = $invocation->sysAwakeable();

        self::assertStringStartsWith('sign_1', $awakeable->id);
        self::assertEquals(
            NotificationResult::success('"paid"'),
            self::completedAfter($invocation, $awakeable->handle, FrameCodec::signalFrame(17, value: '"paid"')),
        );
    }

    public function testCompletingAnAwakeableIsJournaled(): void
    {
        $invocation = self::running();

        $invocation->sysResolveAwakeable('sign_1abc', '"ok"');
        $invocation->sysRejectAwakeable('sign_1def', 400, 'no');

        self::assertSame(
            bin2hex(FrameCodec::completeAwakeableSuccessFrame('sign_1abc', '"ok"') . FrameCodec::completeAwakeableFailureFrame('sign_1def', 400, 'no')),
            bin2hex(self::written($invocation)),
        );
    }

    public function testSignalCompletesWithTheValueOrFailureItIsCompletedWith(): void
    {
        foreach ([self::V5, self::V6, self::V7] as $contentType) {
            $invocation = self::running(open: true, contentType: $contentType);

            $approved = $invocation->sysSignal('approved');
            $declined = $invocation->sysSignal('declined');

            self::assertSame('', self::written($invocation), 'waiting for a signal journals nothing');
            self::assertEquals(
                NotificationResult::success('true'),
                self::completedAfter($invocation, $approved, FrameCodec::signalFrame('approved', value: 'true')),
                $contentType,
            );
            self::assertEquals(
                NotificationResult::failure(409, 'no'),
                self::completedAfter($invocation, $declined, FrameCodec::signalFrame('declined', failure: ['code' => 409, 'message' => 'no'])),
                $contentType,
            );
        }
    }

    public function testEveryDeliveryOfASignalIsTakenOnceInOrder(): void
    {
        $invocation = self::running([
            FrameCodec::signalFrame('approved', value: '1'),
            FrameCodec::signalFrame('approved', value: '2'),
        ]);

        self::assertEquals(NotificationResult::success('1'), self::completed($invocation, $invocation->sysSignal('approved')));
        self::assertEquals(NotificationResult::success('2'), self::completed($invocation, $invocation->sysSignal('approved')));
    }

    public function testAnAwaitedSignalSuspendsWaitingForItsName(): void
    {
        $invocation = self::running();

        self::assertEquals(AwaitResult::suspended(), $invocation->doAwait($invocation->sysSignal('approved')));

        $written = self::written($invocation);
        self::assertSame([FrameCodec::SUSPENSION], FrameCodec::messageTypes($written));
        self::assertStringContainsString('approved', $written);
    }

    public function testResolvingOrRejectingASignalIsJournaled(): void
    {
        // Failure metadata needs v6.
        $invocation = self::running(contentType: self::V6);

        $invocation->sysResolveSignal('inv-2', 'approved', 'true');
        $invocation->sysRejectSignal('inv-3', 'approved', 409, 'denied', ['reason' => 'policy']);

        self::assertSame(
            bin2hex(
                FrameCodec::sendSignalSuccessCommandFrame('inv-2', 'approved', 'true')
                . FrameCodec::sendSignalFailureCommandFrame('inv-3', 'approved', 409, 'denied', ['reason' => 'policy']),
            ),
            bin2hex(self::written($invocation)),
        );
    }

    public function testAnEmptySignalNameIsAnInvalidArgument(): void
    {
        $invocation = self::running();

        foreach ([
            static fn () => $invocation->sysSignal(''),
            static fn () => $invocation->sysResolveSignal('inv-2', '', 'true'),
            static fn () => $invocation->sysRejectSignal('inv-2', '', 500, 'no'),
        ] as $call) {
            $exception = self::backendException($call);
            self::assertSame(BackendErrorCategory::InvalidArgument, $exception->category());
            self::assertSame('Signal name must not be empty.', $exception->getMessage());
        }
    }

    public function testCancelSignalInterruptsAnAwait(): void
    {
        $invocation = self::running(open: true);
        $sleep = $invocation->sysSleep(60_000);
        $promise = $invocation->sysGetPromise('approval');
        self::assertEquals(AwaitResult::of(AwaitState::ReadFromInput), $invocation->doAwaitTree(AwaitCombinator::FirstCompleted->tree([$sleep, $promise])));

        $invocation->notifyInput(FrameCodec::cancelSignalFrame());

        self::assertEquals(AwaitResult::cancelSignalReceived(), $invocation->doAwait($sleep));
    }

    public function testAwaitingATreeResolvesOnTheResultThatDecidesIt(): void
    {
        foreach ([self::V5, self::V6, self::V7] as $contentType) {
            $invocation = self::running(open: true, contentType: $contentType);
            // Call n has its invocation id notification at 2n - 1 and its result at 2n.
            $calls = [$invocation->sysCall(self::greeter(), '""'), $invocation->sysCall(self::greeter(), '""'), $invocation->sysCall(self::greeter(), '""')];
            $tree = AwaitCombinator::AllSucceededOrFirstFailed->tree($calls);
            $completed = static fn (): array => array_map($invocation->isCompleted(...), $calls);

            self::assertEquals(AwaitResult::of(AwaitState::ReadFromInput), $invocation->doAwaitTree($tree), $contentType);
            $invocation->notifyInput(FrameCodec::completionFrame(FrameCodec::CALL_COMPLETION, 2, value: '"ok"'));
            self::assertEquals(AwaitResult::of(AwaitState::ReadFromInput), $invocation->doAwaitTree($tree), $contentType);
            self::assertSame([true, false, false], $completed(), $contentType);

            $invocation->notifyInput(FrameCodec::completionFrame(FrameCodec::CALL_COMPLETION, 6, failure: ['code' => 409, 'message' => 'denied']));
            // The await reports the completed handles itself: those isCompleted() reports, in the tree's order.
            self::assertEquals(AwaitResult::completed([$calls[0], $calls[2]]), $invocation->doAwaitTree($tree), $contentType);
            self::assertSame([true, false, true], $completed(), $contentType);

            self::assertEquals(NotificationResult::failure(409, 'denied'), $invocation->takeNotificationResult($calls[2]));
            self::assertFalse($invocation->isCompleted($calls[2]), 'a taken handle is no longer completed');
        }
    }

    public function testEachCombinatorResolvesAsDefined(): void
    {
        // Results delivered one by one (call index => success), and after which of them the tree resolves.
        $cases = [
            'first_completed' => [[[1, false]], [true]],
            'all_completed' => [[[1, false], [0, true], [2, false]], [false, false, true]],
            'first_succeeded_or_all_failed' => [[[2, false], [0, false], [1, true]], [false, false, true]],
            'first_succeeded_or_all_failed, every child failing' => [[[2, false], [0, false], [1, false]], [false, false, true]],
            'all_succeeded_or_first_failed' => [[[1, true], [2, true], [0, true]], [false, false, true]],
        ];
        foreach ($cases as $name => [$steps, $expected]) {
            $combinator = explode(',', $name)[0];
            $invocation = self::running(open: true);
            $calls = [$invocation->sysCall(self::greeter(), '""'), $invocation->sysCall(self::greeter(), '""'), $invocation->sysCall(self::greeter(), '""')];
            $resolved = [];
            foreach ($steps as [$call, $succeeds]) {
                $invocation->notifyInput($succeeds
                    ? FrameCodec::completionFrame(FrameCodec::CALL_COMPLETION, 2 * ($call + 1), value: '"ok"')
                    : FrameCodec::completionFrame(FrameCodec::CALL_COMPLETION, 2 * ($call + 1), failure: ['code' => 409, 'message' => 'denied']));
                $resolved[] = $invocation->doAwaitTree(AwaitCombinator::from($combinator)->tree($calls))->isCompleted();
            }

            self::assertSame($expected, $resolved, $name);
        }
    }

    public function testATreeSuspendsWaitingForEveryHandleInIt(): void
    {
        $invocation = self::running();
        $tree = AwaitCombinator::AllCompleted->tree([$invocation->sysSleep(60_000), $invocation->sysSleep(1_000)]);

        self::assertEquals(AwaitResult::suspended(), $invocation->doAwaitTree($tree));
        $written = self::written($invocation);
        self::assertSame([FrameCodec::SLEEP_COMMAND, FrameCodec::SLEEP_COMMAND, FrameCodec::SUSPENSION], FrameCodec::messageTypes($written));
        // A v5 suspension waiting for completions 1 and 2 and the cancel signal (1), with the repeated fields packed as
        // shared-core writes them (FrameCodec::suspensionFrame() writes them unpacked, which decodes the same).
        self::assertStringEndsWith('0001' . '0000' . '00000007' . '0a020102' . '120101', bin2hex($written));
    }

    /** @return array<string, array{0: array<array-key, mixed>, 1: string}> */
    public static function malformedTrees(): array
    {
        return [
            'no children' => [['all_completed', []], "The await tree combinator 'all_completed' needs at least one child."],
            'unknown combinator' => [['all', [1]], "Unknown await tree combinator 'all'."],
            'missing children' => [['all_completed'], 'An await tree must be [combinator, [child, …]].'],
            'extra entry' => [['all_completed', [1], 'x'], 'An await tree must be [combinator, [child, …]].'],
            'children not an array' => [['all_completed', 1], 'Await tree children must be an array.'],
            'child neither handle nor tree' => [['all_completed', ['1']], 'An await tree child must be a handle or an await tree.'],
            'negative handle' => [['all_completed', [-1]], 'Handle is outside the u32 range.'],
            'combinator not a string' => [[1, [1]], 'Await tree combinator must be a string.'],
            'nested too deep' => [self::nested(33), 'An await tree may nest at most 32 combinators.'],
        ];
    }

    /**
     * The extension validates the trees it is given; the typed contract cannot even build most of these, so they go to
     * the extension Vm directly.
     *
     * @param array<array-key, mixed> $tree
     */
    #[DataProvider('malformedTrees')]
    public function testAMalformedTreeIsAnInvalidArgument(array $tree, string $message): void
    {
        $vm = self::runningVm();

        // @phpstan-ignore argument.type (the malformed tree is the point: the extension must reject it)
        $exception = self::backendException(static fn () => self::vmCall(static fn () => $vm->doAwaitTree($tree)));

        self::assertSame(BackendErrorCategory::InvalidArgument, $exception->category());
        self::assertSame($message, $exception->getMessage());
    }

    public function testATreeContainingItselfIsAnInvalidArgument(): void
    {
        $vm = self::runningVm();
        $tree = ['first_completed', [1]];
        $tree[1][] = &$tree;

        $exception = self::backendException(static fn () => self::vmCall(static fn () => $vm->doAwaitTree($tree)));

        self::assertSame(BackendErrorCategory::InvalidArgument, $exception->category());
    }

    public function testJournalMismatchIsABackendExceptionWithItsCategory(): void
    {
        $invocation = self::running([
            FrameCodec::sleepCommandFrame(1, 1),
            FrameCodec::completionFrame(FrameCodec::SLEEP_COMPLETION, 1),
        ]);

        $exception = self::backendException(static fn () => $invocation->sysGetState('count'));

        self::assertSame(BackendErrorCategory::JournalMismatch, $exception->category());
        self::assertSame(570, $exception->getCode());
        self::assertSame([FrameCodec::ERROR], FrameCodec::messageTypes(self::written($invocation)));
    }

    public function testMalformedInputIsRejected(): void
    {
        $invocation = self::newInvocation();
        $invocation->notifyInput("\x00\x00\x00\x00\x00\x00\x00\x03\xff\xff\xff");
        $invocation->notifyInputClosed();

        $exception = self::backendException(static fn () => $invocation->isReadyToExecute());

        self::assertSame(BackendErrorCategory::Internal, $exception->category());
        self::assertSame(500, $exception->getCode());
    }

    public function testUnsupportedProtocolVersionIsRejected(): void
    {
        $exception = self::backendException(static fn () => self::newInvocation('application/vnd.restate.invocation.v4'));

        self::assertSame(BackendErrorCategory::Protocol, $exception->category());
        self::assertSame(415, $exception->getCode());
    }

    /** @return array<array-key, mixed> $levels combinators, each the only child of the one above */
    private static function nested(int $levels): array
    {
        $tree = ['first_completed', [1]];
        for ($level = 1; $level < $levels; $level++) {
            $tree = ['first_completed', [$tree]];
        }

        return $tree;
    }

    private static function greeter(): CallTarget
    {
        return new CallTarget(Target::service('Greeter', 'greet'));
    }

    private static function backendException(\Closure $call): BackendException
    {
        try {
            $call();
        } catch (BackendException $e) {
            return $e;
        }

        self::fail('Expected a BackendException.');
    }
}
