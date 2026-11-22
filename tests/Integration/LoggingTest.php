<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\IntegrationTestCase;
use Restate\Tests\Integration\Support\JsonLinesLogger;

/**
 * What the SDK logs to the endpoint's logger for a real invocation (docs/decisions/0027-sdk-logging.md). The integration
 * endpoint logs to a JSON-lines file in the state directory; `Logging/failOnce` (tests/Integration/Services/logging.php)
 * fails its first attempt and succeeds on the retry.
 */
final class LoggingTest extends IntegrationTestCase
{
    public function testAFailedAttemptIsLoggedWithTheInvocationIdAndTheRetrySucceeds(): void
    {
        $request = bin2hex(random_bytes(8));

        $invocationId = json_decode(self::invoke('Logging/failOnce', json_encode($request, JSON_THROW_ON_ERROR), timeout: 30.0), flags: JSON_THROW_ON_ERROR);
        self::assertIsString($invocationId);
        self::assertStringStartsWith('inv_', $invocationId);

        $records = array_values(array_filter(
            JsonLinesLogger::read(self::stateDir() . '/endpoint-log.jsonl'),
            static fn (array $record): bool => ($record['context']['restate.invocation.id'] ?? null) === $invocationId,
        ));
        $target = ['restate.invocation.id' => $invocationId, 'restate.invocation.target' => 'Logging/failOnce'];

        self::assertSame([
            ['debug', 'Starting invocation', $target],
            ['info', 'failOnce started', ['request' => $request] + $target],
            // Request-response serving: the run's completion needs Restate's acknowledgement, so the attempt suspends.
            ['debug', 'Invocation suspended', $target],
            // The handler's logger is silent while the retry replays up to the run.
            ['debug', 'Replaying invocation', $target],
            ['warning', 'Invocation attempt failed; Restate retries it', $target + ['exception' => ['class' => \RuntimeException::class, 'message' => "first attempt of {$request} fails"]]],
            ['debug', 'Replaying invocation', $target],
            ['debug', 'Invocation completed', $target],
        ], array_map(static fn (array $record): array => [$record['level'], $record['message'], $record['context']], $records));
    }
}
