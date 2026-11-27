<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Services\ChaosRecorder;
use Restate\Tests\Integration\Services\MemoryProbe;
use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\IntegrationEndpoint;
use Restate\Tests\Integration\Support\IntegrationRestate;
use Restate\Tests\Integration\Support\IntegrationTestCase;
use Restate\Tests\Integration\Support\JsonLinesLogger;
use Restate\Testing\RestateTestEnvironment;

/**
 * Stray output and large bodies through a real Restate (docs/decisions/0032-stray-output.md), with the services of
 * tests/Integration/Services/large_bodies.php, which give up after three attempts.
 *
 * The matrix: `display_errors` {1, 0} × `enable_post_data_reading` {1, 0} × inputs of 1, 9 and 31 MiB × handlers
 * that echo, raise an E_DEPRECATED and raise an E_WARNING. The harness's own settings (display_errors=0,
 * enable_post_data_reading=0) are the shared endpoint, whatever server it is; the other three are `php -S` endpoints
 * of their own, each with its own Restate, so they only run when the suite runs on `php -S` (elsewhere the server's
 * php.ini decides). Exactly one cell cannot work: display_errors=1 with enable_post_data_reading=1 above
 * post_max_size (8M), where PHP prints its startup warning into the response before the script runs (case (b) of the
 * decision). Every attempt then fails visibly, the endpoint log names the cause, and the handler never runs. Every
 * other cell completes; what the handler printed is discarded and logged.
 *
 * Beyond the matrix, on the shared endpoint: values over Restate's 32 MiB per-message limit produced inside a handler
 * (output, state, run result) end in a bounded failure (Restate's RT0003, transient, so the retry policy ends it), a
 * 31 MiB output completes, and a 40 MiB input never gets past the ingress (413).
 *
 * Peak memory (RSS, which counts the Rust copies) of the large cases is printed with RESTATE_PHP_TEST_REPORT_MEMORY=1
 * and checked against a generous bound.
 */
#[Group('runtime')]
final class LargeBodyTest extends IntegrationTestCase
{
    private const int MIB = 1 << 20;

    private const string DISCARDED = 'Discarded output printed while handling the request, which would have corrupted the response; log instead of printing, and set display_errors=Off with log_errors=On';

    private const string OUTPUT_BEFORE_SCRIPT = 'PHP sent output before the endpoint could respond, so Restate cannot read the response and retries the attempt; the endpoint did not run it.';

    /** @var array<string, RestateTestEnvironment> the `php -S` variants started so far, by name */
    private static array $variants = [];

    #[AfterClass]
    public static function stopVariants(): void
    {
        foreach (self::$variants as $environment) {
            $environment->stop();
        }
        self::$variants = [];
    }

    /** @return iterable<string, array{?string, ?string, int, string}> */
    public static function inputs(): iterable
    {
        foreach (self::configurations() as $name => [$displayErrors, $postDataReading]) {
            foreach (['echo', 'deprecated', 'warning'] as $trigger) {
                yield "{$name}, 1 MiB, {$trigger}" => [$displayErrors, $postDataReading, 1, $trigger];
            }
            yield "{$name}, 9 MiB, all" => [$displayErrors, $postDataReading, 9, 'echo,deprecated,warning'];
        }
    }

    /** @return iterable<string, array{?string, ?string, int, string}> */
    public static function largestInputs(): iterable
    {
        foreach (self::configurations() as $name => [$displayErrors, $postDataReading]) {
            yield "{$name}, 31 MiB, all" => [$displayErrors, $postDataReading, 31, 'echo,deprecated,warning'];
        }
    }

    #[DataProvider('inputs')]
    public function testInput(?string $displayErrors, ?string $postDataReading, int $mebibytes, string $triggers): void
    {
        $this->assertInput($displayErrors, $postDataReading, $mebibytes, $triggers);
    }

