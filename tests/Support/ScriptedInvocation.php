<?php

declare(strict_types=1);

namespace Restate\Tests\Support;

use Restate\AttachTarget;
use Restate\Internal\AwaitCombinator;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\AwaitTree;
use Restate\Internal\Backend\AwakeableHandle;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\Invocation;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\Backend\NotificationKind;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\Backend\ResponseHead;
use Restate\Internal\Backend\RunCompletionStatus;
use Restate\Internal\ReplayStateProvider;
use Restate\RunRetryPolicy;

/**
 * The unit tests' stand-in for the Backend Contract's per-invocation half: a scripted journal that speaks the typed
 * contract (`AwaitResult`, `NotificationResult`, …) the way the VM does, and records every operation.
 *
 * **Handles.** Every operation that returns a handle allocates the next one, from 1. Tests that build futures by hand
 * use their own handle numbers and script them with complete() / interrupt().
 *
 * **Results arrive in order.** A handle's result arrives when the operation is issued (or when complete() is called),
 * after every result that arrived before it; it is not completed until an await processes it. doAwait() processes
 * arrived results in arrival order up to the awaited handle, doAwaitTree() one at a time until the tree resolves (so
 * the order decides which future wins a race), and isCompleted() reports what was processed and not taken yet (a tree
 * resolves only on those, and a completed doAwaitTree() lists the tree's handles that are, depth first). An
 * await that cannot complete reports $whenPending (suspended, as the VM does once the request body is read). A result
 * can be taken once, and only once it was processed.
 *
 * **What an operation does, unless scripted.** State reads and writes use $state (eager state), promise reads and
 * completions use $promises, a sleep completes at once, a run executes (its first await reports execute-run) and
 * completes with what is proposed, an awakeable gets the id `awakeable-<n>`. Calls, sends, attaches, invocation output
 * reads, awakeables, signals and unknown promises never complete. onNext() / onEvery() replace that for an operation: their
 * steps are the interruptions its awaits report first (AwaitState::ExecuteRun reports the handle itself), then,
 * optionally, its result. interrupt() adds such interruptions to a handle.
 *
 * **A whole attempt.** It is also an {@see Invocation}, so an endpoint can run a handler on it
 * (`Endpoint::create(new FakeBackend($scripted), …)`): the request is always ready, its input is $input, and the output
 * calls (`writeOutputSuccess`, `writeOutputFailure`, `notifyError`, `notifyPause`, `end`) are only recorded.
 *
 * The values it can return are typed, so it cannot hand the SDK anything the typed contract cannot express; that the
 * extension produces every typed variant is pinned by `tests/Extension/BackendContractTest.php`.
 */
final class ScriptedInvocation implements Invocation, ReplayStateProvider
{
    /** The operations that return a handle, which onNext() / onEvery() script. */
    private const array HANDLE_OPERATIONS = [
        'sysAwakeable', 'sysSignal', 'sysAttachInvocation', 'sysGetInvocationOutput', 'sysGetPromise', 'sysPeekPromise',
        'sysResolvePromise', 'sysRejectPromise', 'sysCall', 'sysSend', 'sysSleep', 'sysRun', 'sysGetState',
        'sysGetStateKeys',
    ];

    /** @var array<string, string> the invocation's state, as eager state */
    public array $state = [];

    /** @var array<string, NotificationResult> completed durable promises */
    public array $promises = [];

    /** What isReplaying() reports. */
    public bool $replaying = false;

    /** What input() reports, when an endpoint runs an attempt on this invocation. */
    public InvocationInput $input;

    /** What an await reports when the result it waits for never arrives. */
    public AwaitResult $whenPending;

    /** What proposeRunCompletionRetryable() reports; Completed also completes the run with the failure. */
    public RunCompletionStatus $retryableRunStatus = RunCompletionStatus::AttemptEnded;

    /** What proposeRunCompletionPause() throws, as the VM does below protocol v7; null: it ends the attempt. */
    public ?\Throwable $runPauseFailure = null;

    /** @var list<array{0: string, 1: list<mixed>, 2: mixed}> operation, arguments, result */
    private array $journal = [];

    /** @var array<string, list<list<AwaitState|NotificationResult>>> */
    private array $nextSteps = [];

    /** @var array<string, list<AwaitState|NotificationResult>> */
    private array $everySteps = [];

    private int $nextHandle = 1;

    private int $awakeables = 0;

