<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\IntegrationTestCase;

final class RunTest extends IntegrationTestCase
{
    public function testRunReturnsClosureResult(): void
    {
        self::assertInvocationReturns('"Hello Mary from run"', 'Greeter/greetWithRun', '"Mary"');
    }

    public function testRetryableRunFailureIsRetried(): void
    {
        // The handler counts attempts in this file; the first attempt throws RetryableError.
        $attemptFile = self::stateDir() . '/greet-with-retry-run-attempts.txt';
        if (is_file($attemptFile)) {
            unlink($attemptFile);
        }

        self::assertInvocationReturns(
            '{"message":"Hello Mary from retry run","attempts":2}',
            'Greeter/greetWithRetryRun',
            '"Mary"',
            timeout: 30.0,
        );
    }
}