    /** 31 MiB, just under Restate's 32 MiB message limit; nightly (each case moves about 100 MB through Restate). */
    #[DataProvider('largestInputs')]
    #[Group('large-bodies')]
    public function testLargestInput(?string $displayErrors, ?string $postDataReading, int $mebibytes, string $triggers): void
    {
        $this->assertInput($displayErrors, $postDataReading, $mebibytes, $triggers);
    }

    public function testAnInputOverTheIngressLimitIsRejectedThere(): void
    {
        $response = HttpClient::post(self::restate()->ingressUrl() . '/LargeBodies/ingest/send', str_repeat('i', 40 * self::MIB), ['content-type' => 'application/octet-stream'], timeout: 60.0);

        self::assertSame(413, $response->status, $response->body);
        self::assertStringContainsString('length limit exceeded', $response->body);
    }

    #[Group('large-bodies')]
    public function testAnOutputJustUnderTheMessageLimitCompletes(): void
    {
        $bytes = 31 * self::MIB;
        $id = self::sendInvocation('LargeBodies/output', (string) $bytes);

        $response = self::attachInvocation($id, timeout: 60.0);
        self::assertSame(200, $response->status, substr($response->body, 0, 500));
        self::assertSame($bytes, strlen($response->body));
        self::assertSame(str_repeat('o', 16), substr($response->body, 0, 16));
        self::assertMemoryWithinBounds($id, $bytes, "31 MiB output on {$this->serverName()}");
    }

    /** @return iterable<string, array{string, string}> */
    public static function overLimitValues(): iterable
    {
        yield 'handler output' => ['LargeBodies/output', 'OutputCommand'];
        yield 'run result' => ['LargeBodies/runResult', 'ProposeRunCompletion'];
        yield 'state value' => ['LargeBodiesObject/%s/setState', 'SetStateCommand'];
    }

    /**
     * A value over Restate's per-message limit (`networking.message-size-limit`, 32 MiB), produced inside the
     * handler: the endpoint answers with a protocol response, Restate fails to decode the message (RT0003 "message
     * encoding error: hit message size limit", which Restate 1.8.1 treats as transient, not as the non-transient
     * OutOfMemory) and retries, and the service's retry policy (three attempts, KILL) ends the invocation with that
     * error. Without a retry policy it would retry for as long as Restate's default policy allows.
     */
    #[DataProvider('overLimitValues')]
    #[Group('large-bodies')]
    public function testAValueOverTheMessageLimitFailsWithinTheRetryPolicy(string $path, string $message): void
    {
        $bytes = 33 * self::MIB;
        $id = self::sendInvocation(sprintf($path, 'blob-' . bin2hex(random_bytes(4))), (string) $bytes);

        $response = self::attachInvocation($id, timeout: 90.0);
        self::assertSame(500, $response->status, $response->body);
        self::assertStringContainsString('hit message size limit', $response->body);

        $row = self::invocationRow($id, 'status', 'completion_result', 'completion_failure');
        self::assertSame(['status' => 'completed', 'completion_result' => 'failure'], array_intersect_key($row, ['status' => 1, 'completion_result' => 1]));
        self::assertMatchesRegularExpression('/message encoding error: hit message size limit: \d+ >= 33816576/', (string) $row['completion_failure']);
        // Every attempt ran the handler and produced the value; the policy ended it after the third.
        self::assertCount(3, new ChaosRecorder(self::stateDir())->attempts($id));
        self::assertStringContainsString("Message size warning for '{$message}'", self::restate()->restateLog(400));
    }