    /** @var array<int, list<AwaitState>> */
    private array $interruptions = [];

    /** @var array<int, NotificationResult> results that arrived, processed or not */
    private array $results = [];

    /** @var list<int> handles whose results arrived and were not processed yet, in arrival order */
    private array $arrived = [];

    /** @var array<int, true> */
    private array $processed = [];

    /** @var array<int, true> */
    private array $taken = [];

    /** @var array<int, true> */
    private array $runs = [];

    private function __construct(private readonly bool $unused)
    {
        $this->whenPending = AwaitResult::suspended();
        $this->input = new InvocationInput('inv_test', 0, '', [], '');
    }

    public static function new(): self
    {
        return new self(false);
    }

    /** Operations for a handler context whose test never reaches the backend: every operation fails the test. */
    public static function unused(): self
    {
        return new self(true);
    }

    /**
     * Scripts the next call of $operation (calls queue up in order): its awaits report the interruptions in $steps,
     * then its result arrives, if $steps ends with one. No steps: it never completes.
     */
    public function onNext(string $operation, AwaitState|NotificationResult ...$steps): self
    {
        $this->nextSteps[self::handleOperation($operation)][] = self::steps($steps);

        return $this;
    }

    /** Scripts every call of $operation that onNext() did not script. */
    public function onEvery(string $operation, AwaitState|NotificationResult ...$steps): self
    {
        $this->everySteps[self::handleOperation($operation)] = self::steps($steps);

        return $this;
    }

    /** The handle's result arrives now, after every result that arrived before it. */
    public function complete(int $handle, NotificationResult $result): self
    {
        if (array_key_exists($handle, $this->results)) {
            throw new \LogicException("Handle {$handle} already has a result.");
        }
        $this->results[$handle] = $result;
        $this->arrived[] = $handle;

        return $this;
    }

    /** The handle's next awaits report these states before anything else, one per await. */
    public function interrupt(int $handle, AwaitState ...$states): self
    {
        foreach (self::steps($states) as $state) {
            $this->interruptions[$handle][] = $state;
        }

        return $this;
    }

    /** @return list<array{0: string, 1: list<mixed>, 2: mixed}> every operation called, in order: name, arguments, result */
    public function journal(): array
    {
        return $this->journal;
    }

    /** @return list<string> every operation called, in order */
    public function operations(): array
    {
        return array_map(static fn (array $entry): string => $entry[0], $this->journal);
    }

    /** @return list<list<mixed>> the arguments of every call of $operation, in order */
    public function calls(string $operation): array
    {
        self::operation($operation);

        return array_values(array_map(
            static fn (array $entry): array => $entry[1],
            array_filter($this->journal, static fn (array $entry): bool => $entry[0] === $operation),
        ));
    }

    /** @return list<int> the handles $operation returned, in order */
    public function handles(string $operation): array
    {
        self::handleOperation($operation);

        return array_values(array_map(
            static fn (array $entry): int => $entry[2] instanceof AwakeableHandle ? $entry[2]->handle : $entry[2],
            array_filter($this->journal, static fn (array $entry): bool => $entry[0] === $operation),
        ));
    }

    /** @return list<int> the handles doAwait() was called with, in order */
    public function awaited(): array
    {
        return array_map(static fn (array $arguments): int => $arguments[0], $this->calls('doAwait'));
    }

    /** @return list<AwaitTree> the trees doAwaitTree() was called with, in order */
    public function awaitedTrees(): array
    {
        return array_map(static fn (array $arguments): AwaitTree => $arguments[0], $this->calls('doAwaitTree'));
    }

    /** @return list<int> the handles whose results were taken, in order */
    public function taken(): array
    {
        return array_map(static fn (array $arguments): int => $arguments[0], $this->calls('takeNotificationResult'));
    }

    public function responseHead(): ResponseHead
    {
        return new ResponseHead(200, [['content-type', 'application/vnd.restate.invocation.v6']]);
    }

    public function notifyInput(string $bytes): void
    {
        $this->record(__FUNCTION__, [$bytes]);
    }

    public function notifyInputClosed(): void
    {
        $this->record(__FUNCTION__, []);
    }

    public function isReadyToExecute(): bool
    {
        return true;
    }

    public function input(): InvocationInput
    {
        return $this->input;
    }

    public function writeOutputSuccess(string $bytes): void
    {
        $this->record(__FUNCTION__, [$bytes]);
    }

