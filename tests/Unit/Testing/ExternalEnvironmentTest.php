<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Restate\Testing\ExternalEndpoint;
use Restate\Testing\ExternalRestate;
use Restate\Testing\Internal\Process;
use Restate\Testing\RestateTestEnvironment;
use Restate\Testing\TestEnvironmentException;

/**
 * An environment of an {@see ExternalRestate} and an {@see ExternalEndpoint}, against a `php -S` stub that answers as
 * both: Restate's health checks, admin queries and deployment registrations, and the endpoint's `/health`. The harness
 * waits for them, registers the endpoint, reads their log files, refuses every lifecycle call, and leaves them running.
 */
final class ExternalEnvironmentTest extends TestCase
{
    private string $directory = '';

    private ?Process $stub = null;

    private string $url = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/restate-php-external-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        file_put_contents($this->directory . '/router.php', <<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            header('Content-Type: application/json');
            if ($path === '/deployments' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                file_put_contents(__DIR__ . '/registered.json', file_get_contents('php://input') . "\n", FILE_APPEND);
                echo '{"id":"dp_stub"}';
            } elseif ($path === '/query') {
                echo '{"rows":[{"id":"inv_1","status":"completed"}]}';
            } elseif (in_array($path, ['/health', '/restate/health', '/endpoint/health'], true)) {
                echo '{}';
            } else {
                http_response_code(404);
            }
            PHP);
        file_put_contents($this->directory . '/endpoint.log', "endpoint line 1\nendpoint line 2\n");
        file_put_contents($this->directory . '/restate.log', "restate line 1\nrestate line 2\n");

        $port = self::freePort();
        $this->url = "http://127.0.0.1:{$port}";
        $this->stub = Process::start([PHP_BINARY, '-S', "127.0.0.1:{$port}", $this->directory . '/router.php'], $this->directory . '/stub.log');
        for ($deadline = microtime(true) + 5; @file_get_contents($this->url . '/health') === false;) {
            self::assertLessThan($deadline, microtime(true), 'The stub did not start: ' . file_get_contents($this->directory . '/stub.log'));
            usleep(20_000);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->stub?->stop();
        foreach ((array) glob($this->directory . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->directory);
    }

    public function testConnectsRegistersAndReadsTheLogsWithoutStartingAnything(): void
    {
        $environment = RestateTestEnvironment::start(
            ExternalEndpoint::at($this->url . '/endpoint')->withRestateUri('http://endpoint.test:9080/endpoint')->withLogFile($this->directory . '/endpoint.log'),
            ExternalRestate::at($this->url, $this->url . '/')->withLogFile($this->directory . '/restate.log'),
        );

        try {
            self::assertSame($this->url, $environment->adminUrl());
            self::assertSame($this->url, $environment->ingressUrl());
            self::assertSame($this->url . '/endpoint', $environment->endpointUrl());
            self::assertSame((int) parse_url($this->url, PHP_URL_PORT), $environment->endpointPort());
            self::assertSame('127.0.0.1', $environment->testHostForRestate());
            self::assertTrue($environment->isEndpointRunning());
            self::assertNull($environment->endpointContainerName());
            self::assertSame('completed', $environment->invocationRow('inv_1')['status'] ?? null);

            // start() registered the endpoint where Restate reaches it, over HTTP/1.1, and registerEndpoint() appends a path.
            self::assertSame('dp_stub', $environment->registerEndpoint('/more'));
            self::assertSame(
                [
                    ['uri' => 'http://endpoint.test:9080/endpoint', 'use_http_11' => true, 'force' => true],
                    ['uri' => 'http://endpoint.test:9080/endpoint/more', 'use_http_11' => true, 'force' => true],
                ],
                $this->registrations(),
            );

            self::assertSame("endpoint line 2\n", $environment->endpointLog(16));
            self::assertSame(32, $environment->endpointLogSize());
            self::assertSame("restate line 2\n", $environment->restateLog(1));
        } finally {
            $environment->stop();
        }

        self::assertTrue($this->stub?->isRunning(), 'stop() ended the external servers.');
        self::assertFileExists($this->directory . '/endpoint.log');
    }

    public function testRefusesEveryLifecycleCall(): void
    {
        $environment = RestateTestEnvironment::start(ExternalEndpoint::at($this->url . '/endpoint')->withHttp2(), ExternalRestate::at($this->url, $this->url));

        try {
            self::assertSame([['uri' => $this->url . '/endpoint', 'use_http_11' => false, 'force' => true]], $this->registrations());

            foreach ([
                'restartRestate' => static fn () => $environment->restartRestate(),
                'pauseRestate' => static fn () => $environment->pauseRestate(),
                'unpauseRestate' => static fn () => $environment->unpauseRestate(),
                'restateContainerName' => static fn () => $environment->restateContainerName(),
                'containerRuntime' => static fn () => $environment->containerRuntime(),
                'killEndpoint' => static fn () => $environment->killEndpoint(),
                'startEndpoint' => static fn () => $environment->startEndpoint(),
                'restartEndpoint' => static fn () => $environment->restartEndpoint(),
                'signalEndpoint' => static fn () => $environment->signalEndpoint(15),
            ] as $call => $lifecycle) {
                try {
                    $lifecycle();
                    self::fail("{$call}() did not refuse an external environment.");
                } catch (TestEnvironmentException $exception) {
                    self::assertMatchesRegularExpression('/outside the harness|runs nothing in a container/', $exception->getMessage(), $call);
                }
            }

            // Without log files, the logs are empty.
            self::assertSame('', $environment->endpointLog());
            self::assertSame(0, $environment->endpointLogSize());
            self::assertSame('', $environment->restateLog());
        } finally {
            $environment->stop();
        }
        self::assertTrue($this->stub?->isRunning());
    }

    public function testAnExternalEndpointThatDoesNotAnswerFailsTheStartWithItsUrl(): void
    {
        $missing = 'http://127.0.0.1:' . self::freePort();

        try {
            RestateTestEnvironment::start(ExternalEndpoint::at($missing), ExternalRestate::at($this->url, $this->url)->withStartupTimeout(1));
            self::fail('The environment started without its endpoint.');
        } catch (TestEnvironmentException $exception) {
            self::assertStringContainsString("the endpoint is healthy at {$missing}/health", $exception->getMessage());
        }
        self::assertSame([], $this->registrations());
    }

    public function testAnExternalRestateThatDoesNotAnswerFailsTheStartWithItsUrl(): void
    {
        $missing = 'http://127.0.0.1:' . self::freePort();

        try {
            RestateTestEnvironment::start(ExternalEndpoint::at($this->url . '/endpoint'), ExternalRestate::at($missing, $missing)->withStartupTimeout(1));
            self::fail('The environment started without Restate.');
        } catch (TestEnvironmentException $exception) {
            self::assertStringContainsString("the external Restate at {$missing}", $exception->getMessage());
        }
    }

    /** @return list<mixed> */
    private function registrations(): array
    {
        $file = $this->directory . '/registered.json';
        $lines = is_file($file) ? array_filter(explode("\n", (string) file_get_contents($file)), static fn (string $line): bool => $line !== '') : [];

        return array_values(array_map(static fn (string $line): mixed => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
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
