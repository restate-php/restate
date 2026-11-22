<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\IntegrationTestCase;

/**
 * When and how Restate retries a failed attempt, measured with the attempt times the `Retries` service records
 * (tests/Integration/Services/retries.php).
 */
final class RetryTest extends IntegrationTestCase
{
    /**
     * Restate starts the retry delay once it has the failed attempt's error, which comes after the service recorded the
     * attempt, so the gap between two attempts is never shorter than the delay. The tolerance only absorbs clock
     * rounding (both times come from the endpoint's host).
     */
    private const int TOLERANCE_MILLIS = 50;

    public function testRunRetryAfterDelaysTheNextAttemptBeyondThePolicyInterval(): void
    {
        $id = self::requestId();

        self::assertInvocationReturns(
            '2',
            'Retries/runRetryAfter',
            json_encode(['id' => $id, 'retryAfterMillis' => 2_000], JSON_THROW_ON_ERROR),
            timeout: 30.0,
        );

        // The policy would retry after 100 ms.
        self::assertGapAtLeast(2_000, self::attemptTimes($id));
    }

    public function testHandlerRetryAfterDelaysTheNextAttemptBeyondTheInvocationRetryPolicy(): void
    {
        $id = self::requestId();

        self::assertInvocationReturns(
            '2',
            'Retries/handlerRetryAfter',
            json_encode(['id' => $id, 'retryAfterMillis' => 2_000], JSON_THROW_ON_ERROR),
            timeout: 30.0,
        );

        // The handler's invocation retry policy would retry after 100 ms.
        self::assertGapAtLeast(2_000, self::attemptTimes($id));
    }

    /**
     * Once the run's policy is exhausted, the last RetryableError becomes its terminal failure: the caller and the ingress
     * see its message, status code and metadata (shared-core 8.3.0+ carries code and metadata).
     */
    public function testExhaustedRunFailsWithTheCodeAndMetadataOfItsLastRetryableError(): void
    {
        $id = self::requestId();

        $reported = self::invoke('Retries/reportRunExhausted', json_encode(['id' => $id], JSON_THROW_ON_ERROR), timeout: 30.0);

        // Restate does not keep the metadata order.
        self::assertEquals(
            ['code' => 503, 'message' => 'unavailable (attempt 2)', 'metadata' => ['retry-reason' => 'maintenance', '503' => 'numeric key']],
            json_decode($reported, true, flags: JSON_THROW_ON_ERROR),
            $reported,
        );
        self::assertCount(2, self::attemptTimes($id), 'The policy allows two attempts.');

        $direct = self::ingress('Retries/runExhausted', json_encode(['id' => self::requestId()], JSON_THROW_ON_ERROR), timeout: 30.0);
        self::assertSame(503, $direct->status, "Retries/runExhausted expected HTTP 503: {$direct->body}");
        self::assertStringContainsString('unavailable (attempt 2)', $direct->body);
    }

    private static function requestId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** @return list<int> the time of each attempt, in milliseconds */
    private static function attemptTimes(string $id): array
    {
        $file = self::stateDir() . '/retries-' . hash('sha256', $id) . '.txt';

        $lines = file($file, FILE_IGNORE_NEW_LINES);

        return $lines === false ? [] : array_map(intval(...), $lines);
    }

    /** @param list<int> $times */
    private static function assertGapAtLeast(int $millis, array $times): void
    {
        self::assertCount(2, $times, 'Expected exactly two attempts.');
        $gap = $times[1] - $times[0];
        self::assertGreaterThanOrEqual(
            $millis - self::TOLERANCE_MILLIS,
            $gap,
            "The retry came {$gap} ms after the failed attempt; it was asked for after {$millis} ms.",
        );
    }
}