    public function writeOutputFailure(int $code, string $message, array $metadata = []): void
    {
        $this->record(__FUNCTION__, [$code, $message, $metadata]);
    }

    public function notifyError(string $message, string $stacktrace = '', ?int $retryAfterMillis = null, int $code = 500): void
    {
        $this->record(__FUNCTION__, [$message, $stacktrace, $retryAfterMillis, $code]);
    }

    public function notifyPause(string $message, string $stacktrace = ''): void
    {
        $this->record(__FUNCTION__, [$message, $stacktrace]);
    }

    public function end(): void
    {
        $this->record(__FUNCTION__, []);
    }

    public function takeOutput(): ?string
    {
        return null;
    }

    public function free(): void
    {
    }

    public function isReplaying(): bool
    {
        return $this->record(__FUNCTION__, [], $this->replaying);
    }

    public function sysAwakeable(): AwakeableHandle
    {
        $this->expected(__FUNCTION__);
        $handle = $this->issue(__FUNCTION__, []);

        return $this->record(__FUNCTION__, [], new AwakeableHandle('awakeable-' . ++$this->awakeables, $handle));
    }

    public function sysResolveAwakeable(string $id, string $payload): void
    {
        $this->record(__FUNCTION__, [$id, $payload]);
    }

    public function sysRejectAwakeable(string $id, int $code, string $message, array $metadata = []): void
    {
        $this->record(__FUNCTION__, [$id, $code, $message, $metadata]);
    }

    public function sysSignal(string $name): int
    {
        return $this->operationHandle(__FUNCTION__, [$name]);
    }

    public function sysResolveSignal(string $invocationId, string $name, string $payload): void
    {
        $this->record(__FUNCTION__, [$invocationId, $name, $payload]);
    }

    public function sysRejectSignal(string $invocationId, string $name, int $code, string $message, array $metadata = []): void
    {
        $this->record(__FUNCTION__, [$invocationId, $name, $code, $message, $metadata]);
    }

    public function sysAttachInvocation(AttachTarget $target): int
    {
        return $this->operationHandle(__FUNCTION__, [$target]);
    }

    public function sysGetInvocationOutput(AttachTarget $target): int
    {
        return $this->operationHandle(__FUNCTION__, [$target]);
    }

    public function sysGetPromise(string $key): int
    {
        return $this->operationHandle(__FUNCTION__, [$key], isset($this->promises[$key]) ? [$this->promises[$key]] : []);
    }

    public function sysPeekPromise(string $key): int
    {
        return $this->operationHandle(__FUNCTION__, [$key], [$this->promises[$key] ?? NotificationResult::void()]);
    }

    public function sysResolvePromise(string $key, string $payload): int
    {
        $handle = $this->operationHandle(__FUNCTION__, [$key, $payload], [NotificationResult::void()]);
        $this->promises[$key] ??= NotificationResult::success($payload);

        return $handle;
    }

    public function sysRejectPromise(string $key, int $code, string $message, array $metadata = []): int
    {
        $handle = $this->operationHandle(__FUNCTION__, [$key, $code, $message, $metadata], [NotificationResult::void()]);
        $this->promises[$key] ??= NotificationResult::failure($code, $message, $metadata);

        return $handle;
    }

    public function sysCancelInvocation(string $invocationId): void
    {
        $this->record(__FUNCTION__, [$invocationId]);
    }

    public function sysCall(CallTarget $target, string $payload, ?string $idempotencyKey = null): int
    {
        return $this->operationHandle(__FUNCTION__, [$target, $payload, $idempotencyKey]);
    }

    public function sysSend(CallTarget $target, string $payload, ?int $delayMillis, ?string $idempotencyKey = null): int
    {
        return $this->operationHandle(__FUNCTION__, [$target, $payload, $delayMillis, $idempotencyKey]);
    }

    public function sysSleep(int $delayMillis): int
    {
        return $this->operationHandle(__FUNCTION__, [$delayMillis], [NotificationResult::void()]);
    }

    public function takeNotificationResult(int $handle): NotificationResult
    {
        $this->expected(__FUNCTION__);
        if (!isset($this->processed[$handle]) || isset($this->taken[$handle])) {
            throw new \LogicException(isset($this->taken[$handle])
                ? "The result of handle {$handle} was already taken."
                : "Handle {$handle} has not completed: await it first.");
        }
        $this->taken[$handle] = true;

        return $this->record(__FUNCTION__, [$handle], $this->results[$handle]);
    }

