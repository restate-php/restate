<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\IntegrationTestCase;

final class CommunicationTest extends IntegrationTestCase
{
    public function testCallService(): void
    {
        self::assertInvocationReturns('"Hello Mary"', 'Communicator/callGreeter', '"Mary"');
    }

    public function testCallVirtualObject(): void
    {
        self::assertInvocationReturns('{"oldValue":0,"newValue":5}', 'Communicator/callCounter', '{"key":"n2-call","amount":5}');
    }

    public function testCallWorkflow(): void
    {
        self::assertInvocationReturns('"workflow ping"', 'Communicator/callWorkflow', '"n2-workflow"');
    }

    public function testSend(): void
    {
        self::assertInvocationReturns('"sent"', 'Communicator/sendCounter', '{"key":"n2-send","amount":7}');
        self::waitForCounter('n2-send', '7');
    }

    public function testDelayedSendIsNotVisibleBeforeDelay(): void
    {
        self::assertInvocationReturns(
            '"scheduled"',
            'Communicator/sendCounterDelayed',
            '{"key":"n2-delayed","amount":4,"delay_ms":2000}',
        );

        usleep(500_000);
        $early = self::ingress('Counter/n2-delayed/get', null, timeout: 5.0);
        self::assertTrue($early->isSuccessful(), "Failed to read Counter/n2-delayed/get: HTTP {$early->status} {$early->body}");
        self::assertSame('0', $early->body, 'Delayed send was visible before delay elapsed');

        self::waitForCounter('n2-delayed', '4');
    }
}
