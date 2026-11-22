<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark;

use PhpBench\Attributes as Bench;
use Restate\Context;
use Restate\DurableFutureAggregateException;
use Restate\Endpoint;
use Restate\Service;
use Restate\ServiceDefinition;
use Restate\Tests\Benchmark\Support\Expect;
use Restate\Tests\Support\Protocol\FrameCodec;
use Restate\Tests\Support\Workload\InProcessDriver;
use Restate\Tests\Support\Workload\Workload;
use Restate\Tests\Support\Workload\WorkloadRequest;

/**
 * One attempt that combines n durable futures whose results are all in the journal, through the extension: what the
 * combinators cost per attempt (docs/SERVING.md, Wide Fan-out). all() and allSettled() await one tree; a
 * select() loop awaits n trees over n, n - 1, … futures; any() over n rejected awakeables takes one failure per await.
 * The SDK's share is O(1) extension calls per combinator call; shared-core re-reduces the remaining tree for each
 * result it moves into view, so the select() loop and any() stay O(n^2) in the core until that is fixed upstream.
 *
 * @phpstan-type Params array{subject: string, n: int}
 */
#[Bench\Iterations(3)]
#[Bench\Warmup(1)]
#[Bench\Revs(1)]
#[Bench\OutputTimeUnit('milliseconds', precision: 2)]
final class FanOutBench
{
    private InProcessDriver $driver;

    private WorkloadRequest $request;

    /** @param Params $params */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\ParamProviders('cases')]
    public function benchFanOut(array $params): void
    {
        $this->driver->send($this->request);
    }

    /** @param Params $params */
    public function setUp(array $params): void
    {
        $this->driver = new InProcessDriver(Endpoint::builder()->services([self::service()])->build());
        $this->request = self::request($params['subject'], $params['n']);
        Expect::expectedResponse($this->driver->send($this->request), $this->request);
    }

    /** @return \Generator<string, Params> */
    public function cases(): \Generator
    {
        foreach (['all', 'select', 'allSettled', 'any'] as $subject) {
            foreach ([10, 100, 1000, 2000] as $n) {
                yield "{$subject},n={$n}" => ['subject' => $subject, 'n' => $n];
            }
        }
    }

    /**
     * The last attempt of `FanOut/<subject>`: n completed sleeps (all, select, allSettled), or n awakeables Restate
     * rejected (any: v7 awakeables are signals 17 and up, and journal no command).
     */
    private static function request(string $subject, int $n): WorkloadRequest
    {
        $journal = [];
        if ($subject === 'any') {
            for ($i = 0; $i < $n; $i++) {
                $journal[] = FrameCodec::signalFrame(17 + $i, failure: ['code' => 409, 'message' => "rejected {$i}"]);
            }
        } else {
            for ($i = 1; $i <= $n; $i++) {
                $journal[] = FrameCodec::sleepCommandFrame(1, $i);
            }
            for ($i = 1; $i <= $n; $i++) {
                $journal[] = FrameCodec::completionFrame(FrameCodec::SLEEP_COMPLETION, $i);
            }
        }
        $body = FrameCodec::startFrame(Workload::INVOCATION_ID, 1 + count($journal))
            . FrameCodec::inputCommandFrame((string) $n)
            . implode('', $journal);

        return new WorkloadRequest("fan-out:{$subject}:{$n}", "/invoke/FanOut/{$subject}", Workload::CONTENT_TYPE, Workload::INVOCATION_ID, $body, [FrameCodec::OUTPUT_COMMAND, FrameCodec::END]);
    }

    private static function service(): ServiceDefinition
    {
        $sleeps = static function (Context $ctx, int $n): array {
            $futures = [];
            for ($i = 0; $i < $n; $i++) {
                $futures[] = $ctx->sleepFuture(1);
            }

            return $futures;
        };

        return Service::new('FanOut')
            ->handler('all', static fn (Context $ctx, int $n): int => count($ctx->all($sleeps($ctx, $n))))
            ->handler('allSettled', static fn (Context $ctx, int $n): int => count($ctx->allSettled($sleeps($ctx, $n))))
            ->handler('select', static function (Context $ctx, int $n) use ($sleeps): int {
                $futures = $sleeps($ctx, $n);
                while ($futures !== []) {
                    $key = $ctx->select($futures);
                    $futures[$key]->awaitResult();
                    unset($futures[$key]);
                }

                return $n;
            })
            ->handler('any', static function (Context $ctx, int $n): int {
                $futures = [];
                for ($i = 0; $i < $n; $i++) {
                    $futures[] = $ctx->awakeableFuture()['future'];
                }
                try {
                    $ctx->any($futures);
                } catch (DurableFutureAggregateException $e) {
                    return count($e->failures());
                }

                throw new \LogicException('any() over rejected awakeables succeeded.');
            })
            ->definition();
    }
}