    public function sysRun(string $name): int
    {
        $handle = $this->operationHandle(__FUNCTION__, [$name], [AwaitState::ExecuteRun]);
        $this->runs[$handle] = true;

        return $handle;
    }

    public function doAwait(int $handle): AwaitResult
    {
        $this->expected(__FUNCTION__);
        if (isset($this->taken[$handle])) {
            throw new \LogicException("The result of handle {$handle} was already taken: it cannot be awaited again.");
        }

        $interruption = isset($this->interruptions[$handle]) ? array_shift($this->interruptions[$handle]) : null;
        if ($interruption !== null) {
            $result = $interruption === AwaitState::ExecuteRun ? AwaitResult::executeRun($handle) : AwaitResult::of($interruption);
        } elseif (isset($this->processed[$handle]) || $this->processUntil(fn (): bool => isset($this->processed[$handle]))) {
            $result = AwaitResult::completed();
        } else {
            $result = $this->whenPending;
        }

        return $this->record(__FUNCTION__, [$handle], $result);
    }

    public function doAwaitTree(AwaitTree $tree): AwaitResult
    {
        $this->expected(__FUNCTION__);

        return $this->record(
            __FUNCTION__,
            [$tree],
            $this->processUntil(fn (): bool => $this->resolved($tree) !== null)
                ? AwaitResult::completed(array_values(array_filter(self::leaves($tree), $this->isProcessedAndNotTaken(...))))
                : $this->whenPending,
        );
    }

    public function isCompleted(int $handle): bool
    {
        return $this->record(__FUNCTION__, [$handle], $this->isProcessedAndNotTaken($handle));
    }

    public function proposeRunCompletionSuccess(int $handle, string $bytes, ?RunRetryPolicy $retryPolicy = null): void
    {
        $this->record(__FUNCTION__, [$handle, $bytes, $retryPolicy]);
        $this->completeRun($handle, NotificationResult::success($bytes));
    }

    public function proposeRunCompletionFailure(int $handle, int $code, string $message, ?RunRetryPolicy $retryPolicy = null, array $metadata = []): void
    {
        $this->record(__FUNCTION__, [$handle, $code, $message, $retryPolicy, $metadata]);
        $this->completeRun($handle, NotificationResult::failure($code, $message, $metadata));
    }

    public function proposeRunCompletionRetryable(int $handle, string $message, int $attemptDurationMillis, ?RunRetryPolicy $retryPolicy = null, ?int $retryAfterMillis = null, int $code = 500, array $metadata = []): RunCompletionStatus
    {
        $status = $this->record(__FUNCTION__, [$handle, $message, $attemptDurationMillis, $retryPolicy, $retryAfterMillis, $code, $metadata], $this->retryableRunStatus);
        if ($status === RunCompletionStatus::Completed) {
            // As the VM does: an exhausted retry policy keeps the failure code only if it is 400–599 and not 570.
            $this->completeRun($handle, NotificationResult::failure($code >= 400 && $code <= 599 && $code !== 570 ? $code : 500, $message, $metadata));
        }

        return $status;
    }

    public function proposeRunCompletionPause(int $handle, string $message, int $attemptDurationMillis): void
    {
        $this->record(__FUNCTION__, [$handle, $message, $attemptDurationMillis]);
        if ($this->runPauseFailure !== null) {
            throw $this->runPauseFailure;
        }
    }

    public function sysGetState(string $key): int
    {
        $value = $this->state[$key] ?? null;

        return $this->operationHandle(__FUNCTION__, [$key], [$value === null ? NotificationResult::void() : NotificationResult::success($value)]);
    }

    public function sysGetStateKeys(): int
    {
        return $this->operationHandle(__FUNCTION__, [], [NotificationResult::stateKeys(array_map(strval(...), array_keys($this->state)))]);
    }

    public function setState(string $key, string $bytes): void
    {
        $this->record(__FUNCTION__, [$key, $bytes]);
        $this->state[$key] = $bytes;
    }

    public function clearState(string $key): void
    {
        $this->record(__FUNCTION__, [$key]);
        unset($this->state[$key]);
    }

    public function clearAllState(): void
    {
        $this->record(__FUNCTION__, []);
        $this->state = [];
    }

    /**
     * Records a call of a handle operation and returns its new handle.
     *
     * @param list<mixed> $arguments
     * @param list<AwaitState|NotificationResult> $default the steps when the test scripted none
     */
    private function operationHandle(string $operation, array $arguments, array $default = []): int
    {
        $this->expected($operation);

        return $this->record($operation, $arguments, $this->issue($operation, $default));
    }

