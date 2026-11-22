<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Restate\Ingress\IngressClient;
use Restate\Ingress\IngressException;
use Restate\Target;
use Restate\Tests\Integration\Support\IntegrationTestCase;

/**
 * Scope and limit key through the ingress client against the real Restate server, with the services in
 * tests/Integration/Services/scopes.php: the scoped routes, the limit key header, and attaching to a scoped workflow
 * run or idempotent invocation through the id `/restate/lookup` computes. Restate 1.7 refuses scoped requests (its
 * vqueues are experimental and off by default).
 */
final class IngressScopeTest extends IntegrationTestCase
{
    public function testAScopedCallAndSendCarryTheScopeAndLimitKey(): void
    {
        $scope = self::scope();
        $info = self::client()->scope($scope)->serviceClient('ScopeInfo');
        if (!self::supportsScope()) {
            self::assertRefused(static fn () => $info->request('describe', 'hello')->limitKey('eu/user-42')->call());

            return;
        }

        $called = $info->request('describe', 'hello')->limitKey('eu/user-42')->call();
        $sent = $info->request('describe', 'hello')->limitKey('user-7')->sendAfter(10)->attach();

        self::assertIsArray($called);
        self::assertSame([$scope, 'eu/user-42'], [$called['scope'], $called['limitKey']]);
        self::assertIsArray($sent);
        self::assertSame([$scope, 'user-7'], [$sent['scope'], $sent['limitKey']]);
    }

    public function testAScopedWorkflowRunIsFoundInItsScope(): void
    {
        $scope = self::scope();
        $key = 'run-' . bin2hex(random_bytes(4));
        $restate = self::client();
        $workflow = $restate->scope($scope)->workflowClient('ScopeWorkflow', $key);
        if (!self::supportsScope()) {
            self::assertRefused(static fn () => $workflow->submit('go'));

            return;
        }

        $submission = $workflow->submit('go');
        $result = $workflow->attach();

        self::assertIsArray($result);
        self::assertSame($scope, $result['scope']);
        self::assertSame($submission->invocationId(), $result['id']);
        self::assertSame($result, $workflow->output()->value());
        self::assertSame($result, $restate->workflowInvocation('ScopeWorkflow', $key, scope: $scope)->attach());
    }

    public function testScopedIdempotentInvocationsAreFoundInTheirScope(): void
    {
        $scope = self::scope();
        $key = 'counter-' . bin2hex(random_bytes(4));
        $restate = self::client();
        $counter = Target::object('ScopedCounter', $key, 'add')->scoped($scope);
        $info = Target::service('ScopeInfo', 'describe')->scoped($scope);
        if (!self::supportsScope()) {
            self::assertRefused(static fn () => $restate->send($counter, 5, 'add-five'));

            return;
        }

        $added = $restate->send($counter, 5, 'add-five')->attach();
        $described = $restate->send($info, 'hello', 'describe-once')->attach();

        self::assertIsArray($added);
        self::assertSame([5, $scope], [$added['count'], $added['scope']]);
        self::assertSame($added, $restate->idempotentInvocation($counter, 'add-five')->attach());
        self::assertSame($described, $restate->idempotentInvocation($info, 'describe-once')->output()->value());
    }

    private static function supportsScope(): bool
    {
        return version_compare(self::restate()->serverVersion(), '1.8.0', '>=');
    }

    /** Restate 1.7 refuses a scoped request at the ingress, unless its experimental vqueues are enabled. */
    private static function assertRefused(\Closure $request): void
    {
        try {
            $request();
            self::fail('Restate 1.7 accepted a scoped request.');
        } catch (IngressException $e) {
            self::assertSame(400, $e->statusCode());
            self::assertSame('scoped invocations require vqueues to be enabled', $e->errorMessage());
        }
    }

    private static function scope(): string
    {
        return 'tenant-' . bin2hex(random_bytes(6));
    }

    private static function client(): IngressClient
    {
        // No proxy, also none from the environment: Restate listens on 127.0.0.1.
        return IngressClient::connect(self::restate()->ingressUrl(), new Client(['timeout' => 30, 'proxy' => '']), new HttpFactory());
    }
}
