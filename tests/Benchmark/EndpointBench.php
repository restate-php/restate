<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark;

use PhpBench\Attributes as Bench;
use Restate\Endpoint;
use Restate\Tests\Benchmark\Support\DiscardingLogger;
use Restate\Tests\Benchmark\Support\Expect;
use Restate\Tests\Benchmark\Support\ScriptedBackend;
use Restate\Tests\Support\Workload\InProcessDriver;
use Restate\Tests\Support\Workload\Workload;
use Restate\Tests\Support\Workload\WorkloadRequest;
use Restate\Tests\Support\Workload\WorkloadServices;

/**
 * One request to a warm endpoint (built once, as in a long-lived worker), per request class of the workload library:
 * what an attempt costs the SDK and the extension, without HTTP. Each subject checks its response once before it is
 * timed.
 */
#[Bench\Iterations(10)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('microseconds', precision: 2)]
final class EndpointBench
{
    private Endpoint $endpoint;

    private InProcessDriver $driver;

    private WorkloadRequest $request;

    /** @var array<string, mixed> */
    private array $server = [];

    private ScriptedBackend $scripted;

    /** A health check through `$_SERVER`, as `Endpoint::serve()` gets it: 30 keys as php-fpm or `php -S` passes them. */
    #[Bench\BeforeMethods('setUpHealth')]
    #[Bench\Revs(2000)]
    public function benchHealth(): void
    {
        $this->endpoint->handleRequest($this->server, '');
    }

    public function setUpHealth(): void
    {
        $this->endpoint = Endpoint::builder()->services(WorkloadServices::definitions())->build();
        $this->server = self::server('/health');
        Expect::status(200, $this->endpoint->handleRequest($this->server, ''), 'health');
    }

    /** `Workload/echo` on a scripted invocation: the SDK's own cost of an attempt (routing, context, serde), no VM. */
    #[Bench\BeforeMethods('setUpGreetScripted')]
    #[Bench\Revs(1000)]
    public function benchGreetScripted(): void
    {
        $this->driver->send($this->request);
    }

    public function setUpGreetScripted(): void
    {
        $this->scripted = new ScriptedBackend('"Mary"');
        $this->driver = new InProcessDriver(Endpoint::create($this->scripted, WorkloadServices::definitions()));
        $this->request = Workload::start();
        Expect::same(200, $this->driver->send($this->request)->status, 'scripted greet status');
        Expect::same('"Mary"', $this->scripted->lastOutput(), 'scripted greet output');
    }

    /** The same request through the extension: the protocol frames decoded and encoded by shared-core. */
    #[Bench\BeforeMethods('setUpGreetExtension')]
    #[Bench\Revs(1000)]
    public function benchGreetExtension(): void
    {
        $this->driver->send($this->request);
    }

    public function setUpGreetExtension(): void
    {
        $this->useWorkload(Workload::start());
    }

    /**
     * The last attempt of an invocation with N completed runs: the handler replays every run and completes. Its
     * time per run should not grow with N (replay is linear in the journal).
     *
     * @param array{runs: int} $params
     */
    #[Bench\BeforeMethods('setUpReplay')]
    #[Bench\ParamProviders('replayRuns')]
    #[Bench\Revs(20)]
    public function benchReplay(array $params): void
    {
        $this->driver->send($this->request);
    }

    /** @param array{runs: int} $params */
    public function setUpReplay(array $params): void
    {
        $this->useWorkload(Workload::replayRuns($params['runs']));
    }

    /** @return \Generator<string, array{runs: int}> */
    public function replayRuns(): \Generator
    {
        foreach ([0, 10, 100, 1000] as $runs) {
            yield "runs={$runs}" => ['runs' => $runs];
        }
    }

    /** A virtual object call that reads 100 state keys Restate sent eagerly. */
    #[Bench\BeforeMethods('setUpEagerState')]
    #[Bench\Revs(100)]
    public function benchEagerState(): void
    {
        $this->driver->send($this->request);
    }

    public function setUpEagerState(): void
    {
        $this->useWorkload(Workload::eagerState(100));
    }

    /** A 1 MiB input echoed back as a 1 MiB output. */
    #[Bench\BeforeMethods('setUpLargeIo')]
    #[Bench\Revs(20)]
    public function benchLargeIo(): void
    {
        $this->driver->send($this->request);
    }

    public function setUpLargeIo(): void
    {
        $this->useWorkload(Workload::largeInput(1 << 20));
    }

    /**
     * The same requests with a logger (the SDK's logging and shared-core's log capture at INFO, the default once a
     * logger is set) and without.
     *
     * @param array{logger: bool, request: string} $params
     */
    #[Bench\BeforeMethods('setUpLogger')]
    #[Bench\ParamProviders('loggerCases')]
    #[Bench\Revs(100)]
    public function benchLogger(array $params): void
    {
        $this->driver->send($this->request);
    }

    /** @param array{logger: bool, request: string} $params */
    public function setUpLogger(array $params): void
    {
        $builder = Endpoint::builder()->services(WorkloadServices::definitions());
        if ($params['logger']) {
            $builder = $builder->logger(new DiscardingLogger());
        }
        $this->useWorkload($params['request'] === 'start' ? Workload::start() : Workload::replayRuns(100), $builder->build());
    }

    /** @return \Generator<string, array{logger: bool, request: string}> */
    public function loggerCases(): \Generator
    {
        foreach (['start', 'replay-runs:100'] as $request) {
            yield "{$request},logger=off" => ['logger' => false, 'request' => $request];
            yield "{$request},logger=on" => ['logger' => true, 'request' => $request];
        }
    }

    private function useWorkload(WorkloadRequest $request, ?Endpoint $endpoint = null): void
    {
        $this->driver = $endpoint === null ? InProcessDriver::forWorkloadServices() : new InProcessDriver($endpoint);
        $this->request = $request;
        Expect::expectedResponse($this->driver->send($request), $request);
    }

    /** @return array<string, mixed> */
    private static function server(string $uri): array
    {
        return [
            'REQUEST_URI' => $uri,
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/app/public/index.php',
            'PHP_SELF' => '/index.php',
            'DOCUMENT_ROOT' => '/app/public',
            'DOCUMENT_URI' => '/index.php',
            'QUERY_STRING' => '',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_SOFTWARE' => 'nginx/1.27.0',
            'SERVER_NAME' => 'endpoint',
            'SERVER_ADDR' => '10.0.0.2',
            'SERVER_PORT' => '9080',
            'REMOTE_ADDR' => '10.0.0.1',
            'REMOTE_PORT' => '51234',
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'REQUEST_SCHEME' => 'http',
            'CONTENT_TYPE' => '',
            'CONTENT_LENGTH' => '',
            'HTTP_HOST' => 'endpoint:9080',
            'HTTP_USER_AGENT' => 'restate/1.8.1',
            'HTTP_ACCEPT' => '*/*',
            'HTTP_TRACEPARENT' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1',
            'HTTP_X_REQUEST_ID' => 'd3b07384d113edec49eaa6238ad5ff00',
            'HTTP_CONNECTION' => 'keep-alive',
            'FCGI_ROLE' => 'RESPONDER',
            'REDIRECT_STATUS' => '200',
            'REQUEST_TIME' => 1763546400,
            'REQUEST_TIME_FLOAT' => 1763546400.5,
        ];
    }
}
