<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Support\FaultInjectionTestCase;

/**
 * Restate restarts (graceful `stop`, or SIGKILL) while an attempt is in flight, and while a virtual
 * object's lock is held with another invocation queued behind it. Restate keeps its storage and ports across the
 * restart (RestateTestEnvironment::restartRestate()), so nothing is registered again.
 *
 * The endpoint answers an attempt only once it is over, so whatever the attempt did before Restate went away never
 * reached it: the run executes again after the restart, and the journaled result is the second execution's. The PHP
 * worker meanwhile finishes the stale attempt and writes into a closed connection. The conformance suite's KillRuntime
 * and StopRuntime tests cover a restart with nothing in flight.
 */
#[Group('failure-nightly')]
#[Group('managed')]
final class RestateRestartTest extends FaultInjectionTestCase
{
    /** @return iterable<string, array{bool}> */
    public static function restarts(): iterable
    {
        yield 'graceful stop' => [true];
        yield 'SIGKILL' => [false];
    }

    #[DataProvider('restarts')]
    public function testARunInFlightExecutesAgainAfterTheRestart(bool $graceful): void
    {
        $id = self::sendInvocation('Chaos/slowRun', '{"ms":4000,"times":1}');
        self::waitForEffect($id, 'slow', 1);

        self::restate()->restartRestate(graceful: $graceful);

        $output = self::attachInvocation($id, 40.0);
        self::assertTrue($output->isSuccessful(), "Attach failed: HTTP {$output->status} {$output->body}");
        self::assertSame('{"attempt":3,"execution":2}', $output->body);
        self::assertSame([1, 2], array_column(self::chaos()->effects($id, 'slow'), 'attempt'));
        self::assertSame(1, self::journalCounts($id)['Command: Run slow'] ?? 0);
        self::assertRegisteredOnce();
        self::assertEndpointServes(3);
    }

    #[DataProvider('restarts')]
    public function testTheObjectLockAndItsQueueSurviveTheRestart(bool $graceful): void
    {
        $key = 'restart-' . bin2hex(random_bytes(6));
        $holder = self::sendInvocation("ChaosObject/{$key}/add", '{"ms":4000,"times":1}');
        $queued = self::sendInvocation("ChaosObject/{$key}/add", '{}');
        self::waitForEffect($holder, 'add', 1);

        self::restate()->restartRestate(graceful: $graceful);

        // The lock holder's run executes again; the queued invocation runs only after it, and sees its count.
        self::assertSame('1', self::attachInvocation($holder, 40.0)->body);
        self::assertSame('2', self::attachInvocation($queued, 40.0)->body);
        $holderRuns = self::chaos()->effects($holder, 'add');
        self::assertSame([1, 2], array_column($holderRuns, 'attempt'));
        $queuedRuns = self::chaos()->effects($queued, 'add');
        self::assertCount(1, $queuedRuns);
        self::assertGreaterThanOrEqual($holderRuns[1]['at'], $queuedRuns[0]['at']);
        self::assertInvocationReturns('2', "ChaosObject/{$key}/get", null);
    }

    private static function waitForEffect(string $id, string $name, int $count): void
    {
        $deadline = microtime(true) + 10;
        while (count(self::chaos()->effects($id, $name)) < $count) {
            self::assertLessThan($deadline, microtime(true), "{$name} of {$id} did not execute {$count} times.");
            usleep(50_000);
        }
    }

    private static function assertRegisteredOnce(): void
    {
        $rows = self::restate()->query('SELECT id FROM sys_deployment');
        self::assertCount(1, $rows, 'Deployments after the restart: ' . json_encode($rows));
    }
}
