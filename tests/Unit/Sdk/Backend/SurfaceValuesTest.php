<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\Backend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\AttachTarget;
use Restate\Internal\AwaitCombinator;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\AwaitTree;
use Restate\Internal\Backend\AwakeableHandle;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\Extension\SurfaceValues;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\Backend\NotificationKind;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\Backend\ResponseHead;
use Restate\Internal\Backend\RunCompletionStatus;
use Restate\Internal\BackendErrorCategory;
use Restate\Internal\BackendException;
use Restate\RunRetryPolicy;
use Restate\Target;

/**
 * The conversions between the typed Backend Contract and the Extension Surface's PHP values. That the extension
 * really returns these shapes is `tests/Extension/BackendContractTest.php`.
 */
final class SurfaceValuesTest extends TestCase
{
    public function testCallTargetCarriesHeadersOnlyWhenThereAreAny(): void
    {
        self::assertSame(
            ['kind' => 'object', 'service' => 'Counter', 'handler' => 'add', 'key' => 'alice'],
            SurfaceValues::callTarget(new CallTarget(Target::object('Counter', 'alice', 'add'))),
        );
        self::assertSame(
            ['kind' => 'service', 'service' => 'Greeter', 'handler' => 'greet', 'key' => null, 'headers' => [['x-a', '1']]],
            SurfaceValues::callTarget(new CallTarget(Target::service('Greeter', 'greet'), [['x-a', '1']])),
        );
    }

    public function testAttachTargetAndRetryPolicyAreTheirArrays(): void
    {
        self::assertSame(['kind' => 'workflow', 'name' => 'Checkout', 'key' => 'o-1', 'scope' => null], SurfaceValues::attachTarget(AttachTarget::workflow('Checkout', 'o-1')));
        self::assertSame(['type' => 'none'], SurfaceValues::retryPolicy(RunRetryPolicy::none()));
        self::assertNull(SurfaceValues::retryPolicy(null));
    }

    public function testAwaitTreeNestsCombinatorsAndHandles(): void
    {
        $tree = new AwaitTree(AwaitCombinator::FirstCompleted, [1, AwaitCombinator::AllCompleted->tree([2, 3])]);

        self::assertSame(['first_completed', [1, ['all_completed', [2, 3]]]], SurfaceValues::awaitTree($tree));
    }

    public function testAnAwaitTreeNeedsAChild(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AwaitTree(AwaitCombinator::AllCompleted, []);
    }

    /** @return iterable<string, array{array<array-key, mixed>, AwaitResult}> */
    public static function awaitResults(): iterable
    {
        yield 'completed' => [['type' => 'any_completed'], AwaitResult::completed()];
        yield 'suspended' => [['type' => 'suspended'], AwaitResult::suspended()];
        yield 'read from input' => [['type' => 'read_from_input'], AwaitResult::of(AwaitState::ReadFromInput)];
        yield 'waiting pending run' => [['type' => 'waiting_pending_run'], AwaitResult::of(AwaitState::WaitingPendingRun)];
        yield 'cancel signal' => [['type' => 'cancel_signal_received'], AwaitResult::cancelSignalReceived()];
        yield 'execute run' => [['type' => 'execute_run', 'handle' => 7], AwaitResult::executeRun(7)];
    }

    /** @param array<array-key, mixed> $array */
    #[DataProvider('awaitResults')]
    public function testAwaitResults(array $array, AwaitResult $expected): void
    {
        self::assertEquals($expected, SurfaceValues::awaitResult($array));
    }

    public function testAnExecuteRunAwaitResultNeedsItsHandle(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AwaitResult::of(AwaitState::ExecuteRun);
    }

    /** @return iterable<string, array{array<array-key, mixed>, NotificationResult}> */
    public static function notificationResults(): iterable
    {
        yield 'success' => [['type' => 'success', 'value' => "\xff"], NotificationResult::success("\xff")];
        yield 'void' => [['type' => 'void'], NotificationResult::void()];
        yield 'failure' => [['type' => 'failure', 'code' => 409, 'message' => 'no', 'metadata' => [404 => 'x']], NotificationResult::failure(409, 'no', ['404' => 'x'])];
        yield 'invocation id' => [['type' => 'invocation_id', 'invocation_id' => 'inv-2'], NotificationResult::invocationId('inv-2')];
        yield 'state keys' => [['type' => 'state_keys', 'keys' => ['a', 'b']], NotificationResult::stateKeys(['a', 'b'])];
    }