    private function assertInput(?string $displayErrors, ?string $postDataReading, int $mebibytes, string $triggers): void
    {
        $restate = self::environment($displayErrors, $postDataReading);
        $bytes = $mebibytes * self::MIB;
        // A payload whose hash a truncation or a corrupted byte would change.
        $input = substr(str_repeat(hash('sha256', $triggers . $bytes, true), intdiv($bytes, 32) + 1), 0, $bytes);
        $logOffset = $restate->endpointLogSize();

        $sent = HttpClient::post($restate->ingressUrl() . '/LargeBodies/ingest/send', $input, ['content-type' => 'application/octet-stream', 'x-large-bodies-trigger' => $triggers], timeout: 60.0);
        self::assertSame(202, $sent->status, $sent->body);
        $id = json_decode($sent->body, true, flags: JSON_THROW_ON_ERROR)['invocationId'];
        self::assertIsString($id);
        $response = HttpClient::get($restate->ingressUrl() . "/restate/invocation/{$id}/attach", timeout: 90.0);

        $settings = self::settings($restate);
        $displayed = $settings['displayErrors'];
        $limit = self::bytes($settings['postMaxSize']);
        if ($displayed && $settings['enablePostDataReading'] && $limit > 0 && $bytes > $limit) {
            // Case (b): PHP printed `POST Content-Length of … exceeds the limit of 8388608 bytes` before the script
            // ran. Every attempt is answered as text/html, Restate retries it (RT0012), the policy ends it.
            self::assertSame(500, $response->status, $response->body);
            self::assertStringContainsString('unexpected content type', $response->body);
            self::assertSame([], new ChaosRecorder(self::stateDir())->attempts($id), 'The handler ran although its response could never reach Restate.');
            $records = self::records($id, self::OUTPUT_BEFORE_SCRIPT);
            self::assertCount(3, $records, 'One error record per attempt.');
            self::assertSame('error', $records[0]['level']);
            self::assertStringStartsWith('before the script ran: a PHP startup warning', (string) $records[0]['context']['restate.output.origin']);
            self::assertStringContainsString('exceeds the limit of ' . $limit . ' bytes', $restate->endpointLog($logOffset));

            return;
        }

        self::assertSame(200, $response->status, substr($response->body, 0, 500));
        self::assertSame(['bytes' => $bytes, 'sha1' => sha1($input), 'attempt' => 1], json_decode($response->body, true, flags: JSON_THROW_ON_ERROR));

        // What the handler printed: an echo always; a deprecation or warning only where PHP displays it. All of it was
        // discarded from the response and logged.
        $expected = array_filter([
            'echo' => 'stray output from LargeBodies/ingest',
            'deprecated' => $displayed ? 'Calling current() on an object is deprecated' : null,
            'warning' => $displayed ? 'Hexadecimal input string must have an even length' : null,
        ]);
        $printed = array_intersect_key($expected, array_flip(explode(',', $triggers)));
        $excerpts = implode("\n", array_map(static fn (array $record): string => (string) $record['context']['restate.output.excerpt'], self::records($id, self::DISCARDED)));
        foreach ($printed as $text) {
            self::assertStringContainsString($text, $excerpts);
        }
        if ($printed === []) {
            self::assertSame('', $excerpts, 'Nothing was printed, so nothing should have been discarded.');
        }

        if ($bytes > self::MIB) {
            self::assertMemoryWithinBounds($id, $bytes, sprintf('%d MiB input, display_errors=%s, enable_post_data_reading=%s, %s', $mebibytes, $displayErrors ?? 'server', $postDataReading ?? 'server', $this->serverName()));
        }
    }

    /**
     * The shared endpoint for the harness's settings (null, null), else a `php -S` endpoint with these settings, in
     * an environment of its own (one Restate per variant, stopped after the class).
     */
    private static function environment(?string $displayErrors, ?string $postDataReading): RestateTestEnvironment
    {
        if ($displayErrors === null && $postDataReading === null) {
            return self::restate();
        }
        if (IntegrationEndpoint::server() !== 'php-cli') {
            self::markTestSkipped(sprintf('The php.ini matrix runs on php -S (RESTATE_PHP_TEST_SERVER=php-cli); the %s server has its php.ini baked in.', IntegrationEndpoint::server()));
        }

        $name = "display_errors={$displayErrors}, enable_post_data_reading={$postDataReading}";

        return self::$variants[$name] ??= RestateTestEnvironment::start(
            IntegrationEndpoint::phpCli()
                ->withIniSetting('display_errors', (string) $displayErrors)
                ->withIniSetting('enable_post_data_reading', (string) $postDataReading),
            IntegrationRestate::get(),
        );
    }

