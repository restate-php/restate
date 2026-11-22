<?php

declare(strict_types=1);

namespace Restate\Tests\Extension;

use PHPUnit\Framework\Attributes\DataProvider;
use Restate\Extension\Vm;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\Extension\SurfaceValues;
use Restate\Internal\Backend\NotificationKind;
use Restate\Internal\Backend\RunCompletionStatus;
use Restate\Tests\Support\BackendShapes;
use Restate\Tests\Support\Protocol\FrameCodec;

/**
 * The unit tests stand in for the extension backend with typed contract values: `ScriptedInvocation` (and the PHPUnit
 * mocks of `Backend\Invocation`) return `AwaitResult`, `NotificationResult`, `RunCompletionStatus`, … directly, so they
 * can only return what the typed contract can express. This test pins the other half: it drives the extension
 * (`Restate\Extension\Vm`) into every result variant {@see BackendShapes} lists, checks each against its
 * definition, converts it with `Extension\SurfaceValues` to the typed value of the same variant, and fails when a listed
 * variant has no scenario or when a typed variant (an `AwaitState`, `NotificationKind` or `RunCompletionStatus` case)
 * is not listed. So every value a unit test can script is one the extension produces, and a change to what the
 * extension returns fails here.
 */
final class BackendContractTest extends ExtensionTestCase
{
    private const array GREETER = ['kind' => 'service', 'service' => 'Greeter', 'handler' => 'greet', 'key' => null];

    /**
     * Every result variant of the backend contract, each with a scenario that makes the extension produce it.
     *
     * @return array<string, array{0: string, 1: string, 2: \Closure(): array<array-key, mixed>}>
     */
    public static function variants(): array
    {
        $variants = [];
        foreach (self::scenarios() as $method => $scenarios) {
            foreach ($scenarios as $variant => $scenario) {
                $variants["{$method}: {$variant}"] = [$method, $variant, $scenario];
            }
        }

        return $variants;
    }

    /** @param \Closure(): array<array-key, mixed> $scenario */
    #[DataProvider('variants')]
    public function testTheExtensionProducesTheVariantAsDefined(string $method, string $variant, \Closure $scenario): void
    {
        $result = $scenario();

        self::assertSame($variant, BackendShapes::variant($method, $result));
        self::assertSame($variant, self::typedVariant($method, $result), 'the typed contract value has the same variant');
    }

    public function testEveryDefinedVariantHasAScenario(): void
    {
        $defined = [];
        foreach (BackendShapes::TAGGED as $method => $variants) {
            $defined[$method] = array_keys($variants);
        }
        foreach (array_keys(BackendShapes::FIXED) as $method) {
            $defined[$method] = [$method];
        }

        $covered = array_map(static fn (array $scenarios): array => array_keys($scenarios), self::scenarios());

        self::assertEqualsCanonicalizing($defined, $covered);
    }

