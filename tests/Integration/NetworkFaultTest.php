<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Support\ProxiedTestCase;
use Restate\Tests\Support\Faults\FaultProxy;

/**
 * Network faults between Restate and the endpoint, through a FaultProxy (a connection reset before the
 * endpoint sees the request and halfway through the response, a blackhole, latency), and Restate frozen for 3 s with
 * an attempt in flight (`pauseRestate()`). What Restate 1.8.1 does is recorded in the assertions.
 *
 * Every case drives `ChaosObject/batch` (state, two sends to Counter, a run) where it can: the invocation completes
 * once the network heals, the counter is exactly 11 (no send duplicated) and the journal has one entry of each.
 */
#[Group('failure-nightly')]
final class NetworkFaultTest extends ProxiedTestCase
{
    public function testAResetBeforeTheEndpointIsRetriedWithNothingExecuted(): void
    {
        self::proxy()->fault(FaultProxy::RESET);
        [$id, $key] = self::batch();

        self::assertBatchCompleted($id, $key);
        $connections = self::connectionsOf($id);
        self::assertSame('reset', $connections[0]['end']);
        // The reset attempt never reached PHP: the run executed once, in the first attempt that did.
        self::assertCount(1, self::chaos()->effects($id, 'r'));
        self::assertCount(2, self::chaos()->attempts($id));
        self::assertCount(3, $connections);
    }

    public function testAResetHalfwayThroughTheResponseCommitsAtMostWhatArrivedWhole(): void
    {
        self::proxy()->fault(FaultProxy::RESET_RESPONSE);
        [$id, $key] = self::batch();

        self::assertBatchCompleted($id, $key);
        self::assertSame('reset-response', self::connectionsOf($id)[0]['end']);
        // The endpoint ran the whole first attempt; whether its run's completion arrived whole depends on where half
        // the first read fell. At least once either way, and only as often as completions were lost.
        $executions = count(self::chaos()->effects($id, 'r'));
        self::assertContains($executions, [1, 2]);
        self::assertCount($executions + 1, self::chaos()->attempts($id));
    }

    public function testLatencyBelowTheTimeoutsCostsNoRetry(): void
    {
        self::proxy()->fault(FaultProxy::LATENCY, connections: 3, milliseconds: 1000);
        [$id, $key] = self::batch();

        self::assertBatchCompleted($id, $key);
        self::assertCount(1, self::chaos()->effects($id, 'r'));
        self::assertCount(2, self::connectionsOf($id));
        self::assertSame(['latency', 'latency'], array_column(self::connectionsOf($id), 'mode'));
    }

    /**
     * A blackhole (the connection is accepted, nothing comes back) for 4 s, under a 1 s abort timeout: Restate does
     * not give up after 1 s; it notices only when the proxy closes the connection, and retries then. A blackhole that
     * never closed would hold the attempt until TCP gave up (see AbortTimeoutTest: the abort timeout only fires once
     * a response is coming).
     */
    public function testABlackholeHoldsTheAttemptUntilTheConnectionCloses(): void
    {
        self::proxy()->fault(FaultProxy::BLACKHOLE, milliseconds: 4000);
        $id = self::sendInvocation('ChaosObject/blackhole-' . bin2hex(random_bytes(6)) . '/abortable', '{}');

        self::assertSame('1', self::attachInvocation($id, 30.0)->body);
        $connections = self::connectionsOf($id);
        self::assertSame('blackhole', $connections[0]['end']);
        self::assertNotNull($connections[0]['closed']);
        self::assertGreaterThanOrEqual($connections[0]['closed'], $connections[1]['accepted'], (string) json_encode($connections));
        self::assertCount(1, self::chaos()->effects($id, 'abortable-start'));
    }

    /**
     * Restate frozen for 3 s (cgroup freezer) while a 1 s run is in flight: the endpoint's response waits in the
     * kernel, Restate reads it once thawed, and the run is not executed again.
     */
    public function testRestateFrozenWhileAnAttemptIsInFlight(): void
    {
        $id = self::sendInvocation('Chaos/slowRun', '{"ms":1000,"times":1}');
        $deadline = microtime(true) + 10;
        while (self::chaos()->effects($id, 'slow') === []) {
            self::assertLessThan($deadline, microtime(true));
            usleep(20_000);
        }

        self::restate()->pauseRestate();
        try {
            sleep(3);
        } finally {
            self::restate()->unpauseRestate();
        }

        self::assertSame('{"attempt":2,"execution":1}', self::attachInvocation($id, 30.0)->body);
        self::assertCount(1, self::chaos()->effects($id, 'slow'));
    }

    /** @return array{string, string} the invocation id and the object key */
    private static function batch(): array
    {
        $key = 'net-' . bin2hex(random_bytes(6));

        return [self::sendInvocation("ChaosObject/{$key}/batch", null), $key];
    }

    private static function assertBatchCompleted(string $id, string $key): void
    {
        $output = self::attachInvocation($id, 30.0);
        self::assertSame('"v1"', $output->body, "HTTP {$output->status}; proxy: " . (string) json_encode(self::proxy()->connectionsOf($id)));
        self::assertSame(['Command: SetState' => 1, 'Command: OneWayCall' => 2, 'Command: Run r' => 1], array_intersect_key(
            self::journalCounts($id),
            ['Command: SetState' => true, 'Command: OneWayCall' => true, 'Command: Run r' => true],
        ));
        self::waitForCounter($key, '11');
        self::assertCounterStays($key, '11');
    }
}
