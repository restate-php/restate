<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Support\FaultInjectionTestCase;
use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\IntegrationEndpoint;
use Restate\Tests\Integration\Support\ShutdownScenarios;

/**
 * Rolling deploys and graceful shutdowns with attempts in flight, the most common way an endpoint goes away.
 *
 * - php-fpm `USR2` (graceful reload) and `QUIT` (graceful stop: the fpm image's stop signal; the entrypoint lets
 *   php-fpm finish its requests, then stops the front door), during a 3 s run;
 * - SIGTERM to `php -S` and to FrankenPHP during a run (each test runs on its own server only);
 * - new code under the same deployment URI while an invocation is suspended: its replay is a journal mismatch, which
 *   the SDK reports (Restate retries it until the retry policy pauses it), never a silently different journal; with
 *   the old code back the resumed invocation completes.
 *
 * No attempt is lost for good, and effects happen at least once: exactly once where the attempt's response still
 * reached Restate.
 */
#[Group('failure-nightly')]
final class GracefulShutdownTest extends FaultInjectionTestCase
{
    use ShutdownScenarios;

    private const int SIGTERM = 15;

    private const int SIGQUIT = 3;

    private const int SIGUSR2 = 12;

    /** With php-fpm's default `process_control_timeout = 0` a reload does not wait for requests in flight. */
    public function testAPhpFpmReloadLosesTheRunningAttemptByDefault(): void
    {
        self::requireServer(self::isFpm(), 'php-fpm (RESTATE_PHP_TEST_SERVER=nginx-fpm, nginx-fpm-h2c or caddy-fpm)');

        $id = self::startSlowRun();
        self::restate()->signalEndpoint(self::SIGUSR2);

        self::assertCompletesWithRunExecuted($id, 2);
        self::assertTrue(self::restate()->isEndpointRunning());
        self::assertEndpointServes(3);
    }

    /** The same for a graceful stop (`QUIT`, what `docker stop` sends the php-fpm image). */
    public function testAPhpFpmGracefulStopLosesTheRunningAttemptByDefault(): void
    {
        self::requireServer(self::isFpm(), 'php-fpm (RESTATE_PHP_TEST_SERVER=nginx-fpm, nginx-fpm-h2c or caddy-fpm)');

        $id = self::startSlowRun();
        self::restate()->signalEndpoint(self::SIGQUIT);
        self::waitUntilEndpointExited();
        self::startEndpointAndResume($id);

        self::assertCompletesWithRunExecuted($id, 2);
        self::assertEndpointServes(3);
    }

    public function testSigtermToPhpCliServerLosesTheRunningAttempt(): void
    {
        self::requireServer(IntegrationEndpoint::server() === 'php-cli', 'php -S (RESTATE_PHP_TEST_SERVER=php-cli)');

        $id = self::startSlowRun();
        self::restate()->signalEndpoint(self::SIGTERM);
        self::waitUntilEndpointExited();
        self::startEndpointAndResume($id);

        self::assertCompletesWithRunExecuted($id, 2);
        self::assertEndpointServes(3);
    }

    public function testSigtermToFrankenPhpDuringARun(): void
    {
        self::requireServer(in_array(IntegrationEndpoint::server(), ['frankenphp', 'frankenphp-worker'], true), 'FrankenPHP, classic or worker mode (RESTATE_PHP_TEST_SERVER=frankenphp or frankenphp-worker)');

        $id = self::startSlowRun();
        self::restate()->signalEndpoint(self::SIGTERM);
        self::waitUntilEndpointExited();
        self::startEndpointAndResume($id);

        // Caddy's graceful shutdown waits for the request to finish; either way the run executed at least once.
        $output = self::attachInvocation($id, 40.0);
        self::assertTrue($output->isSuccessful(), "Attach failed: HTTP {$output->status} {$output->body}");
        self::assertContains(count(self::chaos()->effects($id, 'slow')), [1, 2]);
        self::assertEndpointServes(3);
    }

    public function testNewCodeUnderTheSameUriIsAJournalMismatchNotACorruptJournal(): void
    {
        $id = self::sendInvocation('Chaos/deploy', null);
        self::restate()->waitForInvocationStatus($id, 'suspended', 20.0);
        $awakeable = self::chaos()->value($id, 'awakeable');
        self::assertNotNull($awakeable);
        self::assertCount(1, self::chaos()->effects($id, 'first'));

        // "Deploy" code whose first command is a sleep instead of the run, then wake the invocation up.
        self::chaos()->put($id, 'code', 'v2');
        self::resolveAwakeable($awakeable, '"deployed"');

        $row = self::waitForRow($id, static fn (array $row): bool => is_string($row['last_failure'] ?? null) && $row['last_failure'] !== '', what: 'a recorded failure');
        self::assertSame('RT0016', $row['last_failure_error_code'] ?? null, 'last_failure: ' . json_encode($row, JSON_UNESCAPED_SLASHES));
        self::assertStringContainsString('[570 Journal mismatch]', (string) $row['last_failure']);
        self::restate()->waitForInvocationStatus($id, 'paused', 30.0);

        // The old code back: the paused invocation replays its journal as it was and completes.
        self::chaos()->put($id, 'code', 'v1');
        self::resume($id);
        self::assertSame('"deployed"', self::attachInvocation($id, 30.0)->body);
        self::assertCount(1, self::chaos()->effects($id, 'first'), 'The run executed again instead of being replayed.');
        self::assertSame(1, self::journalCounts($id)['Command: Run first'] ?? 0);
        self::assertArrayNotHasKey('Command: Sleep', self::journalCounts($id));
    }


    private static function resolveAwakeable(string $awakeable, string $json): void
    {
        $response = HttpClient::postJson(self::restate()->ingressUrl() . "/restate/awakeables/{$awakeable}/resolve", $json);
        self::assertTrue($response->isSuccessful(), "Resolving the awakeable failed: HTTP {$response->status} {$response->body}");
    }
}
