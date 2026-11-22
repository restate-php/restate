<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\IntegrationTestCase;

final class TimerTest extends IntegrationTestCase
{
    public function testSleep(): void
    {
        self::assertInvocationReturns('"slept 100ms"', 'Communicator/sleep', '100', timeout: 20.0);
    }

    /** The first attempt suspends at the sleep; `catch (\Exception)` around it must not see that, and the resumed attempt completes. */
    public function testSleepInsideCatchExceptionStillSuspendsAndResumes(): void
    {
        $id = bin2hex(random_bytes(8));
        $caughtFile = self::stateDir() . '/communicator-caught-' . hash('sha256', $id) . '.txt';

        self::assertInvocationReturns('"slept 300ms"', 'Communicator/sleepCatchingException', '{"id":"' . $id . '","delay_ms":300}', timeout: 20.0);
        self::assertSame('', (string) @file_get_contents($caughtFile), 'The handler caught the suspension.');
    }
}