    /** @param array<array-key, mixed> $array */
    #[DataProvider('notificationResults')]
    public function testNotificationResults(array $array, NotificationResult $expected): void
    {
        self::assertEquals($expected, SurfaceValues::notificationResult($array));
    }

    public function testNotificationResultValuesBelongToTheirKind(): void
    {
        $failure = NotificationResult::failure(404, 'gone', ['404' => 'x'])->terminalError();
        self::assertSame([404, 'gone', [404 => 'x']], [$failure->statusCode(), $failure->getMessage(), $failure->metadata()]);
        self::assertSame('bytes', NotificationResult::success('bytes')->bytes());
        self::assertSame(NotificationKind::Void, NotificationResult::void()->kind);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A void notification result has no success value.');
        NotificationResult::void()->bytes();
    }

    public function testFixedShapes(): void
    {
        self::assertEquals(RunCompletionStatus::AttemptEnded, SurfaceValues::runCompletionStatus(['type' => 'attempt_ended']));
        self::assertEquals(new AwakeableHandle('sign_1', 3), SurfaceValues::awakeable(['id' => 'sign_1', 'handle' => 3]));
        self::assertEquals(new ResponseHead(200, [['content-type', 'x']]), SurfaceValues::responseHead(['status_code' => 200, 'headers' => [['content-type', 'x']]]));
        self::assertEquals(
            new InvocationInput('inv-1', -1, 'alice', [['a', 'b']], '"Mary"', 'tenant', null, 'once'),
            SurfaceValues::invocationInput([
                'invocation_id' => 'inv-1',
                'random_seed' => -1,
                'key' => 'alice',
                'headers' => [['a', 'b']],
                'input' => '"Mary"',
                'scope' => 'tenant',
                'limit_key' => null,
                'idempotency_key' => 'once',
            ]),
        );
    }

    /** @return iterable<string, array{\Closure(): mixed, string}> */
    public static function unexpectedShapes(): iterable
    {
        yield 'unknown await state' => [static fn () => SurfaceValues::awaitResult(['type' => 'remote']), "unexpected await result of type 'remote'"];
        yield 'execute run without handle' => [static fn () => SurfaceValues::awaitResult(['type' => 'execute_run']), "unexpected await result of type 'execute_run'"];
        yield 'untyped result' => [static fn () => SurfaceValues::awaitResult([]), 'unexpected result'];
        yield 'unknown notification' => [static fn () => SurfaceValues::notificationResult(['type' => 'pending']), "unexpected notification result of type 'pending'"];
        yield 'success without bytes' => [static fn () => SurfaceValues::notificationResult(['type' => 'success']), "unexpected notification result of type 'success'"];
        yield 'failure with a string code' => [static fn () => SurfaceValues::notificationResult(['type' => 'failure', 'code' => '409', 'message' => 'x', 'metadata' => []]), "unexpected notification result of type 'failure'"];
        yield 'failure metadata not strings' => [static fn () => SurfaceValues::notificationResult(['type' => 'failure', 'code' => 409, 'message' => 'x', 'metadata' => ['a' => 1]]), 'Terminal failure returned invalid metadata.'];
        yield 'state keys not strings' => [static fn () => SurfaceValues::notificationResult(['type' => 'state_keys', 'keys' => [1]]), "unexpected notification result of type 'state_keys'"];
        yield 'unknown run completion' => [static fn () => SurfaceValues::runCompletionStatus(['type' => 'paused']), "unexpected run completion status of type 'paused'"];
        yield 'headers not pairs' => [static fn () => SurfaceValues::responseHead(['status_code' => 200, 'headers' => [['a']]]), 'unexpected response head'];
        yield 'input with an int scope' => [static fn () => SurfaceValues::invocationInput(['invocation_id' => 'i', 'random_seed' => 1, 'key' => '', 'headers' => [], 'input' => '', 'scope' => 1]), 'unexpected invocation input'];
    }

    /** @param \Closure(): mixed $convert */
    #[DataProvider('unexpectedShapes')]
    public function testAShapeTheContractDoesNotKnowIsAnInternalBackendException(\Closure $convert, string $message): void
    {
        try {
            $convert();
        } catch (BackendException $e) {
            self::assertSame(BackendErrorCategory::Internal, $e->category());
            self::assertStringContainsString($message, $e->getMessage());

            return;
        }

        self::fail('Expected a BackendException.');
    }
}
