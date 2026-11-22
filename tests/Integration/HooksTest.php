<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Services\HookRecorder;
use Restate\Tests\Integration\Support\IntegrationTestCase;

/**
 * Runtime Execution Hooks against a real server: the `Hooks` service's recording interceptor
 * (tests/Integration/Services/hooks.php) and the endpoint's error mapper (tests/Integration/Support/endpoint.php).
 */
final class HooksTest extends IntegrationTestCase
{
    /**
     * In request/response mode the first attempt suspends (at the latest at the sleep): the handler interceptor sees the
     * suspension and runs again, replaying, on each attempt that resumes; each run interceptor fires once, when its
     * callback executes, never on replay.
     */
    public function testInterceptorsWrapEveryAttemptAndOnlyExecutedRuns(): void
    {
        $id = self::hookId();

        self::assertInvocationReturns('"one two"', 'Hooks/traced', null, ['restate-php-hook-id' => $id], timeout: 30.0);

        $events = self::events($id);
        $attempts = self::attempts($events);
        self::assertGreaterThanOrEqual(2, count($attempts), implode("\n", $events));

        self::assertSame('handler start replaying=false', $attempts[0][0], implode("\n", $events));
        self::assertSame('handler suspended', $attempts[0][array_key_last($attempts[0])], implode("\n", $events));
        $last = $attempts[array_key_last($attempts)];
        self::assertSame('handler start replaying=true', $last[0], implode("\n", $events));
        self::assertSame('handler end', $last[array_key_last($last)], implode("\n", $events));

        self::assertSame(['run before-sleep', 'run after-sleep'], array_values(array_filter($events, static fn (string $e): bool => str_starts_with($e, 'run '))), implode("\n", $events));
    }

    public function testErrorMapperTurnsADomainExceptionFromTheHandlerIntoATerminalFailure(): void
    {
        $id = self::hookId();

        $response = self::ingress('Hooks/failDomain', '"acct-1"', ['restate-php-hook-id' => $id]);

        self::assertSame(422, $response->status, "Hooks/failDomain expected HTTP 422: {$response->body}");
        self::assertStringContainsString('Insufficient funds on acct-1', $response->body);
        // Terminal: not retried. The interceptor saw the domain exception itself (it runs inside the mapping).
        self::assertSame(['handler start replaying=false', 'handler failed Restate\Tests\Integration\Services\InsufficientFunds'], self::events($id));
    }

    public function testErrorMapperTurnsADomainExceptionFromARunIntoATerminalFailure(): void
    {
        $id = self::hookId();

        $response = self::ingress('Hooks/failDomainInRun', '"acct-2"', ['restate-php-hook-id' => $id]);

        self::assertSame(422, $response->status, "Hooks/failDomainInRun expected HTTP 422: {$response->body}");
        self::assertStringContainsString('Insufficient funds on acct-2', $response->body);
        // The run failed terminally on its first execution, so its callback ran once. In request/response mode the
        // attempt suspends until Restate acknowledges the run's result; the attempt that resumes replays it and fails
        // with it, and is not retried.
        self::assertSame(
            ['handler start replaying=false', 'run charge', 'handler suspended', 'handler start replaying=true', 'handler failed Restate\TerminalError'],
            self::events($id),
        );
    }

    private static function hookId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** @return list<string> */
    private static function events(string $id): array
    {
        $lines = @file(HookRecorder::file(self::stateDir(), $id), FILE_IGNORE_NEW_LINES);

        return $lines === false ? [] : $lines;
    }

    /**
     * @param list<string> $events
     * @return list<non-empty-list<string>> the events of each attempt
     */
    private static function attempts(array $events): array
    {
        $attempts = [];
        foreach ($events as $event) {
            if (str_starts_with($event, 'handler start')) {
                $attempts[] = [$event];
            } elseif ($attempts !== []) {
                $attempts[array_key_last($attempts)][] = $event;
            }
        }

        return $attempts;
    }
}
