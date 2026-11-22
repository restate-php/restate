<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Ingress;

use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\Ingress\IngressClient;
use Restate\Target;

/**
 * Scope and limit key through the ingress: a scoped request goes to `/restate/scope/{scope}/call|send/…` with the limit
 * key in `x-restate-limit-key`; a scoped workflow run or idempotent invocation is found by the id Restate's
 * `/restate/lookup` computes.
 */
final class IngressScopeTest extends TestCase
{
    private RecordingHttpClient $http;

    #[\Override]
    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
    }

    public function testAScopedCallGoesToTheScopedRouteWithTheLimitKeyHeader(): void
    {
        $this->http->respondJson(200, '"Hello Mary"')->respondJson(200, '1');
        $scope = $this->client()->scope('tenant-1');

        self::assertSame('tenant-1', $scope->name());
        self::assertSame('Hello Mary', $scope->serviceClient('Greeter')->request('greet', 'Mary')->limitKey('eu/user-42')->call());
        $scope->objectClient('Counter', 'a b')->request('add', 1)->idempotencyKey('once')->call();

        [$greet, $add] = $this->http->requests;
        self::assertSame('http://restate:8080/restate/scope/tenant-1/call/Greeter/greet', (string) $greet->getUri());
        self::assertSame('eu/user-42', $greet->getHeaderLine('x-restate-limit-key'));
        self::assertSame('"Mary"', (string) $greet->getBody());
        self::assertSame('http://restate:8080/restate/scope/tenant-1/call/Counter/a%20b/add', (string) $add->getUri());
        self::assertFalse($add->hasHeader('x-restate-limit-key'));
        self::assertSame('once', $add->getHeaderLine('idempotency-key'));
    }

    public function testAScopedSendAndDelayedSendGoToTheScopedSendRoute(): void
    {
        $this->http
            ->respondJson(202, '{"invocationId":"inv_1a","status":"Accepted"}')
            ->respondJson(202, '{"invocationId":"inv_1b","status":"Accepted"}');
        $request = $this->client()->scope('tenant-1')->objectClient('Counter', 'alice')->request('add', 1)->limitKey('user');

        self::assertSame('inv_1a', $request->send()->invocationId());
        self::assertSame('inv_1b', $request->sendAfter(1500)->invocationId());

        [$send, $delayed] = $this->http->requests;
        self::assertSame('http://restate:8080/restate/scope/tenant-1/send/Counter/alice/add', (string) $send->getUri());
        self::assertSame('http://restate:8080/restate/scope/tenant-1/send/Counter/alice/add?delay=1500ms', (string) $delayed->getUri());
        self::assertSame('user', $delayed->getHeaderLine('x-restate-limit-key'));
    }

    public function testTheGenericMethodsTakeAScopedTarget(): void
    {
        $this->http->respondJson(200, '1')->respondJson(202, '{"invocationId":"inv_1a","status":"Accepted"}');
        $client = $this->client();
        $target = Target::service('Greeter', 'greet')->scoped('tenant-1');

        $client->call($target, 'Mary', limitKey: 'user');
        $client->send($target, 'Bob');

        [$call, $send] = $this->http->requests;
        self::assertSame('http://restate:8080/restate/scope/tenant-1/call/Greeter/greet', (string) $call->getUri());
        self::assertSame('user', $call->getHeaderLine('x-restate-limit-key'));
        self::assertSame('http://restate:8080/restate/scope/tenant-1/send/Greeter/greet', (string) $send->getUri());
    }

    public function testAScopedWorkflowIsSubmittedInTheScopeAndFoundByLookup(): void
    {
        $this->http
            ->respondJson(202, '{"invocationId":"inv_1wf","status":"Accepted"}')
            ->respondJson(200, '{"invocationId":"inv_1wf"}')
            ->respondJson(200, '"approved"')
            ->respondJson(200, '{"invocationId":"inv_1wf"}')
            ->respondJson(470, '{"code":470,"message":"not ready","source":"ingress"}');
        $workflow = $this->client()->scope('tenant-1')->workflowClient('Checkout', 'order 1');

        $workflow->submit(['item' => 'book']);
        self::assertSame('approved', $workflow->attach());
        self::assertFalse($workflow->output()->isReady());

        [$submit, $lookup, $attach, $lookupAgain, $output] = $this->http->requests;
        self::assertSame('http://restate:8080/restate/scope/tenant-1/send/Checkout/order%201/run', (string) $submit->getUri());
        self::assertSame('POST', $lookup->getMethod());
        self::assertSame('http://restate:8080/restate/lookup', (string) $lookup->getUri());
        self::assertSame('application/json', $lookup->getHeaderLine('content-type'));
        self::assertSame(
            ['target' => 'workflow', 'workflowName' => 'Checkout', 'workflowKey' => 'order 1', 'scope' => 'tenant-1'],
            json_decode((string) $lookup->getBody(), true),
        );
        self::assertSame('GET', $attach->getMethod());
        self::assertSame('http://restate:8080/restate/invocation/inv_1wf/attach', (string) $attach->getUri());
        self::assertSame((string) $lookup->getBody(), (string) $lookupAgain->getBody());
        self::assertSame('http://restate:8080/restate/invocation/inv_1wf/output', (string) $output->getUri());
    }

    public function testAScopedIdempotentInvocationIsFoundByLookup(): void
    {
        $this->http
            ->respondJson(200, '{"invocationId":"inv_1s"}')
            ->respondJson(200, '1')
            ->respondJson(200, '{"invocationId":"inv_1o"}')
            ->respondJson(200, '2');
        $client = $this->client();

        $client->idempotentInvocation(Target::service('Greeter', 'greet')->scoped('tenant-1'), 'once')->attach();
        $client->idempotentInvocation(Target::object('Counter', 'alice', 'add')->scoped('tenant-1'), 'twice')->output();

        [$serviceLookup, $serviceAttach, $objectLookup, $objectOutput] = $this->http->requests;
        self::assertSame(
            ['target' => 'idempotentInvocation', 'service' => 'Greeter', 'handler' => 'greet', 'idempotencyKey' => 'once', 'scope' => 'tenant-1'],
            json_decode((string) $serviceLookup->getBody(), true),
        );
        self::assertSame('http://restate:8080/restate/invocation/inv_1s/attach', (string) $serviceAttach->getUri());
        self::assertSame(
            ['target' => 'idempotentInvocation', 'service' => 'Counter', 'key' => 'alice', 'handler' => 'add', 'idempotencyKey' => 'twice', 'scope' => 'tenant-1'],
            json_decode((string) $objectLookup->getBody(), true),
        );
        self::assertSame('http://restate:8080/restate/invocation/inv_1o/output', (string) $objectOutput->getUri());
    }

    public function testUnscopedTargetsKeepTheirRoutes(): void
    {
        $this->http->respondJson(200, '1')->respondJson(200, '2');
        $client = $this->client();

        $client->workflowInvocation('Checkout', 'order-1')->attach();
        $client->idempotentInvocation(Target::service('Greeter', 'greet'), 'once')->attach();

        [$workflow, $idempotent] = $this->http->requests;
        self::assertSame('http://restate:8080/restate/workflow/Checkout/order-1/attach', (string) $workflow->getUri());
        self::assertSame('http://restate:8080/restate/invocation/Greeter/greet/once/attach', (string) $idempotent->getUri());
    }

    public function testALimitKeyNeedsAScopeAndAValidFormat(): void
    {
        $client = $this->client();

        foreach ([
            ['The limit key "user" needs a scoped target', static fn () => $client->serviceClient('Greeter')->request('greet')->limitKey('user')],
            ['The limit key "user" needs a scoped target', static fn () => $client->call(Target::service('Greeter', 'greet'), limitKey: 'user')],
            ['a limit key is one or two levels', static fn () => $client->scope('tenant-1')->serviceClient('Greeter')->request('greet')->limitKey('a/b/c')],
            ['a scope is 1 to 36 characters', static fn () => $client->scope('tenant/1')],
            ['a scope is 1 to 36 characters', static fn () => $client->workflowInvocation('Checkout', 'order-1', scope: '')],
        ] as [$message, $operation]) {
            try {
                $operation();
                self::fail("Accepted: {$message}");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        self::assertSame([], $this->http->requests);
    }

    private function client(): IngressClient
    {
        return IngressClient::connect('http://restate:8080', $this->http, new HttpFactory());
    }
}
