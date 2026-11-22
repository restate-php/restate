<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Restate\Ingress\IngressClient;
use Restate\Ingress\IngressException;
use Restate\Ingress\SendStatus;
use Restate\Target;
use Restate\TerminalError;
use Restate\Tests\Integration\Support\IntegrationTestCase;

/**
 * Every operation of the ingress client against the real Restate server, through a real PSR-18 client (Guzzle).
 * Targets: `IngressTargets` and `IngressAwakeables` (tests/Integration/Services/ingress.php), `Counter` and the
 * `Checkout` workflow (test-services/services/).
 */
final class IngressClientTest extends IntegrationTestCase
{
    public function testCallsServiceAndObjectHandlers(): void
    {
        $restate = self::client();
        $key = self::key('a/b c?');

        self::assertSame(['hello' => 'world'], $restate->serviceClient('IngressTargets')->request('echo', ['hello' => 'world'])->call());
        self::assertSame('no input', $restate->serviceClient('IngressTargets')->request('noInput')->call());
        self::assertSame(['oldValue' => 0, 'newValue' => 2], $restate->objectClient('Counter', $key)->request('add', 2)->call());
        self::assertSame(2, $restate->call(Target::object('Counter', $key, 'get')));
    }

    public function testSendsConnectionAndRequestHeaders(): void
    {
        $restate = self::client(['x-ingress-connection' => 'from connect']);

        self::assertSame(
            ['connection' => 'from connect', 'request' => 'from request'],
            $restate->serviceClient('IngressTargets')->request('headers')->header('x-ingress-request', 'from request')->call(),
        );
    }

    public function testAnIdempotentCallRunsOnceAndItsInvocationCanBeAttachedTo(): void
    {
        $restate = self::client();
        $key = self::key('idempotent');
        $request = $restate->objectClient('Counter', $key)->request('add', 5)->idempotencyKey('add-five');

        self::assertSame(['oldValue' => 0, 'newValue' => 5], $request->call());
        self::assertSame(['oldValue' => 0, 'newValue' => 5], $request->call());
        self::assertSame(5, $restate->objectClient('Counter', $key)->request('get')->call());

        $invocation = $restate->idempotentInvocation(Target::object('Counter', $key, 'add'), 'add-five');
        self::assertSame(['oldValue' => 0, 'newValue' => 5], $invocation->attach());
        self::assertSame(['oldValue' => 0, 'newValue' => 5], $invocation->output()->value());
    }

    public function testSendReturnsAnInvocationToAttachToAndAnIdempotentSendIsAcceptedOnce(): void
    {
        $restate = self::client();
        $request = $restate->serviceClient('IngressTargets')
            ->request('sleepThenEcho', ['sleepMillis' => 1000, 'value' => 'slept'])
            ->idempotencyKey(self::key('send'));

        $sent = $request->send();
        $again = $request->send();

        self::assertSame(SendStatus::Accepted, $sent->status());
        self::assertSame(SendStatus::PreviouslyAccepted, $again->status());
        self::assertSame($sent->invocationId(), $again->invocationId());
        self::assertFalse($sent->output()->isReady(), 'The invocation sleeps for 1 s; its output cannot be ready yet.');
        self::assertSame('slept', $sent->attach());
        self::assertSame('slept', $restate->invocation($sent->invocationId())->output()->value());
    }

    public function testAnInvocationSentWithoutIdempotencyKeyCanBeAttachedToAndReadAfterwards(): void
    {
        $restate = self::client();

        $sent = $restate->serviceClient('IngressTargets')->request('sleepThenEcho', ['sleepMillis' => 500, 'value' => 'plain'])->send();

        self::assertSame(SendStatus::Accepted, $sent->status());
        self::assertSame('plain', $restate->invocation($sent->invocationId())->attach());
        self::assertSame('plain', $restate->invocation($sent->invocationId())->output()->value());
    }

    public function testADelayedSendStartsAfterTheDelay(): void
    {
        $restate = self::client();
        $started = microtime(true);

        $sent = $restate->serviceClient('IngressTargets')->request('echo', 'delayed')->idempotencyKey(self::key('delayed'))->sendAfter(1500);

        self::assertFalse($sent->output()->isReady(), 'A send delayed by 1.5 s cannot have completed yet.');
        self::assertSame('delayed', $sent->attach());
        self::assertGreaterThanOrEqual(1.4, microtime(true) - $started);
    }