    /**
     * The endpoint's PHP settings, as `LargeBodies/settings` reports them.
     *
     * @return array{displayErrors: bool, enablePostDataReading: bool, postMaxSize: string}
     */
    private static function settings(RestateTestEnvironment $restate): array
    {
        $response = HttpClient::postJson($restate->ingressUrl() . '/LargeBodies/settings', null);
        self::assertTrue($response->isSuccessful(), "LargeBodies/settings: HTTP {$response->status} {$response->body}");

        /** @var array{displayErrors: bool, enablePostDataReading: bool, postMaxSize: string} */
        return json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
    }

    /** An ini size (`8M`, `1G`, `0`) in bytes. */
    private static function bytes(string $size): int
    {
        $value = (int) $size;

        return match (strtoupper(substr(trim($size), -1))) {
            'G' => $value << 30,
            'M' => $value << 20,
            'K' => $value << 10,
            default => $value,
        };
    }

    /** @return array<string, array{?string, ?string}> display_errors and enable_post_data_reading; null: the server's */
    private static function configurations(): array
    {
        return [
            'server settings' => [null, null],
            'display_errors=1, enable_post_data_reading=1' => ['1', '1'],
            'display_errors=1, enable_post_data_reading=0' => ['1', '0'],
            'display_errors=0, enable_post_data_reading=1' => ['0', '1'],
        ];
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> the endpoint's records for the invocation */
    private static function records(string $invocationId, string $messagePrefix): array
    {
        return array_values(array_filter(
            JsonLinesLogger::read(self::stateDir() . '/endpoint-log.jsonl'),
            static fn (array $record): bool => ($record['context']['restate.invocation.id'] ?? null) === $invocationId && str_starts_with($record['message'], $messagePrefix),
        ));
    }

    /**
     * The attempt's peak RSS stays within a few copies of the body: the SAPI's, PHP's string, the VM's input, the
     * decoded value and the output. The bound is generous; it catches a copy too many per byte, not noise. The base
     * covers the process itself (a debug extension, OPcache's shared memory once touched).
     */
    private static function assertMemoryWithinBounds(string $invocationId, int $bytes, string $case): void
    {
        $records = new MemoryProbe(self::stateDir())->records($invocationId);
        self::assertNotEmpty($records, "No memory record for {$case}.");
        $record = $records[array_key_last($records)];
        if (getenv('RESTATE_PHP_TEST_REPORT_MEMORY') === '1') {
            fwrite(STDERR, sprintf(
                "\n[memory] %s: RSS at handler %s MiB, peak RSS %s MiB, PHP peak %.1f MiB (body %.1f MiB)\n",
                $case,
                $record['rssAtHandlerKiB'] === null ? '?' : sprintf('%.1f', $record['rssAtHandlerKiB'] / 1024),
                $record['peakRssKiB'] === null ? '?' : sprintf('%.1f', $record['peakRssKiB'] / 1024),
                $record['phpPeakBytes'] / self::MIB,
                $bytes / self::MIB,
            ));
        }
        if ($record['peakRssKiB'] !== null) {
            self::assertLessThan(8 * $bytes + 192 * self::MIB, $record['peakRssKiB'] * 1024, "Peak RSS of {$case}.");
        }
        self::assertLessThan(4 * $bytes + 32 * self::MIB, $record['phpPeakBytes'], "PHP peak memory of {$case}.");
    }

    private function serverName(): string
    {
        return IntegrationEndpoint::server();
    }
}