    /** @param list<AwaitState|NotificationResult> $default */
    private function issue(string $operation, array $default): int
    {
        $handle = $this->nextHandle++;
        $steps = ($this->nextSteps[$operation] ?? []) !== [] ? array_shift($this->nextSteps[$operation]) : $this->everySteps[$operation] ?? $default;
        foreach ($steps as $step) {
            $step instanceof NotificationResult ? $this->complete($handle, $step) : $this->interrupt($handle, $step);
        }

        return $handle;
    }

    private function completeRun(int $handle, NotificationResult $result): void
    {
        if (!isset($this->runs[$handle])) {
            throw new \LogicException("Handle {$handle} is not a run: sysRun() did not return it.");
        }
        if (array_key_exists($handle, $this->results)) {
            throw new \LogicException("Run {$handle} already has a result: a completed run is not executed again.");
        }
        $this->complete($handle, $result);
    }

    /**
     * Processes arrived results one at a time until $done; false when they ran out first.
     *
     * @param \Closure(): bool $done
     */
    private function processUntil(\Closure $done): bool
    {
        while (!$done()) {
            $next = array_shift($this->arrived);
            if ($next === null) {
                return false;
            }
            $this->processed[$next] = true;
        }

        return true;
    }

    private function isProcessedAndNotTaken(int $handle): bool
    {
        return isset($this->processed[$handle]) && !isset($this->taken[$handle]);
    }

    /** @return list<int> the tree's handles, depth first */
    private static function leaves(int|AwaitTree $tree): array
    {
        return is_int($tree) ? [$tree] : array_merge(...array_map(self::leaves(...), $tree->children));
    }

    /**
     * Whether the tree resolved with what was processed and not taken (as the VM, which reads only results it holds):
     * true (succeeded), false (failed), null (pending).
     */
    private function resolved(int|AwaitTree $tree): ?bool
    {
        if (is_int($tree)) {
            return $this->isProcessedAndNotTaken($tree) ? $this->results[$tree]->kind !== NotificationKind::Failure : null;
        }

        $states = array_map($this->resolved(...), $tree->children);

        return match ($tree->combinator) {
            AwaitCombinator::FirstCompleted => array_find($states, static fn (?bool $state): bool => $state !== null),
            AwaitCombinator::AllCompleted => in_array(null, $states, true) ? null : true,
            AwaitCombinator::FirstSucceededOrAllFailed => match (true) {
                in_array(true, $states, true) => true,
                in_array(null, $states, true) => null,
                default => false,
            },
            AwaitCombinator::AllSucceededOrFirstFailed => match (true) {
                in_array(false, $states, true) => false,
                in_array(null, $states, true) => null,
                default => true,
            },
        };
    }

    /**
     * @template T
     *
     * @param list<mixed> $arguments
     * @param T $result
     *
     * @return T
     */
    private function record(string $operation, array $arguments, mixed $result = null): mixed
    {
        $this->expected($operation);
        $this->journal[] = [$operation, $arguments, $result];

        return $result;
    }

    private function expected(string $operation): void
    {
        if ($this->unused) {
            throw new \LogicException("InvocationOperations::{$operation}() is not expected in this test.");
        }
    }

    /**
     * @template T of AwaitState|NotificationResult
     *
     * @param array<array-key, T> $steps
     *
     * @return list<T>
     */
    private static function steps(array $steps): array
    {
        $steps = array_values($steps);
        foreach ($steps as $index => $step) {
            if ($step === AwaitState::Completed) {
                throw new \InvalidArgumentException('Script a completion as its NotificationResult, not as AwaitState::Completed.');
            }
            if ($step instanceof NotificationResult && $index !== count($steps) - 1) {
                throw new \InvalidArgumentException('A result must be the last step.');
            }
        }

        return $steps;
    }

    private static function handleOperation(string $operation): string
    {
        if (!in_array($operation, self::HANDLE_OPERATIONS, true)) {
            throw new \InvalidArgumentException("{$operation}() does not return a handle.");
        }

        return $operation;
    }

    private static function operation(string $operation): void
    {
        if (!method_exists(Invocation::class, $operation) && $operation !== 'isReplaying') {
            throw new \InvalidArgumentException("{$operation}() is not an invocation operation.");
        }
    }
}