    public function testSubmitsAWorkflowCallsItsSharedHandlerAndReadsItsResult(): void
    {
        $restate = self::client();
        $workflow = $restate->workflowClient('Checkout', self::key('order'));

        $submission = $workflow->submit(['item' => 'book']);
        self::assertSame(SendStatus::Accepted, $submission->status());
        self::assertSame(SendStatus::PreviouslyAccepted, $workflow->submit(['item' => 'book'])->status());
        self::assertFalse($workflow->output()->isReady(), 'The workflow waits for its approval.');

        $workflow->request('approve', ['status' => 'approved'])->call();

        self::assertSame('approved', $workflow->attach());
        self::assertSame('approved', $workflow->output()->value());
        self::assertSame('approved', $submission->attach());
    }

    public function testResolvesAnAwakeable(): void
    {
        $restate = self::client();
        $key = self::key('resolve');
        $waiter = $restate->serviceClient('IngressTargets')->request('awaitAwakeable', $key)->send();

        $restate->resolveAwakeable(self::awakeableId($restate, $key), ['approved' => true]);

        self::assertSame(['value' => ['approved' => true]], $waiter->attach());
    }

    public function testRejectsAnAwakeable(): void
    {
        $restate = self::client();
        $key = self::key('reject');
        $waiter = $restate->serviceClient('IngressTargets')->request('awaitAwakeable', $key)->send();

        $restate->rejectAwakeable(self::awakeableId($restate, $key), 'denied by policy');

        $result = $waiter->attach();
        self::assertIsArray($result);
        self::assertSame('denied by policy', $result['rejected'] ?? null);
    }

    public function testAnInvocationsTerminalFailureIsThrownAsATerminalError(): void
    {
        $restate = self::client();
        $failure = ['message' => 'Card reported lost', 'code' => 402, 'metadata' => ['reason' => 'lost']];

        try {
            $restate->serviceClient('IngressTargets')->request('fail', $failure)->call();
            self::fail('Expected a TerminalError.');
        } catch (TerminalError $e) {
            self::assertSame(402, $e->code());
            self::assertSame('Card reported lost', $e->getMessage());
            self::assertSame(['reason' => 'lost'], $e->metadata());
        }

        $sent = $restate->serviceClient('IngressTargets')->request('fail', $failure)->idempotencyKey(self::key('fail'))->send();
        try {
            $sent->attach();
            self::fail('Expected a TerminalError.');
        } catch (TerminalError $e) {
            self::assertSame(402, $e->code());
            self::assertSame(['reason' => 'lost'], $e->metadata());
        }
    }

    public function testErrorsOfTheIngressAreIngressExceptions(): void
    {
        $restate = self::client();

        try {
            $restate->serviceClient('NoSuchService')->request('handler', 1)->call();
            self::fail('Expected an IngressException.');
        } catch (IngressException $e) {
            self::assertSame(404, $e->statusCode());
            self::assertSame('ingress', $e->errorSource());
            self::assertStringContainsString('NoSuchService', (string) $e->errorMessage());
        }

        try {
            $restate->invocation('not-an-invocation-id')->output();
            self::fail('Expected an IngressException.');
        } catch (IngressException $e) {
            self::assertSame(400, $e->statusCode());
            self::assertSame('ingress', $e->errorSource());
        }
    }

    /** Restate attributes "not found" on attach to the invocation, as for an attach from a handler. */
    public function testAttachingToAWorkflowThatWasNeverSubmittedFailsWithNotFound(): void
    {
        try {
            self::client()->workflowClient('Checkout', self::key('never-submitted'))->attach();
            self::fail('Expected a TerminalError.');
        } catch (TerminalError $e) {
            self::assertSame(404, $e->code());
        }
    }

    public function testATransportFailureIsAnIngressException(): void
    {
        $restate = IngressClient::connect('http://127.0.0.1:' . self::closedPort(), self::httpClient(), new HttpFactory());

        try {
            $restate->serviceClient('IngressTargets')->request('echo', 1)->call();
            self::fail('Expected an IngressException.');
        } catch (IngressException $e) {
            self::assertNull($e->statusCode());
        }
    }

    /** @param array<string, string> $headers */
    private static function client(array $headers = []): IngressClient
    {
        return IngressClient::connect(self::restate()->ingressUrl(), self::httpClient(), new HttpFactory(), headers: $headers);
    }

    private static function httpClient(): Client
    {
        // No proxy, also none from the environment: Restate listens on 127.0.0.1.
        return new Client(['timeout' => 30, 'proxy' => '']);
    }

    private static function key(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(6));
    }

    /** The awakeable id IngressTargets/awaitAwakeable handed to IngressAwakeables/{key} (40 x 0.25 s). */
    private static function awakeableId(IngressClient $restate, string $key): string
    {
        for ($i = 0; $i < 40; $i++) {
            $id = $restate->objectClient('IngressAwakeables', $key)->request('id')->call();
            if (is_string($id)) {
                return $id;
            }
            usleep(250_000);
        }

        self::fail("IngressAwakeables/{$key} never received an awakeable id.");
    }

    private static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
