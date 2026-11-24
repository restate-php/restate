<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Services\ChaosRecorder;
use Restate\Tests\Integration\Support\IntegrationEndpoint;
use Restate\Tests\Integration\Support\IntegrationTestCase;
use Restate\Testing\PhpCliServer;

/**
 * The chaos services (tests/Integration/Services/chaos.php) do what they say, each armed for one execution so the
 * retry succeeds: what the failure-injection and serving scenarios build on. On the shared endpoint, whose
 * `display_errors=0` / `log_errors=1` defaults (PhpCliServer) keep PHP's messages out of the responses and in the
 * endpoint log.
 */
final class ChaosServicesTest extends IntegrationTestCase
{
    public function testNoisyHandlersAnswerCleanlyAndTheirMessagesGoToTheEndpointLog(): void
    {
        $offset = self::restate()->endpointLogSize();

        self::assertInvocationReturns('["deprecated","warning"]', 'Chaos/noisy', '{"kinds":["deprecated","warning"]}');
        if (IntegrationEndpoint::get() instanceof PhpCliServer) {
            self::assertLogEventually($offset, 'Calling current() on an object is deprecated');
            self::assertLogEventually($offset, 'Chaos/noisy warns');
        }
    }

    public function testExitInAHandlerAndInARunAreRetried(): void
    {
        $handler = self::sendInvocation('Chaos/exitInHandler', '{"times":1}');
        self::assertSame('2', self::attachInvocation($handler)->body);

        $run = self::sendInvocation('Chaos/exitInRun', '{"times":1}');
        self::assertSame('2', self::attachInvocation($run)->body);
        self::assertSame([1, 2], array_column(self::recorder()->effects($run, 'exit'), 'attempt'));
    }

    public function testExhaustedMemoryAndTheTimeLimitAreRetried(): void
    {
        $offset = self::restate()->endpointLogSize();

        $memory = self::sendInvocation('Chaos/exhaustMemoryInRun', '{"times":1}');
        self::assertSame('2', self::attachInvocation($memory)->body);
        $handler = self::sendInvocation('Chaos/exhaustMemoryInHandler', '{"times":1}');
        self::assertSame('2', self::attachInvocation($handler)->body);
        $busy = self::sendInvocation('Chaos/busyRun', '{"ms":3000,"timeLimit":1,"times":1}');
        // Attempt 1 dies of the time limit, attempt 2 runs the busy step again, attempt 3 completes.
        self::assertSame('{"attempt":3,"execution":2}', self::attachInvocation($busy)->body);

        if (IntegrationEndpoint::get() instanceof PhpCliServer) {
            self::assertLogEventually($offset, 'Allowed memory size of');
            self::assertLogEventually($offset, 'Maximum execution time of 1 second exceeded');
        }
    }

    public function testSlowRunsAndTheObjectCount(): void
    {
        $slow = self::sendInvocation('Chaos/slowRun', '{"ms":200}');
        // The run ends the first attempt (request/response mode); the second replays it and completes.
        self::assertSame('{"attempt":2,"execution":1}', self::attachInvocation($slow)->body);
        self::assertCount(2, self::recorder()->attempts($slow));

        $key = 'chaos-' . bin2hex(random_bytes(4));
        self::assertInvocationReturns('1', "ChaosObject/{$key}/add", '{"ms":50}');
        $add = self::sendInvocation("ChaosObject/{$key}/add", '{"exitInRun":true,"times":1}');
        self::assertSame('2', self::attachInvocation($add)->body);
        self::assertInvocationReturns('2', "ChaosObject/{$key}/get", null);
    }

    public function testStepsRecordEveryExecutionByAttempt(): void
    {
        $id = self::sendInvocation('Chaos/steps', '{"runs":3}');

        // Each run ends its attempt (request/response mode): three runs, four attempts.
        self::assertSame('{"attempt":4,"steps":[1,1,1]}', self::attachInvocation($id)->body);
        self::assertCount(4, self::recorder()->attempts($id));
        foreach ([1, 2, 3] as $step) {
            self::assertSame([$step], array_column(self::recorder()->effects($id, "step-{$step}"), 'attempt'));
        }
    }

    private static function recorder(): ChaosRecorder
    {
        return new ChaosRecorder(self::stateDir());
    }

    private static function assertLogEventually(int $offset, string $needle): void
    {
        $deadline = microtime(true) + 5;
        do {
            if (str_contains(self::restate()->endpointLog($offset), $needle)) {
                self::assertStringContainsString($needle, self::restate()->endpointLog($offset));

                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail("The endpoint log does not mention \"{$needle}\":\n" . self::restate()->endpointLog($offset));
    }
}