    /** Every typed variant a unit test can script is an extension variant listed (and so produced) above. */
    public function testEveryTypedVariantIsADefinedVariant(): void
    {
        self::assertEqualsCanonicalizing(self::caseValues(AwaitState::cases()), array_keys(BackendShapes::TAGGED['doAwait']));
        self::assertEqualsCanonicalizing(self::caseValues(AwaitState::cases()), array_keys(BackendShapes::TAGGED['doAwaitTree']));
        self::assertEqualsCanonicalizing(self::caseValues(NotificationKind::cases()), array_keys(BackendShapes::TAGGED['takeNotificationResult']));
        self::assertEqualsCanonicalizing(self::caseValues(RunCompletionStatus::cases()), array_keys(BackendShapes::TAGGED['proposeRunCompletionRetryable']));
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return list<string>
     */
    private static function caseValues(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): string => (string) $case->value, $cases);
    }

    /**
     * The variant of the typed value `Extension\SurfaceValues` builds from an extension result.
     *
     * @param array<array-key, mixed> $result
     */
    private static function typedVariant(string $method, array $result): string
    {
        return match ($method) {
            'doAwait' => SurfaceValues::awaitResult($result)->state->value,
            'doAwaitTree' => SurfaceValues::awaitTreeResult($result)->state->value,
            'takeNotificationResult' => SurfaceValues::notificationResult($result)->kind->value,
            'proposeRunCompletionRetryable' => SurfaceValues::runCompletionStatus($result)->value,
            'sysAwakeable' => self::converts(SurfaceValues::awakeable(...), $result, $method),
            'responseHead' => self::converts(SurfaceValues::responseHead(...), $result, $method),
            'input' => self::converts(SurfaceValues::invocationInput(...), $result, $method),
            'coreLogRecord' => self::converts(SurfaceValues::coreLogRecord(...), $result, $method),
            // The Backend returns the extension's range as is.
            'protocolVersions' => $method,
            default => self::fail("No typed value for {$method}()."),
        };
    }

    /**
     * $variant, once $convert built a value from $result (it throws on a shape it does not know).
     *
     * @param \Closure(array<array-key, mixed>): object $convert
     * @param array<array-key, mixed> $result
     */
    private static function converts(\Closure $convert, array $result, string $variant): string
    {
        $convert($result);

        return $variant;
    }

    /** @return array<string, array<string, \Closure(): array<array-key, mixed>>> method => variant => scenario */
    private static function scenarios(): array
    {
        return [
            'protocolVersions' => [
                'protocolVersions' => static fn (): array => \Restate\Extension\protocol_versions(),
            ],
            'doAwait' => self::awaitScenarios(static fn (Vm $vm, int $handle): array => $vm->doAwait($handle)),
            'doAwaitTree' => self::awaitScenarios(static fn (Vm $vm, int $handle): array => $vm->doAwaitTree(['all_succeeded_or_first_failed', [$handle]])),
            'takeNotificationResult' => [
                'success' => static function (): array {
                    $vm = self::runningVm(key: 'alice', state: ['count' => '5']);

                    return self::completedResult($vm, $vm->sysGetState('count'));
                },
                'void' => static function (): array {
                    $vm = self::runningVm(key: 'alice', state: ['count' => '5']);

                    return self::completedResult($vm, $vm->sysGetState('missing'));
                },
                'failure' => static function (): array {
                    $vm = self::runningVm([
                        FrameCodec::runCommandFrame(1, 'charge'),
                        FrameCodec::completionFrame(FrameCodec::RUN_COMPLETION, 1, failure: ['code' => 402, 'message' => 'declined', 'metadata' => ['404' => 'x']]),
                    ]);

                    return self::completedResult($vm, $vm->sysRun('charge'));
                },
                'invocation_id' => static function (): array {
                    $vm = self::runningVm(open: true);
                    $handle = $vm->sysSend(self::GREETER, '""');
                    self::assertSame(['type' => 'read_from_input'], $vm->doAwait($handle));
                    $vm->notifyInput(FrameCodec::completionFrame(FrameCodec::CALL_INVOCATION_ID_COMPLETION, 1, invocationId: 'inv-2'));

                    return self::completedResult($vm, $handle);
                },
                'state_keys' => static function (): array {
                    $vm = self::runningVm(key: 'alice', state: ['count' => '5']);

                    return self::completedResult($vm, $vm->sysGetStateKeys());
                },
            ],
            'proposeRunCompletionRetryable' => [
                'attempt_ended' => static fn (): array => self::proposeRetryable(null),
                'completed' => static fn (): array => self::proposeRetryable(['type' => 'none']),
            ],
            'sysAwakeable' => [
                'sysAwakeable' => static fn (): array => self::runningVm()->sysAwakeable(),
            ],
            'responseHead' => [
                'responseHead' => static fn (): array => new Vm([['content-type', self::V5]])->responseHead(),
            ],
            'coreLogRecord' => [
                // shared-core logs the start of the invocation at debug.
                'coreLogRecord' => static fn (): array => self::runningVm(logLevel: 'debug')->takeLogs()[0] ?? self::fail('No core log record.'),
            ],
            'input' => [
                // v7, so scope, limit key and idempotency key are strings (before v7 they are null; see ExtensionInvocationTest).
                'input' => static function (): array {
                    $vm = new Vm([['content-type', self::V7]]);
                    $vm->notifyInput(FrameCodec::startFrame(self::INVOCATION_ID, 1, 'alice', scope: 'tenant-1', limitKey: 'user-7', idempotencyKey: 'once'));
                    $vm->notifyInput(FrameCodec::inputCommandFrame('"Mary"', [['x-trace', 'abc']]));
                    $vm->notifyInputClosed();
                    $vm->isReadyToExecute();

                    return $vm->input();
                },
            ],
        ];
    }

    /**
     * The await states, for doAwait() on one handle or doAwaitTree() on a tree over it.
     *
     * @param \Closure(Vm, int): array<array-key, mixed> $await
     *
     * @return array<string, \Closure(): array<array-key, mixed>>
     */
    private static function awaitScenarios(\Closure $await): array
    {
        return [
            'any_completed' => static function () use ($await): array {
                $vm = self::runningVm(key: 'alice', state: ['count' => '5']);

                return $await($vm, $vm->sysGetState('count'));
            },
            'suspended' => static function () use ($await): array {
                $vm = self::runningVm();

                return $await($vm, $vm->sysSleep(60_000));
            },
            'read_from_input' => static function () use ($await): array {
                $vm = self::runningVm(open: true);

                return $await($vm, $vm->sysSleep(60_000));
            },
            'waiting_pending_run' => static function () use ($await): array {
                $vm = self::runningVm();
                $handle = $vm->sysRun('charge');
                $await($vm, $handle);

                return $await($vm, $handle);
            },
            'cancel_signal_received' => static function () use ($await): array {
                $vm = self::runningVm(open: true);
                $handle = $vm->sysSleep(60_000);
                $vm->notifyInput(FrameCodec::cancelSignalFrame());

                return $await($vm, $handle);
            },
            'execute_run' => static function () use ($await): array {
                $vm = self::runningVm();

                return $await($vm, $vm->sysRun('charge'));
            },
        ];
    }

    /** @return array<array-key, mixed> */
    private static function completedResult(Vm $vm, int $handle): array
    {
        self::assertSame(['type' => 'any_completed'], $vm->doAwait($handle));

        return $vm->takeNotificationResult($handle);
    }

    /**
     * @param array{type: 'none'}|null $retryPolicy
     *
     * @return array<array-key, mixed>
     */
    private static function proposeRetryable(?array $retryPolicy): array
    {
        $vm = self::runningVm();
        $handle = $vm->sysRun('charge');
        $vm->doAwait($handle);

        return $vm->proposeRunRetryable($handle, 500, 'timeout', [], 5, $retryPolicy);
    }
}
