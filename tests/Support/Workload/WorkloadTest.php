<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Workload;

use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;
use Restate\Testing\Internal\Process;
use Restate\Tests\Support\Protocol\FrameCodec;

/**
 * The workload library's request bodies and its HTTP driver, without the extension. That the bodies are journals the
 * SDK replays without a mismatch is tests/Extension/WorkloadTest.php (in process) and
 * tests/Integration/WorkloadTest.php (over HTTP).
 */
final class WorkloadTest extends TestCase
{
    public function testEveryRequestClassIsAStartMessageInputAndJournal(): void
    {
        foreach (Workload::classes() as $class => $request) {
            $request = $request('inv_test');
            $frames = FrameCodec::frames($request->body);

            self::assertSame($class, $request->class);
            self::assertSame(Workload::CONTENT_TYPE, $request->contentType);
            self::assertSame(0x0000, $frames[0]['type'], $class);
            $start = FrameCodec::fields($frames[0]['payload']);
            self::assertSame(['inv_test'], $start[1], $class);
            self::assertSame([count($frames) - 1], $start[3], "{$class}: known entries");
            self::assertSame(0x0400, $frames[1]['type'], "{$class}: input command");
            self::assertNotSame([], $request->expectedFrames);
            self::assertSame([['content-type', Workload::CONTENT_TYPE], ['x-restate-invocation-id', 'inv_test']], $request->headers());
        }
    }

    public function testReplayRunsJournalsEveryRunAndItsCompletion(): void
    {
        $types = FrameCodec::messageTypes(Workload::replayRuns(3)->body);

        self::assertSame([0x0000, 0x0400, ...array_merge(...array_fill(0, 3, [FrameCodec::RUN_COMMAND, FrameCodec::RUN_COMPLETION]))], $types);
        self::assertSame('/invoke/Workload/runs', Workload::replayRuns(3)->path);
    }

    public function testEagerStateSendsEveryKeyUpFront(): void
    {
        $start = FrameCodec::fields(FrameCodec::frames(Workload::eagerState(5)->body)[0]['payload']);

        self::assertCount(5, $start[4]);
        self::assertSame(['workload'], $start[6]);
        self::assertArrayNotHasKey(5, $start, 'The state is complete, not partial.');
    }

    public function testLargeInputHasTheRequestedSize(): void
    {
        $request = Workload::largeInput(1 << 20);

        self::assertEqualsWithDelta(1 << 20, strlen($request->body), 128);
    }

    public function testParseHandlesChunkedLengthDelimitedAndCloseDelimitedBodies(): void
    {
        $chunked = HttpDriver::parse("HTTP/1.1 200 OK\r\nContent-Type: application/vnd.restate.invocation.v7\r\nTransfer-Encoding: chunked\r\n\r\n3\r\nabc\r\n2;ext=1\r\nde\r\n0\r\n\r\n");
        self::assertSame(200, $chunked->status);
        self::assertSame('abcde', $chunked->body);
        self::assertSame('application/vnd.restate.invocation.v7', $chunked->header('Content-Type'));

        self::assertSame('abc', HttpDriver::parse("HTTP/1.1 500 Internal Server Error\r\nContent-Length: 3\r\n\r\nabcdef")->body);
        self::assertSame('until close', HttpDriver::parse("HTTP/1.0 200 OK\r\nConnection: close\r\n\r\nuntil close")->body);

        $this->expectException(\RuntimeException::class);
        HttpDriver::parse('');
    }

    public function testResponsesAreCheckedAgainstTheExpectedFrames(): void
    {
        $request = Workload::start();
        $body = FrameCodec::outputSuccessFrame('"Mary"') . FrameCodec::frame("\x00\x03", '');
        $protocol = [['content-type', Workload::CONTENT_TYPE]];

        self::assertTrue(new WorkloadResponse(200, $protocol, $body)->isExpectedFor($request));
        self::assertFalse(new WorkloadResponse(500, $protocol, $body)->isExpectedFor($request));
        self::assertFalse(new WorkloadResponse(200, [['content-type', 'text/html']], $body)->isExpectedFor($request));
        // Stray output in front of the frames.
        $corrupted = new WorkloadResponse(200, $protocol, "Warning: …\n" . $body);
        self::assertFalse($corrupted->isExpectedFor($request));
        self::assertNull($corrupted->frameTypes());
        self::assertStringContainsString('unparseable', $corrupted->describe());
    }

    #[RequiresOperatingSystem('Linux')]
    public function testTheHttpDriverSendsChunkedBodiesWithoutContentLengthAsRestateDoes(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'restate-php-workload-');
        $port = self::freePort();
        $server = Process::start(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'enable_post_data_reading=0', '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/inspect-request.php'],
            $log,
            ['PHP_CLI_SERVER_WORKERS' => '2'],
        );

        try {
            $driver = new HttpDriver("http://127.0.0.1:{$port}/base", chunkSize: 4096);
            $deadline = microtime(true) + 10;
            while (@fsockopen('127.0.0.1', $port) === false && microtime(true) < $deadline) {
                usleep(50_000);
            }

            $request = Workload::largeInput(100_000, 'inv_http');
            $seen = [];
            for ($i = 0; $i < 6; $i++) {
                $response = $driver->send($request);
                self::assertSame(200, $response->status, $response->body . file_get_contents($log));
                $info = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($info);
                self::assertSame('/base/invoke/Workload/echo', $info['uri']);
                self::assertSame(Workload::CONTENT_TYPE, $info['contentType']);
                self::assertSame('chunked', $info['transferEncoding']);
                self::assertNull($info['contentLength']);
                self::assertSame('inv_http', $info['invocationId']);
                self::assertSame(strlen($request->body), $info['length']);
                self::assertSame(hash('sha256', $request->body), $info['sha256']);
                $seen[] = [$info['pid'], $info['worker']];
            }

            // php -S serves requests from its master too; only its forked workers count as workers.
            foreach ($seen as [$pid, $worker]) {
                self::assertSame($pid !== $server->pid(), $worker, json_encode($seen, JSON_THROW_ON_ERROR));
            }
        } finally {
            $server->stop();
            @unlink($log);
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
