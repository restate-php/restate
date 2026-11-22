<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Support;

use Restate\Tests\Integration\Services\ChaosRecorder;
use Restate\Testing\EndpointContainer;
use Restate\Testing\PhpCliServer;
use Restate\Tests\Support\Faults\FaultInjector;
use Restate\Tests\Support\Faults\FaultPlan;

/**
 * Base class for tests that break things: the endpoint serves with the fault injector enabled
 * ({@see IntegrationEndpoint::withFaults()}), in an environment of its own that the shared one never sees, so these
 * tests may kill workers and restart Restate or the endpoint. Every class using it shares that environment.
 *
 * Kills shrink a `php -S` pool for good; a test that kills restarts the endpoint afterwards
 * (`self::restate()->restartEndpoint()`), or relies on the pool's spare workers.
 */
abstract class FaultInjectionTestCase extends IntegrationTestCase
{
    #[\Override]
    protected static function restateEndpoint(): PhpCliServer|EndpointContainer
    {
        return IntegrationEndpoint::withFaults();
    }

    /** What the chaos services recorded, in the state directory shared with the endpoint. */
    protected static function chaos(): ChaosRecorder
    {
        return new ChaosRecorder(self::stateDir());
    }

    protected static function writePlan(FaultPlan $plan): void
    {
        $plan->write(self::stateDir());
    }

    /** Whether the endpoint is php-fpm (RESTATE_PHP_TEST_SERVER), whose master replaces a killed worker at once. */
    protected static function isFpm(): bool
    {
        return isset(IntegrationEndpoint::FPM_SERVERS[IntegrationEndpoint::server()]);
    }

    /**
     * Whether a SIGKILLed worker's output is lost even when it was written in full: behind nginx (`nginx-fpm`,
     * `nginx-fpm-h2c`) the dead worker's FastCGI connection is reset, nginx reports `readv() failed (104: Connection
     * reset by peer) while reading upstream` and drops what it had not passed on, and Restate sees the response body
     * break off before any frame (RT0010) and loses the whole attempt. Caddy passes on what was written, `php -S`
     * delivers it before the connection closes.
     */
    protected static function killedWorkerLosesItsOutput(): bool
    {
        return in_array(IntegrationEndpoint::server(), ['nginx-fpm', 'nginx-fpm-h2c'], true);
    }

    /**
     * After a test that killed workers: a `php -S` pool never replaces them, so start the server again (php-fpm
     * replaces its own; a container endpoint is left alone).
     */
    protected static function replaceKilledWorkers(): void
    {
        if (self::restate()->endpointContainerName() === null) {
            self::restate()->restartEndpoint();
        }
    }

    /**
     * Poll the invocation's `sys_invocation` row until `$until` accepts it.
     *
     * @param \Closure(array<string, mixed>): bool $until
     *
     * @return array<string, mixed> the row that matched
     */
    protected static function waitForRow(string $invocationId, \Closure $until, float $timeout = 20.0, string $what = 'the expected state'): array
    {
        $deadline = microtime(true) + $timeout;
        $row = null;
        do {
            $row = self::restate()->invocationRow($invocationId);
            if ($row !== null && $until($row)) {
                return $row;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail("Invocation {$invocationId} did not reach {$what} within {$timeout}s; last row: " . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * The invocation's journal as Restate keeps it (`sys_journal`; the chaos services retain completed journals).
     *
     * @return list<array{index: int, entry_type: string, name: string}>
     */
    protected static function journal(string $invocationId): array
    {
        $rows = self::restate()->query(sprintf(
            "SELECT index, entry_type, name FROM sys_journal WHERE id = '%s' ORDER BY index",
            str_replace("'", "''", $invocationId),
        ));

        return array_map(static fn (array $row): array => [
            'index' => is_numeric($row['index'] ?? null) ? (int) $row['index'] : -1,
            'entry_type' => is_string($row['entry_type'] ?? null) ? $row['entry_type'] : '',
            'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
        ], $rows);
    }

    /**
     * How many journal entries of each kind the invocation has, by `entry_type` (and `name`, when there is one):
     * `['Command: Run first' => 1, …]`.
     *
     * @return array<string, int>
     */
    protected static function journalCounts(string $invocationId): array
    {
        $counts = [];
        foreach (self::journal($invocationId) as $entry) {
            $key = trim($entry['entry_type'] . ' ' . $entry['name']);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /** Resume a paused invocation (admin API). */
    protected static function resume(string $invocationId): void
    {
        $resume = HttpClient::patch(self::restate()->adminUrl() . "/invocations/{$invocationId}/resume");
        self::assertTrue($resume->isSuccessful(), "Resume failed: HTTP {$resume->status} {$resume->body}");
    }

    /** Ordinary calls on the same endpoint still succeed: the pool recovered from whatever the test did to it. */
    protected static function assertEndpointServes(int $calls = 10): void
    {
        for ($i = 0; $i < $calls; $i++) {
            self::assertInvocationReturns("\"Hello pool-{$i}\"", 'Greeter/greet', "\"pool-{$i}\"");
        }
    }

    /**
     * The attempts the fault injector saw ({@see FaultInjector::attempts()}).
     *
     * @return list<array{attempt: int, pid: int, response: ?array{status: int, frames: ?list<int>, fault: ?string, end: string, written: int, length: int}}>
     */
    protected static function injectedAttempts(string $invocationId): array
    {
        return FaultInjector::attempts(self::stateDir(), $invocationId);
    }
}
