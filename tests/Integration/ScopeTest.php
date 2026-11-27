<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\IntegrationTestCase;

/**
 * Scope and limit key on calls, sends and attach from a handler, with the services in
 * tests/Integration/Services/scopes.php: the callee sees the scope and limit key it was called with, and Restate keeps
 * an object instance and an idempotent invocation per scope.
 *
 * The concurrency limit itself (a rule on `scope/limitKey` holding back invocations) is the conformance suite's
 * `ServiceToServiceScopeConcurrency`, which runs the test services' `Proxy` against the same SDK code.
 */
final class ScopeTest extends IntegrationTestCase
{
    public function testACallWithinAScopeCarriesTheScopeAndLimitKey(): void
    {
        $scope = self::scope();
        $callee = self::invokeJson('Scopes/call', ['scope' => $scope, 'limitKey' => 'eu/user-42']);

        self::assertSame($scope, $callee['scope']);
        self::assertSame('eu/user-42', $callee['limitKey']);
        self::assertIsString($callee['id']);
        self::assertSame(['scope' => $scope, 'limit_key' => 'eu/user-42'], self::invocationRow($callee['id'], 'scope', 'limit_key'));
    }

    public function testADelayedSendWithinAScopeCarriesTheScopeAndLimitKey(): void
    {
        $scope = self::scope();
        $callee = self::invokeJson('Scopes/send', ['scope' => $scope, 'limitKey' => 'user-7']);

        self::assertSame([$scope, 'user-7'], [$callee['scope'], $callee['limitKey']]);
    }

    public function testAnObjectKeyAndAnIdempotencyKeyNameOneInstancePerScope(): void
    {
        $scopes = [self::scope(), self::scope()];
        $key = 'counter-' . bin2hex(random_bytes(4));
        $result = self::invokeJson('Scopes/objectPerScope', ['scopes' => $scopes, 'key' => $key]);

        // Three instances of ScopedCounter/{key}, each with its own state; the idempotent `add-once` ran once per
        // scope, so adding 1000 in the first scope again returns the first result.
        self::assertSame([1, $scopes[0]], [$result['first']['count'], $result['first']['scope']]);
        self::assertSame([10, $scopes[1]], [$result['second']['count'], $result['second']['scope']]);
        self::assertSame([100, null], [$result['unscoped']['count'], $result['unscoped']['scope']]);
        self::assertSame($result['first'], $result['firstAgain']);
        self::assertSame('add-once', $result['first']['idempotencyKey']);
        self::assertCount(3, array_unique([$result['first']['id'], $result['second']['id'], $result['unscoped']['id']]));
    }

    public function testScopedIdempotentRequestsAndWorkflowRunsCanBeAttachedTo(): void
    {
        $scope = self::scope();
        $request = ['scope' => $scope, 'idempotencyKey' => 'once-' . bin2hex(random_bytes(4)), 'workflowKey' => 'run-' . bin2hex(random_bytes(4))];
        $result = self::invokeJson('Scopes/attach', $request);

        self::assertSame([$scope, $request['idempotencyKey']], [$result['idempotent']['scope'], $result['idempotent']['idempotencyKey']]);
        self::assertSame($scope, $result['workflow']['scope']);
    }

    private static function scope(): string
    {
        return 'tenant-' . bin2hex(random_bytes(6));
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function invokeJson(string $path, array $request): array
    {
        $result = json_decode(self::invoke($path, json_encode($request, JSON_THROW_ON_ERROR)), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        /** @var array<string, mixed> $result */
        return $result;
    }
}
