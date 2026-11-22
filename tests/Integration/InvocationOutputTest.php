<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\IntegrationTestCase;

/**
 * `InvocationHandle::output()` waits for another invocation (attach); `peekOutput()` does not (get output). Service
 * `InvocationOutputs` in tests/Integration/Services/invocation_outputs.php: each handler sends a `slow` invocation
 * that sleeps for 2 s, so the first peek sees it running.
 */
final class InvocationOutputTest extends IntegrationTestCase
{
    public function testOutputOfASentInvocationWaitsForItAndPeekDoesNot(): void
    {
        self::assertInvocationReturns(
            '{"running":null,"output":"slept 2000","finished":"slept 2000"}',
            'InvocationOutputs/sendAndWait',
            '2000',
            timeout: 20.0,
        );
    }

    public function testOutputOfAnInvocationByIdWaitsForItAndPeekDoesNot(): void
    {
        self::assertInvocationReturns(
            '{"running":null,"output":"slept 2000","finished":"slept 2000"}',
            'InvocationOutputs/waitById',
            '2000',
            timeout: 20.0,
        );
    }
}
