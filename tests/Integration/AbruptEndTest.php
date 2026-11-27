<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Support\FaultInjectionTestCase;
use Restate\Tests\Integration\Support\IntegrationEndpoint;

/**
 * PHP ends the attempt abruptly, with `exit()`, `max_execution_time` (a CPU busy loop past
 * `set_time_limit(1)`: the limit counts CPU time, a sleep never trips it) or `memory_limit`, in a handler and inside a
 * `ctx.run` whose run retry policy allows 2 attempts.
 *
 * None of these reaches the SDK's `catch` or `finally`: the attempt ends with whatever PHP sent. Measured under
 * `php -S` and php-fpm behind nginx and Caddy alike: an empty `200 text/html` after `exit`, and a `500` after a fatal
 * error with `display_errors=0` (with it on, a `200` HTML page: the status is PHP's business, so tests read Restate's
 * failure instead). Restate treats both as a transient protocol error of the attempt (RT0012), never as a failed run.
 * So the **run retry policy is bypassed** and only the invocation retry policy governs (chaos.php: retry every 100 ms,
 * pause after 5 attempts): a run armed to fail 3 times executes 4 times, past its policy's 2, and one that always fails
 * pauses the invocation. Under FrankenPHP (classic and worker mode; RESTATE_PHP_TEST_SERVER=frankenphp,
 * frankenphp-worker), whose image has no php.ini, a fatal error is a `200` HTML page: the same RT0012 as an `exit`.
 */
#[Group('runtime')]
final class AbruptEndTest extends FaultInjectionTestCase
{
    /** Restate's `last_failure` after an `exit()`: an empty `200 text/html` (`php -S`, and php-fpm behind nginx or Caddy). */
    private const string EXIT_FAILURE = "unexpected content type 'Some(\"text/html; charset=UTF-8\")'";

    /** After a fatal error with `display_errors=0` (the harness's and the FPM image's setting) PHP answers 500. */
    private const string FATAL_FAILURE = 'unexpected http status code: 500';

    /** @return iterable<string, array{string, string, ?string, string, string}> handler, request, run effect, PHP's message, Restate's failure */
    public static function abruptEnds(): iterable
    {
        yield 'exit() in the handler' => ['exitInHandler', '{}', null, '', self::EXIT_FAILURE];
        yield 'exit() in a run' => ['exitInRun', '{}', 'exit', '', self::EXIT_FAILURE];
        yield 'time limit in the handler' => ['busyRun', '{"ms":3000,"timeLimit":1,"inHandler":true}', null, 'Maximum execution time of 1 second exceeded', self::FATAL_FAILURE];
        yield 'time limit in a run' => ['busyRun', '{"ms":3000,"timeLimit":1}', 'busy', 'Maximum execution time of 1 second exceeded', self::FATAL_FAILURE];
        yield 'memory limit in the handler' => ['exhaustMemoryInHandler', '{}', null, 'Allowed memory size of', self::FATAL_FAILURE];
        yield 'memory limit in a run' => ['exhaustMemoryInRun', '{}', 'memory', 'Allowed memory size of', self::FATAL_FAILURE];
    }

    /**
     * Armed for 3 executions: 3 failed attempts, then one that gets through. A run executes 4 times although its
     * policy allows 2: Restate never learnt that a run failed, only that attempts did.
     */
    #[DataProvider('abruptEnds')]
    public function testAnAbruptEndIsRetriedByTheInvocationPolicyNotTheRunPolicy(string $handler, string $request, ?string $effect, string $message, string $failure): void
    {
        $offset = self::restate()->endpointLogSize();
        $request = substr($request, 0, -1) . ($request === '{}' ? '' : ',') . '"times":3}';
        $id = self::sendInvocation("Chaos/{$handler}", $request);

        // While it retries, Restate keeps the last attempt's failure: not a run failure, but what PHP sent.
        $row = self::waitForRow($id, static fn (array $row): bool => is_string($row['last_failure'] ?? null) && $row['last_failure'] !== '', what: 'a recorded failure');
        // FrankenPHP's image ships no php.ini: display_errors is on, so a fatal error is a 200 HTML page there.
        $frankenPhp = str_starts_with(IntegrationEndpoint::server(), 'frankenphp');
        self::assertAbruptEndFailure($row, $failure === self::FATAL_FAILURE && $frankenPhp ? self::EXIT_FAILURE : $failure);

        $output = self::attachInvocation($id, 30.0);
        self::assertTrue($output->isSuccessful(), "Attach failed: HTTP {$output->status} {$output->body}");
        $attempts = count(self::chaos()->attempts($id));
        if ($effect !== null) {
            // 3 failed executions in attempts 1-3, the fourth in attempt 4 succeeds, attempt 5 replays it and returns.
            self::assertSame([1, 2, 3, 4], array_column(self::chaos()->effects($id, $effect), 'attempt'));
            self::assertSame(5, $attempts);
        } else {
            // 3 failed attempts; the fourth gets through (busyRun's run then ends it, so a fifth returns).
            self::assertSame($handler === 'busyRun' ? 5 : 4, $attempts);
        }
        self::assertSame('completed', self::invocationRow($id, 'status')['status']);
        self::assertPhpLogged($offset, $message);
        self::assertEndpointServes();
    }

    /**
     * Always armed: Restate retries until the invocation retry policy pauses it after 5 attempts, with the run
     * executed 5 times, then the resumed invocation completes once disarmed.
     */
    public function testARunThatAlwaysEndsTheProcessPausesTheInvocationOnceItsRetryPolicyIsExhausted(): void
    {
        $id = self::sendInvocation('Chaos/exitInRun', '{}');

        self::assertAbruptEndFailure(self::waitForRow($id, static fn (array $row): bool => is_string($row['last_failure'] ?? null) && $row['last_failure'] !== '', what: 'a recorded failure'), self::EXIT_FAILURE);
        self::restate()->waitForInvocationStatus($id, 'paused', 30.0);
        usleep(300_000);
        self::assertSame('paused', self::invocationRow($id, 'status')['status']);
        self::assertSame([1, 2, 3, 4, 5], array_column(self::chaos()->effects($id, 'exit'), 'attempt'));

        self::chaos()->disarm($id);
        self::resume($id);
        // The resumed attempt executes the run once more and suspends; the next one returns its execution number.
        self::assertSame('6', self::attachInvocation($id, 30.0)->body);
        self::assertSame([1, 2, 3, 4, 5, 6], array_column(self::chaos()->effects($id, 'exit'), 'attempt'));
        self::assertEndpointServes();
    }

    /**
     * What Restate recorded for an attempt PHP ended: RT0012, a transient protocol error about the response it got
     * (no protocol content type, or a 500), never a run failure or a terminal error.
     *
     * @param array<string, mixed> $row
     */
    private static function assertAbruptEndFailure(array $row, string $expected): void
    {
        self::assertStringContainsString($expected, (string) $row['last_failure'], 'last_failure: ' . json_encode($row, JSON_UNESCAPED_SLASHES));
        self::assertSame('RT0012', $row['last_failure_error_code'] ?? null);
    }

    /** PHP's own message went to the endpoint log (`php -S`'s output, or php-fpm's through the container's). */
    private static function assertPhpLogged(int $offset, string $message): void
    {
        if ($message === '' || !(IntegrationEndpoint::server() === 'php-cli' || self::isFpm())) {
            return;
        }

        $deadline = microtime(true) + 5;
        while (!str_contains(self::restate()->endpointLog($offset), $message) && microtime(true) < $deadline) {
            usleep(50_000);
        }
        self::assertStringContainsString($message, self::restate()->endpointLog($offset));
    }
}
