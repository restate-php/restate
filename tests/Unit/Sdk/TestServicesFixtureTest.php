<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\Backend\RunCompletionStatus;
use Restate\Internal\InvocationAttempt;
use Restate\Internal\SuspensionException;
use Restate\RunRetryPolicy;
use Restate\ServiceDefinition;
use Restate\Internal\ServiceRegistry;
use Restate\Target;
use Restate\TerminalError;
use Restate\Tests\Support\ScriptedInvocation;

final class TestServicesFixtureTest extends TestCase
{
    /** Scratch directory the services keep retry counters in (RESTATE_PHP_TEST_STATE_DIR). */
    private string $stateDir;

    private string|false $previousStateDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/restate-php-test-services-' . bin2hex(random_bytes(6));
        mkdir($this->stateDir);
        $this->previousStateDir = getenv('RESTATE_PHP_TEST_STATE_DIR');
        putenv('RESTATE_PHP_TEST_STATE_DIR=' . $this->stateDir);
    }

    protected function tearDown(): void
    {
        putenv($this->previousStateDir === false ? 'RESTATE_PHP_TEST_STATE_DIR' : 'RESTATE_PHP_TEST_STATE_DIR=' . $this->previousStateDir);
        $files = glob($this->stateDir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->stateDir);
    }
    /** @return list<int> */
    private static function bytes(string $string): array
    {
        return array_map(ord(...), str_split($string));
    }

    /** @return iterable<string, array{string}> */
    public static function fixtureFiles(): iterable
    {
        yield 'greeter' => [self::fixturePath('greeter.php')];
        yield 'communicator' => [self::fixturePath('communicator.php')];
        yield 'payments' => [self::fixturePath('payments.php')];
        yield 'counter' => [self::fixturePath('counter.php')];
        yield 'checkout' => [self::fixturePath('checkout.php')];
        yield 'echo-workflow' => [self::fixturePath('echo_workflow.php')];
        yield 'proxy' => [self::fixturePath('proxy.php')];
        yield 'test-utils-service' => [self::fixturePath('test_utils_service.php')];
        yield 'failing' => [self::fixturePath('failing.php')];
        yield 'virtual-object-command-interpreter' => [self::fixturePath('virtual_object_command_interpreter.php')];
        yield 'map-object' => [self::fixturePath('map_object.php')];
        yield 'list-object' => [self::fixturePath('list_object.php')];
        yield 'awakeable-holder' => [self::fixturePath('awakeable_holder.php')];
        yield 'kill-test-runner' => [self::fixturePath('kill_test_runner.php')];
        yield 'kill-test-singleton' => [self::fixturePath('kill_test_singleton.php')];
        yield 'block-and-wait-workflow' => [self::fixturePath('block_and_wait_workflow.php')];
        yield 'cancel-test-runner' => [self::fixturePath('cancel_test_runner.php')];
        yield 'cancel-test-blocking-service' => [self::fixturePath('cancel_test_blocking_service.php')];
        yield 'non-deterministic' => [self::fixturePath('non_deterministic.php')];
    }

    #[DataProvider('fixtureFiles')]
    public function testFixtureFileReturnsServiceDefinition(string $path): void
    {
        self::assertFileExists($path);

        $definition = require $path;

        self::assertInstanceOf(ServiceDefinition::class, $definition);
    }

    public function testFailingEventualSuccessFailsUntilFourthAttempt(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('failing.php')]);
        $key = 'eventual-success-' . bin2hex(random_bytes(4));

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $registry->invoke('Failing', 'failingCallWithEventualSuccess', '', $this->context(), $key);
                self::fail('Expected retryable failure.');
            } catch (\RuntimeException $e) {
                self::assertSame("Failed at attempt: {$attempt}", $e->getMessage());
            }
        }

        self::assertSame('4', $registry->invoke('Failing', 'failingCallWithEventualSuccess', '', $this->context(), $key));
    }

    public function testFailingTerminallyFailingCallPropagatesMetadata(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('failing.php')]);

        try {
            $registry->invoke('Failing', 'terminallyFailingCall', '{"errorMessage":"boom","metadata":{"key1":"value1"}}', $this->statefulContext(self::stateful()), 'failing-1');
            self::fail('Expected terminal failure.');
        } catch (TerminalError $exception) {
            self::assertSame('boom', $exception->getMessage());
            self::assertSame(['key1' => 'value1'], $exception->metadata());
        }
    }

    public function testFailingTerminallyFailingSideEffectProposesRunFailureWithMetadata(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('failing.php')]);
        $invocation = ScriptedInvocation::new();

        try {
            $registry->invoke('Failing', 'terminallyFailingSideEffect', '{"errorMessage":"boom","metadata":{"key1":"value1"}}', $this->statefulContext($invocation), 'failing-1');
            self::fail('Expected terminal failure.');
        } catch (TerminalError $exception) {
            self::assertSame('boom', $exception->getMessage());
            self::assertSame(['key1' => 'value1'], $exception->metadata());
        }

        self::assertEquals([[$invocation->handles('sysRun')[0], 500, 'boom', RunRetryPolicy::infinite(), ['key1' => 'value1']]], $invocation->calls('proposeRunCompletionFailure'));
    }

    public function testFailingSideEffectSucceedsAfterGivenAttemptsUsesPersistentAttemptCounter(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('failing.php')]);
        $key = 'side-effect-success-' . bin2hex(random_bytes(4));

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $invocation = ScriptedInvocation::new();
            try {
                $registry->invoke('Failing', 'sideEffectSucceedsAfterGivenAttempts', '3', $this->statefulContext($invocation), $key);
                self::fail('Expected retry scheduling suspension.');
            } catch (SuspensionException $exception) {
                self::assertSame('Run retry scheduled.', $exception->getMessage());
            }
        }

        $invocation = ScriptedInvocation::new();
        self::assertSame('3', $registry->invoke('Failing', 'sideEffectSucceedsAfterGivenAttempts', '3', $this->statefulContext($invocation), $key));
        self::assertEquals([[$invocation->handles('sysRun')[0], '3', RunRetryPolicy::infinite()]], $invocation->calls('proposeRunCompletionSuccess'));
    }

    public function testFailingSideEffectFailsAfterGivenAttemptsReturnsPersistentAttemptCount(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('failing.php')]);
        $key = 'side-effect-failure-' . bin2hex(random_bytes(4));

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $invocation = ScriptedInvocation::new();
            try {
                $registry->invoke('Failing', 'sideEffectFailsAfterGivenAttempts', '3', $this->statefulContext($invocation), $key);
                self::fail('Expected retry scheduling suspension.');
            } catch (SuspensionException $exception) {
                self::assertSame('Run retry scheduled.', $exception->getMessage());
            }
        }

        $invocation = ScriptedInvocation::new();
        $invocation->retryableRunStatus = RunCompletionStatus::Completed;
        self::assertSame('3', $registry->invoke('Failing', 'sideEffectFailsAfterGivenAttempts', '3', $this->statefulContext($invocation), $key));
    }

    public function testFailingSideEffectFailsAfterGivenAttemptsFallsBackToRunFailureAttemptCount(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('failing.php')]);
        $invocation = ScriptedInvocation::new()->onEvery('sysRun', NotificationResult::failure(500, 'Failed at attempt: 1'));

        self::assertSame('1', $registry->invoke('Failing', 'sideEffectFailsAfterGivenAttempts', '1', $this->statefulContext($invocation), 'replayed-failure'));
    }

    public function testTestUtilsServiceEchoHeadersReturnsInvocationHeaders(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('test_utils_service.php')]);

        $output = $registry->invoke('TestUtilsService', 'echoHeaders', '', $this->context(headers: [
            ['x-my-custom-header', 'x-my-custom-value'],
        ]));

        self::assertSame([
            'x-my-custom-header' => 'x-my-custom-value',
        ], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testTestUtilsServiceRawEchoReturnsRawBytes(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('test_utils_service.php')]);
        $bytes = "raw\0bytes";

        self::assertSame($bytes, $registry->invoke('TestUtilsService', 'rawEcho', $bytes, $this->context()));
    }

    public function testTestUtilsCountExecutedSideEffectsIgnoresReplayedRunResults(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('test_utils_service.php')]);
        $invocation = ScriptedInvocation::new();
        foreach ([10, 20, 30] as $replayed) {
            $invocation->onNext('sysRun', NotificationResult::success(json_encode($replayed, JSON_THROW_ON_ERROR)));
        }

        $output = $registry->invoke('TestUtilsService', 'countExecutedSideEffects', '3', new InvocationAttempt($invocation));

        self::assertSame('0', $output);
        self::assertSame([['count'], ['count'], ['count']], $invocation->calls('sysRun'));
    }

    public function testNonDeterministicHandlersFollowAuditedOperationShapes(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('non_deterministic.php')]);
        $countsPath = sys_get_temp_dir() . '/restate-php-non-deterministic-counts-' . bin2hex(random_bytes(6)) . '.json';
        $previousCountsPath = getenv('RESTATE_PHP_NONDETERMINISTIC_COUNTS_PATH');
        putenv("RESTATE_PHP_NONDETERMINISTIC_COUNTS_PATH={$countsPath}");

        try {
            $firstEither = self::nonDeterministic();
            $registry->invoke('NonDeterministic', 'eitherSleepOrCall', '', $this->statefulContext($firstEither), 'eitherSleepOrCall');
            $firstSendTarget = $firstEither->calls('sysSend')[0][0];

            self::assertSame([[100], [100]], $firstEither->calls('sysSleep'));
            self::assertInstanceOf(CallTarget::class, $firstSendTarget);
            self::assertSame('add', $firstSendTarget->target->toArray()['handler']);

            $secondEither = self::nonDeterministic();
            $registry->invoke('NonDeterministic', 'eitherSleepOrCall', '', $this->statefulContext($secondEither), 'eitherSleepOrCall');
            $secondCallTarget = $secondEither->calls('sysCall')[0][0];

            self::assertSame([[100]], $secondEither->calls('sysSleep'));
            self::assertInstanceOf(CallTarget::class, $secondCallTarget);
            self::assertSame('get', $secondCallTarget->target->toArray()['handler']);

            $firstSet = self::nonDeterministic();
            $registry->invoke('NonDeterministic', 'setDifferentKey', '', $this->statefulContext($firstSet), 'setDifferentKey');

            self::assertSame('"my-state"', $firstSet->state['a'] ?? null);
            self::assertArrayNotHasKey('b', $firstSet->state);

            $secondSet = self::nonDeterministic();
            $registry->invoke('NonDeterministic', 'setDifferentKey', '', $this->statefulContext($secondSet), 'setDifferentKey');

            self::assertSame('"my-state"', $secondSet->state['b'] ?? null);
            self::assertArrayNotHasKey('a', $secondSet->state);
        } finally {
            if ($previousCountsPath === false) {
                putenv('RESTATE_PHP_NONDETERMINISTIC_COUNTS_PATH');
            } else {
                putenv("RESTATE_PHP_NONDETERMINISTIC_COUNTS_PATH={$previousCountsPath}");
            }
            if (is_file($countsPath)) {
                unlink($countsPath);
            }
        }
    }

    public function testCounterAddReturnsOldAndNewValues(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('counter.php')]);
        $invocation = self::stateful();
        $context = $this->statefulContext($invocation);

        $first = $registry->invoke('Counter', 'add', '1', $context, 'counter-1');
        $second = $registry->invoke('Counter', 'add', '2', $context, 'counter-1');

        self::assertSame(['oldValue' => 0, 'newValue' => 1], json_decode($first, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['oldValue' => 1, 'newValue' => 3], json_decode($second, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testCounterGetAcceptsEmptyInput(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('counter.php')]);
        $invocation = self::stateful(['value' => json_encode(7, JSON_THROW_ON_ERROR)]);

        self::assertSame('7', $registry->invoke('Counter', 'get', '', $this->statefulContext($invocation), 'counter-1'));
    }

    public function testCounterAddThenFailPersistsStateBeforeTerminalFailure(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('counter.php')]);
        $invocation = self::stateful();
        $context = $this->statefulContext($invocation);

        try {
            $registry->invoke('Counter', 'addThenFail', '1', $context, 'my-failure-counter');
            self::fail('Expected addThenFail to throw terminal failure.');
        } catch (TerminalError $e) {
            self::assertSame(500, $e->statusCode());
            self::assertStringContainsString('my-failure-counter', $e->getMessage());
        }

        self::assertSame('1', $registry->invoke('Counter', 'get', '', $context, 'my-failure-counter'));
    }

    public function testCounterResetClearsValueAndReturnsEmptyOutput(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('counter.php')]);
        $invocation = self::stateful(['value' => json_encode(7, JSON_THROW_ON_ERROR)]);
        $context = $this->statefulContext($invocation);

        self::assertSame('', $registry->invoke('Counter', 'reset', '', $context, 'counter-1'));
        self::assertSame('0', $registry->invoke('Counter', 'get', '', $context, 'counter-1'));
    }

    public function testProxyCallForwardsIdempotencyKey(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('proxy.php')]);
        $invocation = self::proxy();
        $context = new InvocationAttempt($invocation);

        $result = $registry->invoke('Proxy', 'call', json_encode([
            'serviceName' => 'Counter',
            'virtualObjectKey' => 'counter-1',
            'handlerName' => 'add',
            'message' => self::bytes('1'),
            'idempotencyKey' => 'idem-proxy-call',
        ], JSON_THROW_ON_ERROR), $context);

        self::assertSame(self::bytes('{"oldValue":0,"newValue":1}'), json_decode($result, true, flags: JSON_THROW_ON_ERROR));
        self::assertEquals([[new CallTarget(Target::object('Counter', 'counter-1', 'add')), '1', 'idem-proxy-call']], $invocation->calls('sysCall'));
    }

    public function testProxyOneWayCallForwardsIdempotencyKey(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('proxy.php')]);
        $invocation = self::proxy();
        $context = new InvocationAttempt($invocation);

        $result = $registry->invoke('Proxy', 'oneWayCall', json_encode([
            'serviceName' => 'Counter',
            'virtualObjectKey' => 'counter-1',
            'handlerName' => 'add',
            'message' => self::bytes('1'),
            'idempotencyKey' => 'idem-proxy-send',
        ], JSON_THROW_ON_ERROR), $context);

        self::assertSame('"inv-from-send"', $result);
        self::assertEquals([[new CallTarget(Target::object('Counter', 'counter-1', 'add')), '1', null, 'idem-proxy-send']], $invocation->calls('sysSend'));
    }

    public function testProxyManyCallsForwardsIdempotencyKeys(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('proxy.php')]);
        $invocation = self::proxy();
        $context = new InvocationAttempt($invocation);

        $registry->invoke('Proxy', 'manyCalls', json_encode([
            [
                'proxyRequest' => [
                    'serviceName' => 'Counter',
                    'virtualObjectKey' => 'counter-send',
                    'handlerName' => 'add',
                    'message' => self::bytes('1'),
                    'idempotencyKey' => 'idem-many-send',
                ],
                'oneWayCall' => true,
                'awaitAtTheEnd' => false,
            ],
            [
                'proxyRequest' => [
                    'serviceName' => 'Counter',
                    'virtualObjectKey' => 'counter-delay',
                    'handlerName' => 'add',
                    'message' => self::bytes('2'),
                    'delayMillis' => 250,
                    'idempotencyKey' => 'idem-many-delay',
                ],
                'oneWayCall' => true,
                'awaitAtTheEnd' => false,
            ],
            [
                'proxyRequest' => [
                    'serviceName' => 'Counter',
                    'virtualObjectKey' => 'counter-future',
                    'handlerName' => 'add',
                    'message' => self::bytes('3'),
                    'idempotencyKey' => 'idem-many-future',
                ],
                'oneWayCall' => false,
                'awaitAtTheEnd' => true,
            ],
            [
                'proxyRequest' => [
                    'serviceName' => 'Counter',
                    'virtualObjectKey' => 'counter-call',
                    'handlerName' => 'add',
                    'message' => self::bytes('4'),
                    'idempotencyKey' => 'idem-many-call',
                ],
                'oneWayCall' => false,
                'awaitAtTheEnd' => false,
            ],
        ], JSON_THROW_ON_ERROR), $context);

        self::assertEquals([
            [new CallTarget(Target::object('Counter', 'counter-send', 'add')), '1', null, 'idem-many-send'],
            [new CallTarget(Target::object('Counter', 'counter-delay', 'add')), '2', 250, 'idem-many-delay'],
        ], $invocation->calls('sysSend'));
        self::assertEquals([
            [new CallTarget(Target::object('Counter', 'counter-future', 'add')), '3', 'idem-many-future'],
            [new CallTarget(Target::object('Counter', 'counter-call', 'add')), '4', 'idem-many-call'],
        ], $invocation->calls('sysCall'));
    }

    public function testCommandInterpreterHasAwakeableReadsStoredMarker(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('virtual_object_command_interpreter.php')]);
        $invocation = self::stateful();
        $context = $this->statefulContext($invocation);

        self::assertSame('false', $registry->invoke('VirtualObjectCommandInterpreter', 'hasAwakeable', '"awk1"', $context, 'interpreter-1'));

        $invocation->state['awakeable:awk1'] = json_encode('awakeable-1', JSON_THROW_ON_ERROR);

        self::assertSame('true', $registry->invoke('VirtualObjectCommandInterpreter', 'hasAwakeable', '"awk1"', $context, 'interpreter-1'));
    }

    public function testCommandInterpreterResolveAndRejectAwakeablesUseStoredIds(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('virtual_object_command_interpreter.php')]);
        $invocation = self::stateful();
        $context = $this->statefulContext($invocation);

        $invocation->state['awakeable:awk1'] = json_encode('awakeable-1', JSON_THROW_ON_ERROR);
        $invocation->state['awakeable:awk2'] = json_encode('awakeable-2', JSON_THROW_ON_ERROR);

        $registry->invoke('VirtualObjectCommandInterpreter', 'resolveAwakeable', '{"awakeableKey":"awk1","value":"done"}', $context, 'interpreter-1');
        $registry->invoke('VirtualObjectCommandInterpreter', 'rejectAwakeable', '{"awakeableKey":"awk2","reason":"nope"}', $context, 'interpreter-1');

        self::assertSame([['awakeable-1', '"done"']], $invocation->calls('sysResolveAwakeable'));
        self::assertSame([['awakeable-2', 500, 'nope', []]], $invocation->calls('sysRejectAwakeable'));
    }

    public function testCommandInterpreterGetResultsReturnsStoredResults(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('virtual_object_command_interpreter.php')]);
        $invocation = self::stateful(['results' => json_encode(['sleep'], JSON_THROW_ON_ERROR)]);

        self::assertSame('["sleep"]', $registry->invoke('VirtualObjectCommandInterpreter', 'getResults', '', $this->statefulContext($invocation), 'interpreter-1'));
    }

    public function testCommandInterpreterAwaitAnyReturnsSleepAndStoresAwakeable(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('virtual_object_command_interpreter.php')]);
        $invocation = self::stateful();
        $context = $this->statefulContext($invocation);

        $result = $registry->invoke('VirtualObjectCommandInterpreter', 'interpretCommands', json_encode([
            'commands' => [[
                'type' => 'awaitAny',
                'commands' => [
                    ['type' => 'createAwakeable', 'awakeableKey' => 'awk1'],
                    ['type' => 'sleep', 'timeoutMillis' => 25],
                ],
            ]],
        ], JSON_THROW_ON_ERROR), $context, 'interpreter-1');

        self::assertSame('"sleep"', $result);
        self::assertSame([[25]], $invocation->calls('sysSleep'));
        self::assertSame('true', $registry->invoke('VirtualObjectCommandInterpreter', 'hasAwakeable', '"awk1"', $context, 'interpreter-1'));
    }

    public function testCommandInterpreterAwakeableOrTimeoutThrowsTerminalAwaitTimeout(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('virtual_object_command_interpreter.php')]);
        $invocation = self::stateful();

        try {
            $registry->invoke('VirtualObjectCommandInterpreter', 'interpretCommands', json_encode([
                'commands' => [[
                    'type' => 'awaitAwakeableOrTimeout',
                    'awakeableKey' => 'should-timeout-awk',
                    'timeoutMillis' => 25,
                ]],
            ], JSON_THROW_ON_ERROR), $this->statefulContext($invocation), 'interpreter-1');
            self::fail('Expected await timeout terminal failure.');
        } catch (TerminalError $e) {
            self::assertSame(500, $e->statusCode());
            self::assertStringContainsString('await-timeout', $e->getMessage());
        }

        self::assertSame([[25]], $invocation->calls('sysSleep'));
    }

    public function testCommandInterpreterAwaitAnySuccessfulReturnsFirstResolvedAwakeable(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('virtual_object_command_interpreter.php')]);
        $precreateInvocation = self::stateful();
        $precreateContext = $this->statefulContext($precreateInvocation);

        foreach (['awk0', 'awk1'] as $awakeableKey) {
            $registry->invoke('VirtualObjectCommandInterpreter', 'interpretCommands', json_encode([
                'commands' => [[
                    'type' => 'awaitAny',
                    'commands' => [
                        ['type' => 'createAwakeable', 'awakeableKey' => $awakeableKey],
                        ['type' => 'sleep', 'timeoutMillis' => 1],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR), $precreateContext, 'interpreter-1');
        }

        $awk0Marker = $precreateInvocation->state['awakeable:awk0'] ?? null;
        $awk1Marker = $precreateInvocation->state['awakeable:awk1'] ?? null;
        self::assertIsString($awk0Marker);
        self::assertIsString($awk1Marker);

        $invocation = self::stateful([
            'awakeable:awk0' => $awk0Marker,
            'awakeable:awk1' => $awk1Marker,
        ]);
        $context = $this->statefulContext($invocation);

        $invocation->onNext('sysAwakeable')->onNext('sysAwakeable', NotificationResult::success(json_encode('awk1-result', JSON_THROW_ON_ERROR)));

        $result = $registry->invoke('VirtualObjectCommandInterpreter', 'interpretCommands', json_encode([
            'commands' => [[
                'type' => 'awaitAnySuccessful',
                'commands' => [
                    ['type' => 'createAwakeable', 'awakeableKey' => 'awk0'],
                    ['type' => 'runThrowTerminalException', 'reason' => 'run0'],
                    ['type' => 'createAwakeable', 'awakeableKey' => 'awk1'],
                ],
            ]],
        ], JSON_THROW_ON_ERROR), $context, 'interpreter-1');

        self::assertSame('"awk1-result"', $result);
    }

    public function testCommandInterpreterAwaitAllSucceededJoinsResultsInCommandOrder(): void
    {
        $invocation = self::stateful();
        $invocation->onNext('sysAwakeable', NotificationResult::success(json_encode('awk-value', JSON_THROW_ON_ERROR)));

        $result = $this->interpret($invocation, [[
            'type' => 'awaitAllSucceededOrFirstFailed',
            'commands' => [
                ['type' => 'createAwakeable', 'awakeableKey' => 'awk0'],
                ['type' => 'runReturns', 'value' => 'run-value'],
                ['type' => 'sleep', 'timeoutMillis' => 5],
            ],
        ]]);

        self::assertSame('"awk-value|run-value|sleep"', $result);
        self::assertSame([['runReturns']], $invocation->calls('sysRun'));
    }

    public function testCommandInterpreterAwaitAllSucceededThrowsTheFailedRun(): void
    {
        $invocation = self::stateful();

        try {
            $this->interpret($invocation, [[
                'type' => 'awaitAllSucceededOrFirstFailed',
                'commands' => [
                    ['type' => 'createAwakeable', 'awakeableKey' => 'awk0'],
                    ['type' => 'runThrowTerminalException', 'reason' => 'run-failed'],
                ],
            ]]);
            self::fail('Expected the run failure.');
        } catch (TerminalError $e) {
            self::assertSame('run-failed', $e->getMessage());
        }

        self::assertSame([['runThrowTerminalException']], $invocation->calls('sysRun'));
    }

    public function testCommandInterpreterAwaitAllCompletedReportsEveryOutcomeInCommandOrder(): void
    {
        $invocation = self::stateful();
        $invocation->onNext('sysAwakeable', NotificationResult::failure(500, 'awk-rejected'));

        $result = $this->interpret($invocation, [[
            'type' => 'awaitAllCompleted',
            'commands' => [
                ['type' => 'createAwakeable', 'awakeableKey' => 'awk0'],
                ['type' => 'runThrowTerminalException', 'reason' => 'run-failed'],
                ['type' => 'runReturns', 'value' => 'run-value'],
                ['type' => 'sleep', 'timeoutMillis' => 5],
            ],
        ]]);

        self::assertSame('"err:awk-rejected|err:run-failed|ok:run-value|ok:sleep"', $result);
    }

    public function testCommandInterpreterAwaitFirstCompletedReturnsTheRunBeforeAPendingAwakeable(): void
    {
        $invocation = self::stateful();

        $result = $this->interpret($invocation, [[
            'type' => 'awaitFirstCompleted',
            'commands' => [
                ['type' => 'createAwakeable', 'awakeableKey' => 'awk0'],
                ['type' => 'runReturns', 'value' => 'run-value'],
            ],
        ]]);

        self::assertSame('"run-value"', $result);
    }

    public function testCommandInterpreterAwaitFirstSucceededFailsTerminallyWhenEverythingFailed(): void
    {
        $invocation = self::stateful();
        $invocation->onNext('sysAwakeable', NotificationResult::failure(500, 'awk-rejected'));

        try {
            $this->interpret($invocation, [[
                'type' => 'awaitFirstSucceededOrAllFailed',
                'commands' => [
                    ['type' => 'runThrowTerminalException', 'reason' => 'run-failed'],
                    ['type' => 'createAwakeable', 'awakeableKey' => 'awk0'],
                ],
            ]]);
            self::fail('Expected a terminal failure.');
        } catch (TerminalError $e) {
            self::assertSame('awk-rejected', $e->getMessage());
        }
    }

    public function testCommandInterpreterAwaitFirstSucceededMapsTheWinningSleep(): void
    {
        $invocation = self::stateful();
        $invocation->onNext('sysAwakeable', NotificationResult::failure(500, 'awk-rejected'));

        $result = $this->interpret($invocation, [[
            'type' => 'awaitFirstSucceededOrAllFailed',
            'commands' => [
                ['type' => 'createAwakeable', 'awakeableKey' => 'awk0'],
                ['type' => 'sleep', 'timeoutMillis' => 5],
            ],
        ]]);

        self::assertSame('"sleep"', $result);
    }

    public function testCommandInterpreterStoresEveryCommandResult(): void
    {
        $invocation = self::stateful();
        $invocation->state['awakeable:awk0'] = json_encode('awakeable-0', JSON_THROW_ON_ERROR);

        $result = $this->interpret($invocation, [
            ['type' => 'awaitOne', 'command' => ['type' => 'sleep', 'timeoutMillis' => 5]],
            ['type' => 'resolveAwakeable', 'awakeableKey' => 'awk0', 'value' => 'done'],
        ]);

        self::assertSame('""', $result);
        self::assertSame(json_encode(['sleep', ''], JSON_THROW_ON_ERROR), $invocation->state['results'] ?? null);
    }

    public function testCommandInterpreterAwaitsSignalsAsFutures(): void
    {
        $invocation = self::stateful();
        $invocation->onNext('sysSignal', NotificationResult::success('"val0"'));
        $invocation->onNext('sysSignal', NotificationResult::failure(500, 'err1'));

        $result = $this->interpret($invocation, [[
            'type' => 'awaitAllCompleted',
            'commands' => [
                ['type' => 'createSignal', 'signalName' => 'sig0'],
                ['type' => 'createSignal', 'signalName' => 'sig1'],
            ],
        ]]);

        self::assertSame('"ok:val0|err:err1"', $result);
        self::assertSame([['sig0'], ['sig1']], $invocation->calls('sysSignal'));
    }

    public function testTestUtilsServiceResolvesAndRejectsSignals(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('test_utils_service.php')]);
        $invocation = ScriptedInvocation::new();

        $registry->invoke('TestUtilsService', 'resolveSignal', '{"invocationId":"inv-1","signalName":"sig","value":"hello"}', new InvocationAttempt($invocation));
        $registry->invoke('TestUtilsService', 'rejectSignal', '{"invocationId":"inv-1","signalName":"sig","reason":"boom"}', new InvocationAttempt($invocation));

        self::assertSame([['inv-1', 'sig', '"hello"']], $invocation->calls('sysResolveSignal'));
        self::assertSame([['inv-1', 'sig', 500, 'boom', []]], $invocation->calls('sysRejectSignal'));
    }

    public function testProxyForwardsScopeAndLimitKey(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('proxy.php')]);
        $invocation = self::proxy();
        $scoped = static fn (string $handler, array $request): string => $registry->invoke('Proxy', $handler, json_encode([
            'serviceName' => 'BlockingProxy',
            'handlerName' => 'block',
            'message' => self::bytes('"blocker-1"'),
            'scope' => 's2s-scope',
            ...$request,
        ], JSON_THROW_ON_ERROR), new InvocationAttempt($invocation));

        $scoped('call', ['limitKey' => 's2s-limitkey']);
        $scoped('oneWayCall', ['limitKey' => 's2s-limitkey']);
        $scoped('oneWayCall', ['delayMillis' => 10]);
        $registry->invoke('Proxy', 'manyCalls', json_encode([[
            'proxyRequest' => ['serviceName' => 'BlockingProxy', 'handlerName' => 'block', 'message' => self::bytes('"b"'), 'scope' => 's2s-scope', 'limitKey' => 'k'],
            'oneWayCall' => true,
            'awaitAtTheEnd' => false,
        ]], JSON_THROW_ON_ERROR), new InvocationAttempt($invocation));

        $target = Target::service('BlockingProxy', 'block')->scoped('s2s-scope');
        self::assertEquals([[new CallTarget($target, limitKey: 's2s-limitkey'), '"blocker-1"', null]], $invocation->calls('sysCall'));
        self::assertEquals([
            [new CallTarget($target, limitKey: 's2s-limitkey'), '"blocker-1"', null, null],
            [new CallTarget($target), '"blocker-1"', 10, null],
            [new CallTarget($target, limitKey: 'k'), '"b"', null, null],
        ], $invocation->calls('sysSend'));
    }

    /** @param list<array<string, mixed>> $commands */
    private function interpret(ScriptedInvocation $invocation, array $commands): string
    {
        $registry = new ServiceRegistry([require self::fixturePath('virtual_object_command_interpreter.php')]);

        return $registry->invoke('VirtualObjectCommandInterpreter', 'interpretCommands', json_encode(['commands' => $commands], JSON_THROW_ON_ERROR), $this->statefulContext($invocation), 'interpreter-1');
    }

    public function testMapObjectSetAndGetStoredEntry(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('map_object.php')]);
        $invocation = self::stateful();
        $context = $this->statefulContext($invocation);

        $registry->invoke('MapObject', 'set', '{"key":"my-key","value":"my-value"}', $context, 'map-1');

        self::assertSame('"my-value"', $registry->invoke('MapObject', 'get', '"my-key"', $context, 'map-1'));
    }

    public function testMapObjectSetWritesEntriesVisibleToClearAll(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('map_object.php')]);
        $invocation = self::stateful();
        $context = $this->statefulContext($invocation);

        $registry->invoke('MapObject', 'set', '{"key":"my-key","value":"my-value"}', $context, 'map-1');

        self::assertSame(
            [['key' => 'my-key', 'value' => 'my-value']],
            json_decode($registry->invoke('MapObject', 'clearAll', '', $context, 'map-1'), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testMapObjectGetMissingKeyReturnsEmptyString(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('map_object.php')]);
        $invocation = self::stateful();

        self::assertSame('""', $registry->invoke('MapObject', 'get', '"missing"', $this->statefulContext($invocation), 'map-1'));
    }

    public function testMapObjectClearAllReturnsEntriesAndRemovesThem(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('map_object.php')]);
        $invocation = self::stateful([
            'entry:my-key-0' => json_encode('my-value-0', JSON_THROW_ON_ERROR),
            'entry:my-key-1' => json_encode('my-value-1', JSON_THROW_ON_ERROR),
        ]);
        $context = $this->statefulContext($invocation);

        $result = json_decode($registry->invoke('MapObject', 'clearAll', '', $context, 'map-1'), true, flags: JSON_THROW_ON_ERROR);

        usort($result, static fn (array $left, array $right): int => strcmp((string) $left['key'], (string) $right['key']));
        self::assertSame([
            ['key' => 'my-key-0', 'value' => 'my-value-0'],
            ['key' => 'my-key-1', 'value' => 'my-value-1'],
        ], $result);
        self::assertSame('""', $registry->invoke('MapObject', 'get', '"my-key-0"', $context, 'map-1'));
        self::assertSame('""', $registry->invoke('MapObject', 'get', '"my-key-1"', $context, 'map-1'));
    }

    public function testMapObjectSeparateBackendStateKeepsObjectStateIsolated(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('map_object.php')]);
        $first = self::stateful(['entry:shared-key' => json_encode('first', JSON_THROW_ON_ERROR)]);
        $second = self::stateful(['entry:shared-key' => json_encode('second', JSON_THROW_ON_ERROR)]);

        $registry->invoke('MapObject', 'clearAll', '', $this->statefulContext($first), 'map-1');

        self::assertSame('""', $registry->invoke('MapObject', 'get', '"shared-key"', $this->statefulContext($first), 'map-1'));
        self::assertSame('"second"', $registry->invoke('MapObject', 'get', '"shared-key"', $this->statefulContext($second), 'map-2'));
    }

    public function testListObjectAppendAndGetReturnsOrderedValues(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('list_object.php')]);
        $invocation = self::stateful();
        $context = $this->statefulContext($invocation);

        $registry->invoke('ListObject', 'append', '"0"', $context, 'list-1');
        $registry->invoke('ListObject', 'append', '"1"', $context, 'list-1');

        self::assertSame('["0","1"]', $registry->invoke('ListObject', 'get', '', $context, 'list-1'));
    }

    public function testListObjectGetMissingListReturnsEmptyArray(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('list_object.php')]);

        self::assertSame('[]', $registry->invoke('ListObject', 'get', '', $this->statefulContext(self::stateful()), 'list-1'));
    }

    public function testListObjectClearReturnsOrderedValuesAndClearsList(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('list_object.php')]);
        $invocation = self::stateful(['items' => json_encode(['0', '1', '2'], JSON_THROW_ON_ERROR)]);
        $context = $this->statefulContext($invocation);

        self::assertSame('["0","1","2"]', $registry->invoke('ListObject', 'clear', '', $context, 'list-1'));
        self::assertSame('[]', $registry->invoke('ListObject', 'get', '', $context, 'list-1'));
    }

    public function testListObjectOrderingShapeReturnsAppendedValuesOnClear(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('list_object.php')]);
        $invocation = self::stateful();
        $context = $this->statefulContext($invocation);

        foreach (['0', '1', '2'] as $value) {
            $registry->invoke('ListObject', 'append', json_encode($value, JSON_THROW_ON_ERROR), $context, 'list-1');
        }

        self::assertSame('["0","1","2"]', $registry->invoke('ListObject', 'clear', '', $context, 'list-1'));
    }

    public function testBlockAndWaitWorkflowRunStoresStateBeforeSuspending(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('block_and_wait_workflow.php')]);
        $invocation = self::workflow();
        $context = $this->statefulContext($invocation);

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('Promise get suspended.');

        try {
            $registry->invoke('BlockAndWaitWorkflow', 'run', '"Francesco"', $context, 'workflow-1');
        } finally {
            self::assertSame('"Francesco"', $invocation->state['state'] ?? null);
        }
    }

    public function testBlockAndWaitWorkflowRunReturnsResolvedOutput(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('block_and_wait_workflow.php')]);
        $invocation = self::workflow(['unblock' => '"Till"']);

        self::assertSame('"Till"', $registry->invoke('BlockAndWaitWorkflow', 'run', '"Francesco"', $this->statefulContext($invocation), 'workflow-1'));
    }

    public function testBlockAndWaitWorkflowSharedHandlersUseStateAndPromise(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('block_and_wait_workflow.php')]);
        $invocation = self::workflow();
        $invocation->state['state'] = '"Francesco"';
        $context = $this->statefulContext($invocation);

        self::assertSame('"Francesco"', $registry->invoke('BlockAndWaitWorkflow', 'getState', '', $context, 'workflow-1'));
        self::assertSame('', $registry->invoke('BlockAndWaitWorkflow', 'unblock', '"Till"', $context, 'workflow-1'));
        self::assertSame([['unblock', '"Till"']], $invocation->calls('sysResolvePromise'));
    }

    public function testCancelTestRunnerRecordsCancellationTerminalFailure(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('cancel_test_runner.php')]);
        $invocation = self::proxy();
        $invocation->onEvery('sysCall', NotificationResult::failure(409, 'Invocation cancelled'));
        $context = $this->statefulContext($invocation);

        self::assertSame('false', $registry->invoke('CancelTestRunner', 'verifyTest', '', $context, 'cancel-1'));
        try {
            $registry->invoke('CancelTestRunner', 'startTest', '"CALL"', $context, 'cancel-1');
            self::fail('Expected cancellation to be rethrown after recording state.');
        } catch (TerminalError $exception) {
            self::assertSame(409, $exception->statusCode());
        }
        self::assertSame('true', $registry->invoke('CancelTestRunner', 'verifyTest', '', $context, 'cancel-1'));
    }

    public function testCancelTestBlockingServiceRegistersAwakeableBeforeBlocking(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('cancel_test_blocking_service.php')]);
        $invocation = self::kill();
        $context = $this->statefulContext($invocation);

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('Awakeable suspended.');

        try {
            $registry->invoke('CancelTestBlockingService', 'block', '"CALL"', $context, 'cancel-1');
        } finally {
            self::assertEquals([
                [new CallTarget(Target::object('AwakeableHolder', 'cancel-1', 'hold')), '"awakeable-1"', null, null],
            ], $invocation->calls('sysSend'));
        }
    }

    public function testAwakeableHolderHasAwakeableReflectsHoldState(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('awakeable_holder.php')]);
        $invocation = self::stateful();
        $context = $this->statefulContext($invocation);

        self::assertSame('false', $registry->invoke('AwakeableHolder', 'hasAwakeable', '', $context, 'holder-1'));
        $registry->invoke('AwakeableHolder', 'hold', '"awakeable-1"', $context, 'holder-1');

        self::assertSame('true', $registry->invoke('AwakeableHolder', 'hasAwakeable', '', $context, 'holder-1'));
        self::assertSame('"awakeable-1"', $invocation->state['id'] ?? null);
        self::assertArrayNotHasKey('holder_id', $invocation->state);
    }

    public function testAwakeableHolderUnlockResolvesStoredAwakeableAndClearsId(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('awakeable_holder.php')]);
        $invocation = self::stateful(['id' => json_encode('awakeable-1', JSON_THROW_ON_ERROR)]);
        $context = $this->statefulContext($invocation);

        $registry->invoke('AwakeableHolder', 'unlock', '"cancel"', $context, 'holder-1');

        self::assertSame([['awakeable-1', '"cancel"']], $invocation->calls('sysResolveAwakeable'));
        self::assertArrayNotHasKey('id', $invocation->state);
        self::assertArrayNotHasKey('unlocked', $invocation->state);
    }

    public function testAwakeableHolderUnlockWithoutIdFailsTerminally(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('awakeable_holder.php')]);
        $invocation = self::stateful();
        $context = $this->statefulContext($invocation);

        $this->expectException(TerminalError::class);
        $this->expectExceptionMessage('No awakeable registered');

        $registry->invoke('AwakeableHolder', 'unlock', '"cancel"', $context, 'holder-1');
    }

    public function testKillTestSingletonRecursiveCallRegistersAwakeableAndSuspends(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('kill_test_singleton.php')]);
        $invocation = self::kill();
        $context = $this->statefulContext($invocation);

        $this->expectException(SuspensionException::class);
        $this->expectExceptionMessage('Awakeable suspended.');

        try {
            $registry->invoke('KillTestSingleton', 'recursiveCall', '', $context, 'kill-1');
        } finally {
            self::assertEquals([
                [new CallTarget(Target::object('AwakeableHolder', 'kill-1', 'hold')), '"awakeable-1"', null, null],
            ], $invocation->calls('sysSend'));
            self::assertEquals([
                ['awakeable', 'awakeable-1', $invocation->handles('sysAwakeable')[0]],
                ['send', new CallTarget(Target::object('AwakeableHolder', 'kill-1', 'hold')), '"awakeable-1"'],
                ['await', $invocation->handles('sysAwakeable')[0]],
            ], self::trace($invocation));
            self::assertArrayNotHasKey('locked', $invocation->state);
            self::assertArrayNotHasKey('unlocked', $invocation->state);
        }
    }

    public function testKillTestSingletonRecursiveCallCallsItselfAfterAwakeableResolves(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('kill_test_singleton.php')]);
        $invocation = self::kill();
        $invocation->onNext('sysAwakeable', NotificationResult::success(json_encode('cancel', JSON_THROW_ON_ERROR)));
        $context = $this->statefulContext($invocation);

        $registry->invoke('KillTestSingleton', 'recursiveCall', '', $context, 'kill-1');

        self::assertEquals([
            [new CallTarget(Target::object('AwakeableHolder', 'kill-1', 'hold')), '"awakeable-1"', null, null],
        ], $invocation->calls('sysSend'));
        self::assertEquals([
            [new CallTarget(Target::object('KillTestSingleton', 'kill-1', 'recursiveCall')), 'null', null],
        ], $invocation->calls('sysCall'));
        self::assertEquals([
            ['awakeable', 'awakeable-1', $invocation->handles('sysAwakeable')[0]],
            ['send', new CallTarget(Target::object('AwakeableHolder', 'kill-1', 'hold')), '"awakeable-1"'],
            ['await', $invocation->handles('sysAwakeable')[0]],
            ['call', new CallTarget(Target::object('KillTestSingleton', 'kill-1', 'recursiveCall')), 'null'],
            ['await', $invocation->handles('sysCall')[0]],
        ], self::trace($invocation));
    }

    public function testKillTestRunnerStartCallTreeCallsSingletonRecursively(): void
    {
        $registry = new ServiceRegistry([require self::fixturePath('kill_test_runner.php')]);
        $invocation = self::kill();
        $context = $this->statefulContext($invocation);

        $registry->invoke('KillTestRunner', 'startCallTree', '', $context, 'kill-1');

        self::assertEquals([
            [new CallTarget(Target::object('KillTestSingleton', 'kill-1', 'recursiveCall')), 'null', null],
        ], $invocation->calls('sysCall'));
        self::assertSame([], $invocation->calls('sysSend'));
        self::assertEquals([
            ['call', new CallTarget(Target::object('KillTestSingleton', 'kill-1', 'recursiveCall')), 'null'],
            ['await', $invocation->handles('sysCall')[0]],
        ], self::trace($invocation));
    }

    private static function fixturePath(string $file): string
    {
        return dirname(__DIR__, 3) . "/test-services/services/{$file}";
    }

    /** @param list<array{0: string, 1: string}> $headers */
    private function context(array $headers = []): InvocationAttempt
    {
        return new InvocationAttempt(ScriptedInvocation::unused(), headers: $headers);
    }

    private function statefulContext(ScriptedInvocation $invocation): InvocationAttempt
    {
        return new InvocationAttempt($invocation);
    }

    /** @param array<string, string> $state */
    private static function stateful(array $state = []): ScriptedInvocation
    {
        $invocation = ScriptedInvocation::new();
        $invocation->state = $state;

        return $invocation;
    }

    /** Calls return what Counter/add returns; sends report an invocation id. */
    private static function proxy(): ScriptedInvocation
    {
        return ScriptedInvocation::new()
            ->onEvery('sysCall', NotificationResult::success('{"oldValue":0,"newValue":1}'))
            ->onEvery('sysSend', NotificationResult::invocationId('inv-from-send'));
    }

    /** Calls complete without a value; awakeables never complete unless scripted. */
    private static function kill(): ScriptedInvocation
    {
        return ScriptedInvocation::new()->onEvery('sysCall', NotificationResult::void());
    }

    /** Calls return JSON null. */
    private static function nonDeterministic(): ScriptedInvocation
    {
        return ScriptedInvocation::new()->onEvery('sysCall', NotificationResult::success('null'));
    }

    /** @param array<string, string> $promises completed promises: key => value bytes */
    private static function workflow(array $promises = []): ScriptedInvocation
    {
        $invocation = ScriptedInvocation::new();
        $invocation->promises = array_map(NotificationResult::success(...), $promises);

        return $invocation;
    }

    /**
     * The awakeables, calls and sends, and the awaits of their handles, in order (state access left out).
     *
     * @return list<list<mixed>>
     */
    private static function trace(ScriptedInvocation $invocation): array
    {
        $traced = [];
        $handles = [];
        foreach ($invocation->journal() as [$operation, $arguments, $result]) {
            if ($operation === 'sysAwakeable') {
                $traced[] = ['awakeable', $result->id, $result->handle];
                $handles[] = $result->handle;
            } elseif ($operation === 'sysCall' || $operation === 'sysSend') {
                $traced[] = [$operation === 'sysCall' ? 'call' : 'send', $arguments[0], $arguments[1]];
                $handles[] = $result;
            } elseif ($operation === 'doAwait' && in_array($arguments[0], $handles, true)) {
                $traced[] = ['await', $arguments[0]];
            }
        }

        return $traced;
    }
}
