<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\Internal\Discovery;
use Restate\Internal\ServiceRegistry;
use Restate\Tests\Support\FakeProtocolVersions;

/**
 * Discovery manifests of the services in test-services/, which the upstream SDK test suite (restatedev/e2e sdk-tests) drives.
 *
 * These run in-process through Discovery::manifestJson(); tests/Integration/DiscoveryTest.php checks /discover over HTTP.
 * testDiscoveryListsEveryServiceWithItsHandlersAndTheirTypes() covers which handlers each service has; the other tests
 * check the input and output payload shapes of individual handlers.
 */
final class TestServicesDiscoveryTest extends TestCase
{
    public function testProxyTestUtilsAndFailingHandlersDeclareTheirPayloadShapes(): void
    {
        $handlers = self::handlers('proxy', 'test_utils_service', 'failing');

        self::assertSame(['required' => false], $handlers['Failing']['failingCallWithEventualSuccess']['input']);
        self::assertSame(['required' => false], $handlers['TestUtilsService']['echoHeaders']['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $handlers['TestUtilsService']['echoHeaders']['output']);
        self::assertSame(['required' => false, 'contentType' => 'application/octet-stream'], $handlers['TestUtilsService']['rawEcho']['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/octet-stream'], $handlers['TestUtilsService']['rawEcho']['output']);
    }

    public function testCounterHandlersDeclareTheirPayloadShapes(): void
    {
        $handlers = self::handlers('counter')['Counter'];

        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'integer']], $handlers['add']['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $handlers['add']['output']);
        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'integer']], $handlers['addThenFail']['input']);
        self::assertSame(['setContentTypeIfEmpty' => false], $handlers['addThenFail']['output']);
        self::assertSame(['required' => false], $handlers['get']['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'integer']], $handlers['get']['output']);
        self::assertSame(['required' => false], $handlers['reset']['input']);
        self::assertSame(['setContentTypeIfEmpty' => false], $handlers['reset']['output']);
    }

    public function testVirtualObjectCommandInterpreterHandlersDeclareTheirPayloadShapes(): void
    {
        $handlers = self::handlers('virtual_object_command_interpreter')['VirtualObjectCommandInterpreter'];

        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $handlers['interpretCommands']['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']], $handlers['interpretCommands']['output']);
        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $handlers['resolveAwakeable']['input']);
        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $handlers['rejectAwakeable']['input']);
        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']], $handlers['hasAwakeable']['input']);
        self::assertSame(['required' => false], $handlers['getResults']['input'], 'getResults takes no input: the suite calls it without a body');
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $handlers['getResults']['output']);
    }

    public function testMapObjectHandlersDeclareTheirPayloadShapes(): void
    {
        $handlers = self::handlers('map_object')['MapObject'];

        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $handlers['set']['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'null']], $handlers['set']['output']);
        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']], $handlers['get']['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']], $handlers['get']['output']);
        self::assertSame(['required' => false], $handlers['clearAll']['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $handlers['clearAll']['output']);
    }

    public function testListObjectHandlersDeclareTheirPayloadShapes(): void
    {
        $handlers = self::handlers('list_object')['ListObject'];

        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']], $handlers['append']['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'null']], $handlers['append']['output']);
        self::assertSame(['required' => false], $handlers['get']['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $handlers['get']['output']);
        self::assertSame(['required' => false], $handlers['clear']['input']);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true], $handlers['clear']['output']);
    }

    public function testKillInvocationHandlersDeclareTheirPayloadShapes(): void
    {
        $handlers = self::handlers('awakeable_holder', 'kill_test_runner', 'kill_test_singleton');

        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']], $handlers['AwakeableHolder']['hold']['input']);
        self::assertSame(['required' => false], $handlers['AwakeableHolder']['hasAwakeable']['input']);
        self::assertSame(['required' => false, 'contentType' => 'application/json', 'jsonSchema' => ['type' => 'string']], $handlers['AwakeableHolder']['unlock']['input']);
        self::assertSame(['required' => false], $handlers['KillTestRunner']['startCallTree']['input']);
        self::assertSame(['required' => false], $handlers['KillTestSingleton']['recursiveCall']['input']);
        self::assertSame(['required' => false], $handlers['KillTestSingleton']['isUnlocked']['input']);
    }

    public function testDiscoveryListsEveryServiceWithItsHandlersAndTheirTypes(): void
    {
        $manifest = self::manifest(
            'greeter',
            'communicator',
            'proxy',
            'test_utils_service',
            'failing',
            'virtual_object_command_interpreter',
            'map_object',
            'list_object',
            'awakeable_holder',
            'kill_test_runner',
            'kill_test_singleton',
            'block_and_wait_workflow',
            'cancel_test_runner',
            'cancel_test_blocking_service',
            'echo_workflow',
            'payments',
            'counter',
            'non_deterministic',
            'checkout',
        );

        $matrix = [];
        foreach ($manifest['services'] as $service) {
            $matrix[$service['name']] = [
                'ty' => $service['ty'],
                'handlers' => array_map(
                    static fn (array $handler): array => ['name' => $handler['name'], 'ty' => $handler['ty'] ?? null],
                    $service['handlers'],
                ),
            ];
        }

        self::assertSame([
            'Greeter' => [
                'ty' => 'SERVICE',
                'handlers' => [
                    ['name' => 'greet', 'ty' => null],
                    ['name' => 'greetWithRun', 'ty' => null],
                    ['name' => 'greetWithRetryRun', 'ty' => null],
                    ['name' => 'schemaEcho', 'ty' => null],
                ],
            ],
            'Communicator' => [
                'ty' => 'SERVICE',
                'handlers' => [
                    ['name' => 'callGreeter', 'ty' => null],
                    ['name' => 'callCounter', 'ty' => null],
                    ['name' => 'callWorkflow', 'ty' => null],
                    ['name' => 'sleep', 'ty' => null],
                    ['name' => 'sleepCatchingException', 'ty' => null],
                    ['name' => 'awaitAwakeable', 'ty' => null],
                    ['name' => 'resolveAwakeable', 'ty' => null],
                    ['name' => 'awaitRejectedAwakeable', 'ty' => null],
                    ['name' => 'rejectAwakeable', 'ty' => null],
                    ['name' => 'attachWorkflowOutput', 'ty' => null],
                    ['name' => 'attachCheckoutOutput', 'ty' => null],
                    ['name' => 'knownInvocationOutput', 'ty' => null],
                    ['name' => 'selectCallWins', 'ty' => null],
                    ['name' => 'futureRaceCallWins', 'ty' => null],
                    ['name' => 'selectSleepWins', 'ty' => null],
                    ['name' => 'combineAwakeables', 'ty' => null],
                    ['name' => 'unorderedSleeps', 'ty' => null],
                    ['name' => 'unorderedSleepsFromCollection', 'ty' => null],
                    ['name' => 'randomValues', 'ty' => null],
                    ['name' => 'cancelDelayedCounter', 'ty' => null],
                    ['name' => 'cancelInvocation', 'ty' => null],
                    ['name' => 'sendCounter', 'ty' => null],
                    ['name' => 'sendCounterDelayed', 'ty' => null],
                ],
            ],
            'Proxy' => [
                'ty' => 'SERVICE',
                'handlers' => [
                    ['name' => 'call', 'ty' => null],
                    ['name' => 'oneWayCall', 'ty' => null],
                    ['name' => 'manyCalls', 'ty' => null],
                ],
            ],
            'TestUtilsService' => [
                'ty' => 'SERVICE',
                'handlers' => [
                    ['name' => 'echo', 'ty' => null],
                    ['name' => 'uppercaseEcho', 'ty' => null],
                    ['name' => 'echoHeaders', 'ty' => null],
                    ['name' => 'rawEcho', 'ty' => null],
                    ['name' => 'countExecutedSideEffects', 'ty' => null],
                    ['name' => 'cancelInvocation', 'ty' => null],
                    ['name' => 'resolveSignal', 'ty' => null],
                    ['name' => 'rejectSignal', 'ty' => null],
                ],
            ],
            'Failing' => [
                'ty' => 'VIRTUAL_OBJECT',
                'handlers' => [
                    ['name' => 'terminallyFailingCall', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'callTerminallyFailingCall', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'failingCallWithEventualSuccess', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'terminallyFailingSideEffect', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'sideEffectSucceedsAfterGivenAttempts', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'sideEffectFailsAfterGivenAttempts', 'ty' => 'EXCLUSIVE'],
                ],
            ],
            'VirtualObjectCommandInterpreter' => [
                'ty' => 'VIRTUAL_OBJECT',
                'handlers' => [
                    ['name' => 'interpretCommands', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'resolveAwakeable', 'ty' => 'SHARED'],
                    ['name' => 'rejectAwakeable', 'ty' => 'SHARED'],
                    ['name' => 'hasAwakeable', 'ty' => 'SHARED'],
                    ['name' => 'getResults', 'ty' => 'SHARED'],
                ],
            ],
            'MapObject' => [
                'ty' => 'VIRTUAL_OBJECT',
                'handlers' => [
                    ['name' => 'set', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'get', 'ty' => 'SHARED'],
                    ['name' => 'clearAll', 'ty' => 'EXCLUSIVE'],
                ],
            ],
            'ListObject' => [
                'ty' => 'VIRTUAL_OBJECT',
                'handlers' => [
                    ['name' => 'append', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'get', 'ty' => 'SHARED'],
                    ['name' => 'clear', 'ty' => 'EXCLUSIVE'],
                ],
            ],
            'AwakeableHolder' => [
                'ty' => 'VIRTUAL_OBJECT',
                'handlers' => [
                    ['name' => 'hold', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'hasAwakeable', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'unlock', 'ty' => 'EXCLUSIVE'],
                ],
            ],
            'KillTestRunner' => [
                'ty' => 'VIRTUAL_OBJECT',
                'handlers' => [
                    ['name' => 'startCallTree', 'ty' => 'EXCLUSIVE'],
                ],
            ],
            'KillTestSingleton' => [
                'ty' => 'VIRTUAL_OBJECT',
                'handlers' => [
                    ['name' => 'recursiveCall', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'isUnlocked', 'ty' => 'EXCLUSIVE'],
                ],
            ],
            'BlockAndWaitWorkflow' => [
                'ty' => 'WORKFLOW',
                'handlers' => [
                    ['name' => 'run', 'ty' => 'WORKFLOW'],
                    ['name' => 'unblock', 'ty' => 'SHARED'],
                    ['name' => 'reject', 'ty' => 'SHARED'],
                    ['name' => 'getState', 'ty' => 'SHARED'],
                ],
            ],
            'CancelTestRunner' => [
                'ty' => 'VIRTUAL_OBJECT',
                'handlers' => [
                    ['name' => 'startTest', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'verifyTest', 'ty' => 'EXCLUSIVE'],
                ],
            ],
            'CancelTestBlockingService' => [
                'ty' => 'VIRTUAL_OBJECT',
                'handlers' => [
                    ['name' => 'block', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'isUnlocked', 'ty' => 'EXCLUSIVE'],
                ],
            ],
            'EchoWorkflow' => [
                'ty' => 'WORKFLOW',
                'handlers' => [
                    ['name' => 'run', 'ty' => 'WORKFLOW'],
                ],
            ],
            'Payments' => [
                'ty' => 'SERVICE',
                'handlers' => [
                    ['name' => 'charge', 'ty' => null],
                    ['name' => 'declineWithMetadata', 'ty' => null],
                    ['name' => 'reportDecline', 'ty' => null],
                    ['name' => 'declineInLatin1', 'ty' => null],
                    ['name' => 'reportLatin1Decline', 'ty' => null],
                ],
            ],
            'Counter' => [
                'ty' => 'VIRTUAL_OBJECT',
                'handlers' => [
                    ['name' => 'add', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'addThenFail', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'get', 'ty' => 'SHARED'],
                    ['name' => 'reset', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'keys', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'clearKey', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'clearAll', 'ty' => 'EXCLUSIVE'],
                ],
            ],
            'NonDeterministic' => [
                'ty' => 'VIRTUAL_OBJECT',
                'handlers' => [
                    ['name' => 'eitherSleepOrCall', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'callDifferentMethod', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'backgroundInvokeWithDifferentTargets', 'ty' => 'EXCLUSIVE'],
                    ['name' => 'setDifferentKey', 'ty' => 'EXCLUSIVE'],
                ],
            ],
            'Checkout' => [
                'ty' => 'WORKFLOW',
                'handlers' => [
                    ['name' => 'run', 'ty' => 'WORKFLOW'],
                    ['name' => 'approve', 'ty' => 'SHARED'],
                    ['name' => 'peekApproval', 'ty' => 'SHARED'],
                    ['name' => 'peekThenApprove', 'ty' => 'SHARED'],
                ],
            ],
        ], $matrix);
    }

    /**
     * The manifest's handlers, by service name and handler name.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function handlers(string ...$services): array
    {
        $handlers = [];
        foreach (self::manifest(...$services)['services'] as $service) {
            /** @var list<array<string, mixed>> $serviceHandlers */
            $serviceHandlers = $service['handlers'];
            /** @var string $name */
            $name = $service['name'];
            $handlers[$name] = array_column($serviceHandlers, null, 'name');
        }

        return $handlers;
    }

    /**
     * @return array{services: list<array<string, mixed>>}
     */
    private static function manifest(string ...$services): array
    {
        return json_decode(
            Discovery::manifestJson(new ServiceRegistry(array_map(
                static fn (string $service): mixed => require dirname(__DIR__, 3) . "/test-services/services/{$service}.php",
                $services,
            )), FakeProtocolVersions::RANGE),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
