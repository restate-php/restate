<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Support\FaultInjectionTestCase;
use Restate\Tests\Integration\Support\IntegrationEndpoint;
use Restate\Tests\Integration\Support\ShutdownScenarios;
use Restate\Testing\EndpointContainer;
use Restate\Testing\PhpCliServer;

/**
 * Graceful shutdown, the fix: php-fpm with `process_control_timeout = 10s` (RESTATE_PHP_FPM_PROCESS_CONTROL_TIMEOUT)
 * lets a graceful reload (`USR2`) or stop (`QUIT`) wait for the requests in flight, so the 3 s run's attempt still reaches
 * Restate and the run executes once. With the default of 0 both lose the attempt (GracefulShutdownTest). Runs on the
 * php-fpm servers only, in an environment of its own.
 */
#[Group('failure-nightly')]
final class PhpFpmDrainTest extends FaultInjectionTestCase
{
    use ShutdownScenarios;

    private const int SIGQUIT = 3;

    private const int SIGUSR2 = 12;

    #[\Override]
    protected static function restateEndpoint(): PhpCliServer|EndpointContainer
    {
        return IntegrationEndpoint::withFaults()->withEnvironment(['RESTATE_PHP_FPM_PROCESS_CONTROL_TIMEOUT' => '10s']);
    }

    #[\Override]
    protected function setUp(): void
    {
        self::requireServer(self::isFpm(), 'php-fpm (RESTATE_PHP_TEST_SERVER=nginx-fpm, nginx-fpm-h2c or caddy-fpm)');
        parent::setUp();
    }

    public function testAReloadWaitsForTheRunningAttempt(): void
    {
        $id = self::startSlowRun();
        self::restate()->signalEndpoint(self::SIGUSR2);

        self::assertCompletesWithRunExecuted($id, 1);
        self::assertTrue(self::restate()->isEndpointRunning());
        self::assertEndpointServes(3);
    }

    public function testAGracefulStopWaitsForTheRunningAttempt(): void
    {
        $id = self::startSlowRun();
        self::restate()->signalEndpoint(self::SIGQUIT);
        self::waitUntilEndpointExited();
        self::startEndpointAndResume($id);

        self::assertCompletesWithRunExecuted($id, 1);
        self::assertEndpointServes(3);
    }
}
