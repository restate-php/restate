<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Support\ProxiedTestCase;
use Restate\Tests\Support\Faults\Fault;
use Restate\Tests\Support\Faults\FaultPlan;
use Restate\Tests\Support\Faults\FaultProxy;

/**
 * Back-pressure: a front door that sheds load answers 503 or 429 with `Retry-After: 2` (the FaultProxy's `status` mode,
 * before PHP runs), or the endpoint's response is replaced by one (Fault::respond(), after the handler ran).
 * Restate treats 503 and 429 as rate limiting and waits for `Retry-After` instead of its retry policy's 100 ms
 * (`Chaos`), so the next attempt arrives at least about 2 s later; the lower bound is Restate's own delay, not the
 * machine's speed.
 */
#[Group('failure-nightly')]
#[Group('managed')]
final class BackPressureTest extends ProxiedTestCase
{
    /** @return iterable<string, array{int}> */
    public static function statuses(): iterable
    {
        yield '503 Service Unavailable' => [503];
        yield '429 Too Many Requests' => [429];
    }

    #[DataProvider('statuses')]
    public function testRestateWaitsForRetryAfterFromTheFrontDoor(int $status): void
    {
        self::proxy()->fault(FaultProxy::STATUS, milliseconds: $status);
        $id = self::sendInvocation('Chaos/steps', '{"runs":1}');

        self::assertSame('{"attempt":2,"steps":[1]}', self::attachInvocation($id, 30.0)->body);
        $connections = self::connectionsOf($id);
        self::assertSame("status-{$status}", $connections[0]['end']);
        self::assertNotNull($connections[0]['closed']);
        // The next attempt waited for Retry-After (2 s), not for the policy's 100 ms.
        self::assertGreaterThanOrEqual(1800, $connections[1]['accepted'] - $connections[0]['closed'], (string) json_encode($connections));
        self::assertCount(1, self::chaos()->effects($id, 'step-1'));
    }

    /** The endpoint's own response replaced by a 503 with Retry-After: the handler ran, so its run executes again. */
    public function testRestateWaitsForRetryAfterFromTheEndpoint(): void
    {
        $key = 'shed-' . bin2hex(random_bytes(6));
        self::writePlan(FaultPlan::forIdempotencyKey($key)->onAttempt(1, Fault::respond(503, ['retry-after' => '2'])));
        $id = self::sendInvocation('Chaos/steps', '{"runs":1}', ['idempotency-key' => $key]);

        self::assertSame('{"attempt":3,"steps":[2]}', self::attachInvocation($id, 30.0)->body);
        $attempts = self::chaos()->attempts($id);
        self::assertGreaterThanOrEqual(1800, $attempts[1]['at'] - $attempts[0]['at']);
        self::assertSame([1, 2], array_column(self::chaos()->effects($id, 'step-1'), 'attempt'));
    }

    /**
     * Shed six times in a row, more than the policy's 5 attempts: rate-limited attempts do not count towards the
     * invocation retry policy, so the invocation is not paused and completes once the front door lets it through.
     */
    public function testRateLimitedAttemptsDoNotCountTowardsTheRetryPolicy(): void
    {
        self::proxy()->fault(FaultProxy::STATUS, connections: 6, milliseconds: 503);
        $id = self::sendInvocation('Chaos/steps', '{"runs":1}');

        self::assertSame('{"attempt":2,"steps":[1]}', self::attachInvocation($id, 40.0)->body);
        self::assertSame(
            ['status', 'status', 'status', 'status', 'status', 'status', 'pass', 'pass'],
            array_column(self::connectionsOf($id), 'mode'),
        );
    }
}
