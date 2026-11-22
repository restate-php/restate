<?php

declare(strict_types=1);

namespace Restate\Tests\Extension;

use Restate\Tests\Support\Protocol\FrameCodec;
use Restate\Tests\Support\Workload\InProcessDriver;
use Restate\Tests\Support\Workload\Workload;

/**
 * The workload library's request bodies are journals the SDK and shared-core accept as they are: every request class,
 * sent in process to an endpoint on the extension backend, gets exactly its expected frames (a replay that diverged
 * would answer with an error frame instead). Benchmarks and soak runs rely on that.
 */
final class WorkloadTest extends ExtensionTestCase
{
    public function testEveryRequestClassGetsItsExpectedResponse(): void
    {
        $driver = InProcessDriver::forWorkloadServices();

        foreach (Workload::classes() as $class => $request) {
            $request = $request("inv_{$class}");
            // Twice: an endpoint serves many requests in a worker, and nothing of one may leak into the next.
            for ($i = 0; $i < 2; $i++) {
                $response = $driver->send($request);
                self::assertTrue($response->isExpectedFor($request), "{$class}: {$response->describe()}");
            }
        }
    }

    public function testReplayOfALongJournalCompletesWithTheSumOfItsRuns(): void
    {
        $response = InProcessDriver::forWorkloadServices()->send(Workload::replayRuns(1000));

        self::assertSame(FrameCodec::responseBody([FrameCodec::outputSuccessFrame('500500')]), $response->body, $response->describe());
    }
}
