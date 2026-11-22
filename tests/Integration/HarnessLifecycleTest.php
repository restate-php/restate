<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\FaultInjectionTestCase;
use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\HttpTransportException;

/**
 * The harness's lifecycle controls against a real Restate, once each: restarting Restate (gracefully and with
 * SIGKILL), pausing it, and killing, signalling and restarting the endpoint. Runs in the fault-injection environment,
 * so the one every other test shares is never restarted.
 */
final class HarnessLifecycleTest extends FaultInjectionTestCase
{
    /** A test that failed half-way must not leave the next one without an endpoint. */
    #[\Override]
    protected function tearDown(): void
    {
        if (!self::restate()->isEndpointRunning()) {
            self::restate()->startEndpoint();
        }
    }

    public function testRestartingRestateKeepsItsPortsStorageAndRegistration(): void
    {
        $restate = self::restate();
        $key = 'restart-' . bin2hex(random_bytes(4));
        self::invoke("Counter/{$key}/add", '5');
        [$ingress, $admin] = [$restate->ingressUrl(), $restate->adminUrl()];

        $restate->restartRestate();
        self::assertSame([$ingress, $admin], [$restate->ingressUrl(), $restate->adminUrl()]);
        self::assertInvocationReturns('5', "Counter/{$key}/get", null);

        $restate->restartRestate(graceful: false);
        self::assertSame([$ingress, $admin], [$restate->ingressUrl(), $restate->adminUrl()]);
        self::assertInvocationReturns('{"oldValue":5,"newValue":6}', "Counter/{$key}/add", '1');
    }

    public function testAPausedRestateAnswersNothingUntilItIsUnpaused(): void
    {
        $restate = self::restate();
        $id = 'pause-' . bin2hex(random_bytes(4));

        $restate->pauseRestate();
        try {
            try {
                HttpClient::get($restate->ingressUrl() . '/restate/health', timeout: 1.0);
                self::fail('A paused Restate answered.');
            } catch (HttpTransportException) {
                $this->addToAssertionCount(1);
            }
        } finally {
            $restate->unpauseRestate();
        }

        self::assertInvocationReturns(json_encode("Hello {$id}", JSON_THROW_ON_ERROR), 'Greeter/greet', json_encode($id, JSON_THROW_ON_ERROR));
    }

    public function testAKilledEndpointIsRetriedUntilItIsStartedAgain(): void
    {
        $restate = self::restate();
        $port = $restate->endpointPort();

        $restate->killEndpoint();
        self::assertFalse($restate->isEndpointRunning());
        try {
            HttpClient::get($restate->endpointUrl() . '/health', timeout: 1.0);
            self::fail('A killed endpoint answered.');
        } catch (HttpTransportException) {
            $this->addToAssertionCount(1);
        }

        // Chaos retries every 100 ms and pauses after 5 failed attempts: Restate gives up on a dead endpoint quickly.
        $id = self::sendInvocation('Chaos/steps', '{"runs":1}');
        $restate->waitForInvocationStatus($id, 'paused', 30.0);
        self::assertSame([], self::chaos()->attempts($id), 'An attempt reached the killed endpoint.');

        $restate->startEndpoint();
        self::assertTrue($restate->isEndpointRunning());
        self::assertSame($port, $restate->endpointPort());
        $resume = HttpClient::patch($restate->adminUrl() . "/invocations/{$id}/resume");
        self::assertTrue($resume->isSuccessful(), "Resume failed: HTTP {$resume->status} {$resume->body}");
        self::assertSame('{"attempt":2,"steps":[1]}', self::attachInvocation($id)->body);
        self::assertSame('completed', $restate->invocationRow($id, 'status')['status'] ?? null);
    }

    public function testASignalledEndpointCanBeRestarted(): void
    {
        $restate = self::restate();

        $restate->signalEndpoint(15);
        $deadline = microtime(true) + 10;
        while ($restate->isEndpointRunning() && microtime(true) < $deadline) {
            usleep(50_000);
        }
        self::assertFalse($restate->isEndpointRunning(), 'SIGTERM did not stop the endpoint.');

        $restate->startEndpoint();
        $restate->restartEndpoint(graceful: true);
        $restate->restartEndpoint();
        self::assertInvocationReturns('"Hello again"', 'Greeter/greet', '"again"');
    }

    public function testQueriesRejectBadInput(): void
    {
        $restate = self::restate();

        self::assertSame([['answer' => 42]], $restate->query('SELECT 42 AS answer'));
        foreach ([
            static fn () => $restate->invocationRow('inv_1', 'status; DROP'),
            static fn () => $restate->waitForInvocationStatus('inv_1', 'backing_off'),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid input was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
