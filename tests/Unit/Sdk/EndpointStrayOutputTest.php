<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LogLevel;
use Restate\Context;
use Restate\Endpoint;
use Restate\EndpointResponse;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\OutputCapture;
use Restate\Psr\PsrEndpointHandler;
use Restate\Service;
use Restate\Tests\Support\FakeBackend;
use Restate\Tests\Support\RecordingLogger;
use Restate\Tests\Support\ScriptedInvocation;

/**
 * Output PHP prints outside the protocol response (docs/decisions/0032-stray-output.md): what the request
 * prints is discarded and logged, and the response stays a protocol response; output PHP already sent before the
 * endpoint ran is reported, and the request is not run.
 *
 * The in-process cases drive the endpoint directly. The cases that depend on ini settings (display_errors,
 * post_max_size, output_buffering) or on what PHP printed before the script run Fixtures/stray-output-endpoint.php in
 * a child PHP: under the CLI, or under `php -S` for PHP's own request-startup warning.
 */
final class EndpointStrayOutputTest extends TestCase
{
    private const array SERVER = [
        'REQUEST_URI' => '/invoke/Greeter/greet',
        'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6',
        'HTTP_X_RESTATE_INVOCATION_ID' => 'inv_1',
    ];

    private const string DISCARDED = 'Discarded output printed while handling the request, which would have corrupted the response; log instead of printing, and set display_errors=Off with log_errors=On';

    private const string SENT_BEFORE = 'PHP sent output before the endpoint could respond, so Restate cannot read the response and retries the attempt; the endpoint did not run it.';

    private const string FIXTURE = __DIR__ . '/Fixtures/stray-output-endpoint.php';

    private RecordingLogger $logger;

