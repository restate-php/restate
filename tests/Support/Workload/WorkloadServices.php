<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Workload;

use Restate\Context;
use Restate\InvocationRetryPolicy;
use Restate\ObjectContext;
use Restate\Service;
use Restate\ServiceDefinition;
use Restate\TerminalError;
use Restate\VirtualObject;

/**
 * The handlers the {@see Workload} request bodies are journals of. Every endpoint a workload drives must serve them:
 * the integration endpoint does (tests/Integration/Support/services.php), and {@see InProcessDriver::forWorkloadServices()}
 * builds an endpoint with only these.
 *
 * - `Workload/echo(string)`: returns its input.
 * - `Workload/runs(int n)`: n sequential runs `step-1`…`step-n`, the i-th returning i; returns their sum.
 * - `Workload/sleep(int ms)`: a durable sleep, so a request without the sleep's completion suspends.
 * - `Workload/fail(string)`: throws, so the attempt fails (retryable; Restate gives up after 3 attempts).
 * - `Workload/terminal(string)`: throws a terminal error, so the invocation fails for good.
 * - `Workload/run(string name)`: one run with that name, returning it (a journal with another name mismatches).
 * - `Workload/log(string marker)`: logs the marker through the handler's logger and returns it.
 * - `WorkloadObject/readState(int k)`: reads the keys `k0`…`k{k-1}` (ints) and returns their sum.
 */
final class WorkloadServices
{
    private function __construct()
    {
    }

    /** @return list<ServiceDefinition> */
    public static function definitions(): array
    {
        $retries = new InvocationRetryPolicy(initialIntervalMillis: 100, exponentiationFactor: 1.0, maxAttempts: 3, onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_KILL);

        return [
            Service::new('Workload', invocationRetryPolicy: $retries)
                ->handler('echo', static fn (Context $ctx, string $input): string => $input)
                ->handler('runs', static function (Context $ctx, int $runs): int {
                    $sum = 0;
                    for ($step = 1; $step <= $runs; $step++) {
                        $sum += $ctx->run("step-{$step}", static fn (): int => $step);
                    }

                    return $sum;
                })
                ->handler('sleep', static function (Context $ctx, int $milliseconds): string {
                    $ctx->sleep($milliseconds);

                    return 'woke';
                })
                ->handler('fail', static fn (Context $ctx, string $reason): never => throw new \RuntimeException($reason))
                ->handler('terminal', static fn (Context $ctx, string $reason): never => throw new TerminalError($reason, 409))
                ->handler('run', static fn (Context $ctx, string $name): string => $ctx->run($name, static fn (): string => $name))
                ->handler('log', static function (Context $ctx, string $marker): string {
                    $ctx->logger()->info('workload marker', ['marker' => $marker]);

                    return $marker;
                })
                ->definition(),
            VirtualObject::new('WorkloadObject', invocationRetryPolicy: $retries)
                ->handler('readState', static function (ObjectContext $ctx, int $keys): int {
                    $sum = 0;
                    for ($key = 0; $key < $keys; $key++) {
                        $sum += (int) $ctx->get("k{$key}");
                    }

                    return $sum;
                })
                ->definition(),
        ];
    }
}
