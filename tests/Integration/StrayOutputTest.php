<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Support\IntegrationTestCase;
use Restate\Tests\Integration\Support\JsonLinesLogger;

/**
 * Output printed while a real Restate invokes a handler (docs/decisions/0032-stray-output.md). Before the fix
 * it went in front of the protocol frames, the response went out as text/html, and Restate retried forever (RT0012).
 * The services (tests/Integration/Services/stray_output.php) print, and the invocations still complete; the endpoint
 * logs what it discarded.
 */
#[Group('runtime')]
final class StrayOutputTest extends IntegrationTestCase
{
    private const string DISCARDED = 'Discarded output printed while handling the request, which would have corrupted the response; log instead of printing, and set display_errors=Off with log_errors=On';

    public function testAHandlerThatEchoesCompletes(): void
    {
        $token = bin2hex(random_bytes(8));

        self::assertInvocationReturns(json_encode("{$token}: step done", JSON_THROW_ON_ERROR), 'StrayOutput/echoes', json_encode($token, JSON_THROW_ON_ERROR), timeout: 30.0);

        // One record per attempt: the first prints from the handler and the run, the replay from the handler only.
        $excerpts = self::discardedOutput('/invoke/StrayOutput/echoes', $token);
        self::assertContains("handler output {$token}\nrun output {$token}\n", $excerpts);
        self::assertContains("handler output {$token}\n", $excerpts);
    }

    public function testAHandlerThatRaisesDisplayedDeprecationsAndWarningsCompletes(): void
    {
        $token = bin2hex(random_bytes(8));

        self::assertInvocationReturns(json_encode("{$token}: warned", JSON_THROW_ON_ERROR), 'StrayOutput/warns', json_encode($token, JSON_THROW_ON_ERROR), timeout: 30.0);

        $excerpts = self::discardedOutput('/invoke/StrayOutput/warns', $token);
        self::assertNotEmpty($excerpts);
        // php -S displays errors as HTML (html_errors), the CLI as text.
        self::assertMatchesRegularExpression("~Deprecated(</b>)?:\\s+deprecated call {$token}~", $excerpts[0]);
        self::assertMatchesRegularExpression("~Warning(</b>)?:\\s+warning {$token}~", $excerpts[0]);
    }

    /** @return list<string> the excerpts of the endpoint's "discarded output" records for $path that mention $token */
    private static function discardedOutput(string $path, string $token): array
    {
        $excerpts = [];
        foreach (JsonLinesLogger::read(self::stateDir() . '/endpoint-log.jsonl') as $record) {
            $excerpt = $record['context']['restate.output.excerpt'] ?? null;
            if ($record['message'] === self::DISCARDED && ($record['context']['restate.request.path'] ?? null) === $path && is_string($excerpt) && str_contains($excerpt, $token)) {
                self::assertSame('warning', $record['level']);
                self::assertIsString($record['context']['restate.invocation.id'] ?? null);
                self::assertStringContainsString('stray_output.php:', (string) $record['context']['restate.output.origin']);
                $excerpts[] = $excerpt;
            }
        }

        return $excerpts;
    }
}