    private ScriptedInvocation $invocation;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->invocation = ScriptedInvocation::new();
        $this->invocation->input = new InvocationInput('inv_1', 0, '', [], '"Mary"');
    }

    public function testWhatAHandlerEchoesIsDiscardedAndLoggedWithWhereItWasPrinted(): void
    {
        $line = __LINE__ + 2;
        $response = $this->handle(static function (Context $ctx, string $name): string {
            echo "debug: greeting {$name}\n";

            return "Hello {$name}";
        });

        self::assertProtocolResponse($response);
        self::assertSame([['"Hello Mary"']], $this->invocation->calls('writeOutputSuccess'));
        self::assertSame([LogLevel::WARNING, self::DISCARDED, [
            'restate.invocation.id' => 'inv_1',
            'restate.request.path' => '/invoke/Greeter/greet',
            'restate.output.bytes' => 21,
            'restate.output.origin' => __FILE__ . ':' . $line,
            'restate.output.excerpt' => "debug: greeting Mary\n",
        ]], $this->logger->record(self::DISCARDED));
    }

    public function testARequestThatPrintsNothingLogsNothingAboutOutput(): void
    {
        $this->handle(static fn (Context $ctx, string $name): string => "Hello {$name}");

        self::assertSame([[LogLevel::DEBUG, 'Starting invocation'], [LogLevel::DEBUG, 'Invocation completed']], $this->logger->messages());
    }

    public function testOutputOfAFailedAttemptIsDiscardedToo(): void
    {
        $response = $this->handle(static function (Context $ctx): never {
            echo 'partial';

            throw new \RuntimeException('database down');
        });

        self::assertProtocolResponse($response);
        self::assertCount(1, $this->invocation->calls('notifyError'));
        self::assertSame('partial', $this->logger->record(self::DISCARDED)[2]['restate.output.excerpt']);
    }

    public function testALogRecordCarriesOnlyAnExcerptOfLargeOutput(): void
    {
        $this->handle(static function (Context $ctx): string {
            echo str_repeat('x', 3 * 1024 * 1024);

            return 'ok';
        });

        $context = $this->logger->record(self::DISCARDED)[2];
        self::assertSame(3 * 1024 * 1024, $context['restate.output.bytes']);
        self::assertSame(str_repeat('x', OutputCapture::EXCERPT_BYTES), $context['restate.output.excerpt']);
    }

    public function testBuffersAHandlerLeavesOpenAreClosedAndTheirContentsDiscarded(): void
    {
        $level = ob_get_level();

        $response = $this->handle(static function (Context $ctx): string {
            ob_start();
            echo 'never flushed';

            return 'ok';
        });

        self::assertSame($level, ob_get_level());
        self::assertProtocolResponse($response);
        self::assertSame('never flushed', $this->logger->record(self::DISCARDED)[2]['restate.output.excerpt']);
    }

    public function testAHandlerThatEndsTheCaptureBufferIsAnError(): void
    {
        $level = ob_get_level();

        $this->handle(static function (Context $ctx): string {
            echo 'captured';
            ob_end_clean();

            return 'ok';
        });

        self::assertSame($level, ob_get_level());
        $message = 'Request code ended the output buffer the endpoint discards stray output with (an unbalanced ob_end_*() or ob_get_clean() call); output printed after that corrupts the response';
        [$logLevel, , $context] = $this->logger->record($message);
        self::assertSame(LogLevel::ERROR, $logLevel);
        self::assertSame('captured', $context['restate.output.excerpt']);
    }

    public function testThePsrHandlerKeepsHandlerOutputOutOfItsResponse(): void
    {
        $endpoint = $this->endpoint(static function (Context $ctx, string $name): string {
            echo 'from the handler';

            return "Hello {$name}";
        });

        $uri = self::createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/invoke/Greeter/greet');
        $uri->method('getQuery')->willReturn('');
        $request = self::createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaders')->willReturn(['content-type' => ['application/vnd.restate.invocation.v6'], 'x-restate-invocation-id' => ['inv_1']]);
        $request->method('getBody')->willReturn(new PsrTestStream(''));

        $response = new PsrEndpointHandler($endpoint, new PsrTestResponseFactory())->handle($request);

        self::assertSame('application/vnd.restate.invocation.v6', $response->getHeaderLine('content-type'));
        self::assertSame('', (string) $response->getBody());
        self::assertSame('from the handler', $this->logger->record(self::DISCARDED)[2]['restate.output.excerpt']);
    }

    /** With display_errors on (PHP's default without a php.ini), warnings and deprecations are printed. */
    #[DataProvider('displayedDiagnostics')]
    public function testDisplayedWarningsAndDeprecationsAreDiscardedAndLogged(string $handler, string $excerpt, int $line): void
    {
        [$stdout, $log] = self::runCli(['STRAY_OUTPUT_HANDLER' => $handler], ['display_errors' => '1', 'error_reporting' => (string) E_ALL]);

        self::assertSame('', $stdout, 'the CLI prints the (empty) response body only');
        self::assertTrue($log['handlerRan']);
        $records = array_values(array_filter($log['records'], static fn (array $record): bool => $record[1] === self::DISCARDED));
        self::assertCount(1, $records);
        self::assertSame(LogLevel::WARNING, $records[0][0]);
        self::assertStringContainsString($excerpt, $records[0][2]['restate.output.excerpt']);
        self::assertSame(realpath(self::FIXTURE) . ':' . $line, $records[0][2]['restate.output.origin']);
        self::assertContains(['debug', 'Invocation completed', ['restate.invocation.id' => 'inv_1', 'restate.invocation.target' => 'Greeter/greet']], $log['records']);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: int}> */
    public static function displayedDiagnostics(): iterable
    {
        yield 'echo' => ['echo', 'debug output from the handler', 45];
        yield 'E_USER_WARNING' => ['user-warning', 'Warning: a warning from the handler', 47];
        yield 'E_DEPRECATED' => ['deprecation', 'Deprecated: Function lcg_value() is deprecated', 50];
    }

    public function testOutputSentBeforeTheEndpointRanIsReportedAndTheRequestDoesNotRun(): void
    {
        [$stdout, $log] = self::runCli(['STRAY_OUTPUT_BEFORE' => 'printed by the script'], []);

        $origin = realpath(self::FIXTURE) . ':27';
        self::assertStringStartsWith("printed by the scriptRestate endpoint error: PHP sent output before the endpoint could respond (output started {$origin}), so this is not a Restate protocol response and the request did not run. Set display_errors=Off with log_errors=On", $stdout);
        self::assertFalse($log['handlerRan']);
        self::assertCount(1, $log['records']);
        self::assertSame('error', $log['records'][0][0]);
        self::assertStringStartsWith(self::SENT_BEFORE, $log['records'][0][1]);
        self::assertStringContainsString('enable_post_data_reading=0', $log['records'][0][1]);
        self::assertSame(['restate.invocation.id' => 'inv_1', 'restate.request.path' => '/invoke/Greeter/greet', 'restate.output.origin' => $origin], $log['records'][0][2]);
    }

    /**
     * The reproduction on record: a body over post_max_size makes PHP print a startup warning before the script runs.
     * The response has gone out as text/html; the endpoint says so, in its log and in the body, and runs nothing.
     * PHP 8.6's built-in server answers 413 itself instead (below).
     */
    #[RequiresPhp('< 8.6.0-dev')]
    public function testAStartupWarningForABodyOverPostMaxSizeIsReportedAsOutputBeforeTheScript(): void
    {
        [$response, $log] = self::runServer(['display_errors' => '1', 'post_max_size' => '1K'], [], str_repeat('x', 2048));

        self::assertStringContainsString('content-type: text/html', strtolower($response['head']));
        self::assertStringContainsString('POST Content-Length of 2048 bytes exceeds the limit of 1024 bytes', $response['body']);
        self::assertStringContainsString('Restate endpoint error: PHP sent output before the endpoint could respond (output started before the script ran: a PHP startup warning, such as a request body over post_max_size, or an auto_prepend_file)', $response['body']);
        self::assertStringNotContainsString('Cannot modify header information', $response['body']);
        self::assertFalse($log['handlerRan']);
        self::assertCount(1, $log['records']);
        self::assertSame('error', $log['records'][0][0]);
        self::assertStringStartsWith(self::SENT_BEFORE, $log['records'][0][1]);
        self::assertStringStartsWith('before the script ran', $log['records'][0][2]['restate.output.origin']);
    }

    /** The documented fix: enable_post_data_reading=0 removes the startup warning; the SDK reads php://input itself. */
    #[RequiresPhp('< 8.6.0-dev')]
    public function testEnablePostDataReadingOffRemovesThePostMaxSizeWarning(): void
    {
        [$response, $log] = self::runServer(['display_errors' => '1', 'post_max_size' => '1K', 'enable_post_data_reading' => '0'], [], str_repeat('x', 2048));

        self::assertStringContainsString('content-type: application/vnd.restate.invocation.v6', strtolower($response['head']));
        self::assertSame('', $response['body']);
        self::assertTrue($log['handlerRan']);
        self::assertSame([['debug', 'Starting invocation'], ['debug', 'Invocation completed']], array_map(static fn (array $record): array => [$record[0], $record[1]], $log['records']));
    }

    /**
     * PHP 8.6's built-in server checks post_max_size before it runs the script, whatever enable_post_data_reading
     * says: a body over it gets a 413 from the server, and the endpoint never runs.
     */
    #[RequiresPhp('>= 8.6.0-dev')]
    public function testThePhp86BuiltInServerAnswers413ForABodyOverPostMaxSizeWithoutRunningTheScript(): void
    {
        // runServer checks that the script wrote no log, so it never ran.
        [$response] = self::runServer(['display_errors' => '1', 'post_max_size' => '1K', 'enable_post_data_reading' => '0'], [], str_repeat('x', 2048), expectLog: false);

        self::assertStringStartsWith('HTTP/1.1 413 ', $response['head']);
    }

    /** The fix on PHP 8.6's built-in server: no post_max_size limit (0), with enable_post_data_reading=0 as before. */
    #[RequiresPhp('>= 8.6.0-dev')]
    public function testNoPostMaxSizeLimitServesALargeBodyOnThePhp86BuiltInServer(): void
    {
        [$response, $log] = self::runServer(['display_errors' => '1', 'post_max_size' => '0', 'enable_post_data_reading' => '0'], [], str_repeat('x', 2048));

        self::assertStringContainsString('content-type: application/vnd.restate.invocation.v6', strtolower($response['head']));
        self::assertTrue($log['handlerRan']);
    }

    /** Output that PHP still holds in its own buffer (output_buffering, as php.ini-production sets it) can be discarded. */
    public function testOutputStillInPhpsOutputBufferIsDiscardedAndTheResponseIsIntact(): void
    {
        [$response, $log] = self::runServer(['display_errors' => '1', 'output_buffering' => '4096'], ['STRAY_OUTPUT_BEFORE' => 'printed by the script'], 'request-body');

        self::assertStringContainsString('content-type: application/vnd.restate.invocation.v6', strtolower($response['head']));
        self::assertSame('', $response['body']);
        self::assertTrue($log['handlerRan']);
        self::assertSame('warning', $log['records'][0][0]);
        self::assertStringStartsWith('Discarded output PHP printed before the endpoint handled the request', $log['records'][0][1]);
        self::assertSame(['restate.invocation.id' => 'inv_1', 'restate.request.path' => '/invoke/Greeter/greet', 'restate.output.bytes' => 21, 'restate.output.origin' => 'unknown', 'restate.output.excerpt' => 'printed by the script'], $log['records'][0][2]);
    }

    private static function assertProtocolResponse(EndpointResponse $response): void
    {
        self::assertSame(200, $response->statusCode);
        self::assertSame([['content-type', 'application/vnd.restate.invocation.v6']], $response->headers);
        self::assertSame('', $response->body, 'ScriptedInvocation produces no frames: nothing else may be in the body');
    }

    private function endpoint(\Closure $handler): Endpoint
    {
        return Endpoint::create(new FakeBackend($this->invocation), [Service::new('Greeter')->handler('greet', $handler)], logger: $this->logger);
    }

    private function handle(\Closure $handler): EndpointResponse
    {
        return $this->endpoint($handler)->handleRequest(self::SERVER, '');
    }

    /**
     * @param array<string, string> $env
     * @param array<string, string> $ini
     *
     * @return array{0: string, 1: array{records: list<array{0: string, 1: string, 2: array<string, mixed>}>, handlerRan: bool}}
     */
    private static function runCli(array $env, array $ini): array
    {
        $logFile = self::logFile();
        $process = proc_open([PHP_BINARY, ...self::iniArguments($ini), self::FIXTURE], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, self::environment($env, $logFile));
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr . $stdout);

        return [$stdout, self::readLog($logFile)];
    }

    /**
     * Serves the fixture with `php -S` and POSTs $body to /invoke/Greeter/greet with a Content-Length.
     *
     * @param array<string, string> $ini
     * @param array<string, string> $env
     *
     * @return ($expectLog is true ? array{0: array{head: string, body: string}, 1: array{records: list<array{0: string, 1: string, 2: array<string, mixed>}>, handlerRan: bool}} : array{0: array{head: string, body: string}, 1: null})
     */
    private static function runServer(array $ini, array $env, string $body, bool $expectLog = true): array
    {
        $logFile = self::logFile();
        $port = self::freePort();
        $process = proc_open(
            [PHP_BINARY, ...self::iniArguments($ini), '-S', "127.0.0.1:{$port}", self::FIXTURE],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            self::environment($env, $logFile),
        );
        self::assertIsResource($process);

        try {
            $connection = null;
            for ($deadline = microtime(true) + 10; $connection === null && microtime(true) < $deadline;) {
                $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", timeout: 1);
                $connection = $socket === false ? null : $socket;
                if ($connection === null) {
                    usleep(20_000);
                }
            }
            self::assertNotNull($connection, 'php -S did not start');
            fwrite($connection, "POST /invoke/Greeter/greet HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Type: application/vnd.restate.invocation.v6\r\nX-Restate-Invocation-Id: inv_1\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n{$body}");
            stream_set_timeout($connection, 10);
            $raw = (string) stream_get_contents($connection);
            fclose($connection);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }

        [$head, $responseBody] = explode("\r\n\r\n", $raw, 2) + [1 => ''];

        if (!$expectLog) {
            self::assertFileDoesNotExist($logFile, 'the script did not run');

            return [['head' => $head, 'body' => $responseBody], null];
        }

        return [['head' => $head, 'body' => $responseBody], self::readLog($logFile)];
    }

    /**
     * @param array<string, string> $ini
     *
     * @return list<string>
     */
    private static function iniArguments(array $ini): array
    {
        $arguments = [];
        foreach ($ini as $name => $value) {
            $arguments[] = '-d';
            $arguments[] = "{$name}={$value}";
        }

        return $arguments;
    }

    /**
     * @param array<string, string> $env
     *
     * @return array<string, string>
     */
    private static function environment(array $env, string $logFile): array
    {
        return ['STRAY_OUTPUT_LOG' => $logFile] + $env + getenv();
    }

    private static function logFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'restate-stray-output-');
        self::assertIsString($file);
        unlink($file);

        return $file;
    }

    /** @return array{records: list<array{0: string, 1: string, 2: array<string, mixed>}>, handlerRan: bool} */
    private static function readLog(string $file): array
    {
        self::assertFileExists($file, 'the fixture writes its log at shutdown');
        $log = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        unlink($file);
        self::assertIsArray($log);

        /** @var array{records: list<array{0: string, 1: string, 2: array<string, mixed>}>, handlerRan: bool} */
        return $log;
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
