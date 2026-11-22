<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\IntegrationTestCase;

/**
 * Pausing the invocation instead of retrying it (`PauseError`, `OnMaxAttempts::Pause`), with the `Pauses` service in
 * tests/Integration/Services/pauses.php: each handler pauses until the test fixes its condition, then returns how many
 * attempts it took.
 *
 * Restate 1.8 (service protocol v7) pauses the invocation; resuming it through the admin API runs it again. Restate 1.7
 * (v6) cannot pause: the attempt fails with 573 and a message saying why, and Restate keeps retrying.
 */
final class PauseTest extends IntegrationTestCase
{
    private const float WAIT_SECONDS = 20.0;

    /** @return iterable<string, array{string, int, string}> handler, attempts until it pauses, the pause's message */
    public static function pausingHandlers(): iterable
    {
        yield 'PauseError from the handler' => ['handler', 1, 'handler waits for a fix of %s'];
        yield 'PauseError from a run' => ['run', 1, 'run waits for approval of %s'];
        yield 'run policy that pauses once exhausted' => ['runExhausted', 2, 'unavailable (attempt 2)'];
        yield 'error mapper returning a PauseError' => ['mapped', 1, 'paused: ledger of %s is offline'];
    }

    #[DataProvider('pausingHandlers')]
    public function testThePausedInvocationCompletesOnceResumed(string $handler, int $attemptsUntilPaused, string $message): void
    {
        $id = bin2hex(random_bytes(8));
        $invocationId = self::send("Pauses/{$handler}", $id);

        if (!self::supportsPause()) {
            self::assertPauseRefused($invocationId, $handler === 'runExhausted'
                ? "Feature 'pause' is not supported"
                : 'Pausing the invocation needs service protocol v7 (Restate 1.8 or newer), but this invocation negotiated v6; failing the attempt instead: ' . sprintf($message, $id));

            return;
        }

        self::restate()->waitForInvocationStatus($invocationId, 'paused', self::WAIT_SECONDS);
        // Paused by the SDK, not by Restate giving up: no attempt after the one that asked to pause. (Restate 1.8 keeps
        // no failure in sys_invocation for a paused invocation, so $message only shows in its log.)
        usleep(500_000);
        self::assertSame('paused', self::invocationRow($invocationId, 'status', 'last_failure')['status']);
        self::assertCount($attemptsUntilPaused, self::attempts($id));

        touch(self::file($id, 'fixed'));
        $resume = HttpClient::patch(self::restate()->adminUrl() . "/invocations/{$invocationId}/resume");
        self::assertTrue($resume->isSuccessful(), "Resume failed: HTTP {$resume->status} {$resume->body}");

        $output = HttpClient::get(self::restate()->ingressUrl() . "/restate/invocation/{$invocationId}/attach", timeout: self::WAIT_SECONDS);
        self::assertTrue($output->isSuccessful(), "Attach failed: HTTP {$output->status} {$output->body}");
        self::assertSame((string) ($attemptsUntilPaused + 1), $output->body);
        self::assertSame('completed', self::invocationRow($invocationId, 'status', 'last_failure')['status']);
    }

    private static function supportsPause(): bool
    {
        return version_compare(self::restate()->serverVersion(), '1.8.0', '>=');
    }

    /**
     * On Restate 1.7 the pause cannot be requested: the attempt fails with 573 and $message, Restate retries it, and
     * the invocation never pauses. The test kills it afterwards.
     */
    private static function assertPauseRefused(string $invocationId, string $message): void
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;
        do {
            $row = self::invocationRow($invocationId, 'status', 'last_failure');
            if (str_starts_with(is_string($row['last_failure'] ?? null) ? $row['last_failure'] : '', '[573 ')) {
                break;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        try {
            // Restate puts the SDK's code in the message; last_failure_error_code is its own (RT0007).
            self::assertStringStartsWith("[573 Unsupported feature] {$message}", self::failureMessage($row));
            self::assertSame('backing-off', $row['status']);
        } finally {
            HttpClient::patch(self::restate()->adminUrl() . "/invocations/{$invocationId}/kill");
        }
    }

    private static function send(string $path, string $id): string
    {
        $sent = self::invoke("{$path}/send", json_encode($id, JSON_THROW_ON_ERROR));
        $invocationId = json_decode($sent, true, flags: JSON_THROW_ON_ERROR)['invocationId'] ?? null;
        self::assertIsString($invocationId, "send returned no invocation id: {$sent}");

        return $invocationId;
    }

    /** @param array<string, mixed> $row */
    private static function failureMessage(array $row): string
    {
        $failure = $row['last_failure'] ?? null;
        self::assertIsString($failure, json_encode($row, JSON_THROW_ON_ERROR));

        return $failure;
    }

    /** @return list<string> one line per attempt */
    private static function attempts(string $id): array
    {
        $lines = file(self::file($id, 'txt'), FILE_IGNORE_NEW_LINES);

        return $lines === false ? [] : $lines;
    }

    private static function file(string $id, string $extension): string
    {
        return self::stateDir() . '/pauses-' . hash('sha256', $id) . '.' . $extension;
    }
}
