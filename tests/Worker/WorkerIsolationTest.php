<?php

declare(strict_types=1);

namespace Restate\Tests\Worker;

use PHPUnit\Framework\Attributes\Group;
use Restate\Testing\EndpointContainer;
use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\IntegrationEndpoint;
use Restate\Tests\Integration\Support\JsonLinesLogger;
use Restate\Tests\Integration\Support\WorkerProbe;
use Restate\Tests\Support\Workload\HttpDriver;
use Restate\Tests\Support\Workload\Workload;

/**
 * What one invocation can see of another when a long-lived worker serves both (tests/Integration/Services/
 * worker_isolation.php). The endpoint has one worker thread, in an environment of its own, so consecutive requests
 * really reach the same PHP thread and the same worker script run (each test checks the run did not change under it,
 * except the last, which restarts it on purpose).
 */
#[Group('managed')]
final class WorkerIsolationTest extends WorkerTestCase
{
    #[\Override]
    protected static function restateEndpoint(): EndpointContainer
    {
        return IntegrationEndpoint::withWorkerThreads(1);
    }

    public function testInstanceBoundServicesAreSharedAndFactoryBoundOnesAreNew(): void
    {
        $worker = self::workerStats()['instance'];

        $instance = [];
        $factory = [];
        for ($i = 0; $i < 20; ++$i) {
            $instance[] = self::which('InstanceBoundProbe');
            $factory[] = self::which('FactoryBoundProbe');
        }

        self::assertSame([$worker], array_values(array_unique(array_column([...$instance, ...$factory], 'worker'))), 'The worker script restarted during the test.');
        // Built once with the endpoint, for the worker's lifetime: state on it is shared by every invocation.
        self::assertCount(1, array_unique(array_column($instance, 'id')));
        self::assertSame([1], array_values(array_unique(array_column($instance, 'construction'))));
        // Built per attempt: nothing carries over.
        self::assertCount(20, array_unique(array_column($factory, 'id')));
        $constructions = array_column($factory, 'construction');
        self::assertSame($constructions, array_values(array_unique($constructions)));
        self::assertSame($constructions, (static function (array $sorted): array {
            sort($sorted);

            return $sorted;
        })($constructions));
    }

    public function testAContextKeptFromAnEarlierInvocationFailsLoudly(): void
    {
        $worker = json_decode(self::invoke('WorkerIsolation/stash', null), true, flags: JSON_THROW_ON_ERROR);
        $stale = json_decode(self::invoke('WorkerIsolation/useStale', null), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($stale);
        self::assertSame($worker, $stale['worker'], 'The worker script restarted between the two invocations.');
        self::assertTrue($stale['stashed']);
        self::assertTrue($stale['threw'], 'A run on the context of a finished invocation went through.');
        self::assertSame('Restate\\Internal\\BackendException', $stale['class']);
        self::assertStringContainsString('already been freed', $stale['message']);
    }

    public function testEveryLogRecordCarriesTheInvocationThatLoggedIt(): void
    {
        $worker = self::workerStats()['instance'];

        $markers = [];
        for ($i = 0; $i < 50; ++$i) {
            $marker = bin2hex(random_bytes(8));
            $markers[$marker] = json_decode(self::invoke('WorkerIsolation/log', json_encode($marker, JSON_THROW_ON_ERROR)), true, flags: JSON_THROW_ON_ERROR);
        }

        $logged = [];
        foreach (JsonLinesLogger::read(self::stateDir() . '/endpoint-log.jsonl') as $record) {
            $marker = $record['context']['marker'] ?? null;
            if ($record['message'] === 'worker isolation marker' && is_string($marker) && isset($markers[$marker])) {
                $logged[$marker][] = $record['context']['restate.invocation.id'] ?? null;
            }
        }

        foreach ($markers as $marker => $invocationId) {
            self::assertSame([$invocationId], $logged[$marker] ?? [], "The record of marker {$marker} names another invocation, or is missing.");
        }
        self::assertSame($worker, self::workerStats()['instance'], 'The worker script restarted during the test.');
    }

    /**
     * A request whose output PHP sent itself (`/__worker/echo` prints and flushes, so PHP sends its own headers) must
     * not leave `headers_sent()` set for the next request on the same thread: Endpoint::handle() would then refuse to
     * run it (docs/decisions/0032-stray-output.md, case b).
     */
    public function testHeadersSentIsResetForTheNextRequest(): void
    {
        $worker = self::workerStats()['instance'];
        $driver = new HttpDriver(self::restate()->endpointUrl());

        for ($i = 0; $i < 5; ++$i) {
            $echo = HttpClient::get(self::restate()->endpointUrl() . WorkerProbe::PATH . '/echo');
            self::assertSame("worker probe output\n", $echo->body);

            $request = Workload::start(invocationId: 'inv_1workload' . bin2hex(random_bytes(8)));
            $response = $driver->send($request);
            self::assertTrue($response->isExpectedFor($request), $response->describe());
            self::assertSame($worker, $response->header(WorkerProbe::HEADER_INSTANCE));
        }
        self::assertInvocationReturns('"Hello Mary"', 'Greeter/greet', '"Mary"');
    }

    /**
     * `exit()` and a fatal error end the worker script; FrankenPHP starts it again (with a new instance id). The attempt
     * that ended it is retried and succeeds, and so do invocations that arrive while the worker restarts.
     */
    public function testExitAndFatalErrorsRestartTheWorkerAndEveryInvocationCompletes(): void
    {
        foreach (['Chaos/exitInHandler', 'Chaos/exhaustMemoryInHandler'] as $path) {
            $before = self::workerStats()['instance'];

            $failing = self::sendInvocation($path, '{"times":1}');
            $others = [];
            for ($i = 0; $i < 10; ++$i) {
                $others[] = self::sendInvocation('Greeter/greet', json_encode("worker {$i}", JSON_THROW_ON_ERROR));
            }

            $result = self::attachInvocation($failing, timeout: 30.0);
            self::assertSame(200, $result->status, "{$path}: {$result->body}");
            self::assertSame('2', $result->body, "{$path}: the second attempt completes");
            foreach ($others as $i => $invocationId) {
                $other = self::attachInvocation($invocationId, timeout: 30.0);
                self::assertSame(200, $other->status, $other->body);
                self::assertSame(json_encode("Hello worker {$i}"), $other->body);
            }

            self::assertNotSame($before, self::workerStats()['instance'], "{$path} did not restart the worker script.");
        }
    }

    /** @return array{id: string, construction: int, worker: ?string, pid: int} */
    private static function which(string $service): array
    {
        /** @var array{id: string, construction: int, worker: ?string, pid: int} */
        return json_decode(self::invoke("{$service}/which", null), true, flags: JSON_THROW_ON_ERROR);
    }
}
