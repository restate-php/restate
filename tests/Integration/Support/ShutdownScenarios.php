<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Support;

/**
 * What GracefulShutdownTest and PhpFpmDrainTest share: a slow run to shut the endpoint down under, and the checks
 * once it is back.
 *
 * @phpstan-require-extends FaultInjectionTestCase
 */
trait ShutdownScenarios
{
    private static function requireServer(bool $matches, string $server): void
    {
        if (!$matches) {
            self::markTestSkipped("Runs on {$server} only.");
        }
    }

    /** `Chaos/slowRun` with a 3 s run, once the run is executing. */
    private static function startSlowRun(): string
    {
        $id = self::sendInvocation('Chaos/slowRun', '{"ms":3000,"times":1}');
        $deadline = microtime(true) + 10;
        while (self::chaos()->effects($id, 'slow') === []) {
            self::assertLessThan($deadline, microtime(true), 'The slow run never started.');
            usleep(50_000);
        }

        return $id;
    }

    private static function waitUntilEndpointExited(): void
    {
        $deadline = microtime(true) + 20;
        while (self::restate()->isEndpointRunning()) {
            self::assertLessThan($deadline, microtime(true), 'The endpoint did not exit.');
            usleep(100_000);
        }
    }

    /**
     * Start the endpoint again. While it was down, Restate's retries (every 100 ms) may have exhausted the chaos
     * services' policy, which pauses the invocation: resume it then, as an operator would.
     */
    private static function startEndpointAndResume(string $id): void
    {
        self::restate()->startEndpoint();
        $deadline = microtime(true) + 1.0;
        do {
            if ((self::restate()->invocationRow($id, 'status')['status'] ?? null) === 'paused') {
                self::resume($id);

                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);
    }

    /** The invocation completed, its run executed `$executions` times, and the result is the last execution's. */
    private static function assertCompletesWithRunExecuted(string $id, int $executions): void
    {
        $output = self::attachInvocation($id, 40.0);
        self::assertTrue($output->isSuccessful(), "Attach failed: HTTP {$output->status} {$output->body}");
        self::assertCount($executions, self::chaos()->effects($id, 'slow'), 'Executions of the run; output ' . $output->body);
        self::assertSame(sprintf('{"attempt":%d,"execution":%d}', $executions + 1, $executions), $output->body);
        self::assertSame(1, self::journalCounts($id)['Command: Run slow'] ?? 0);
    }
}
