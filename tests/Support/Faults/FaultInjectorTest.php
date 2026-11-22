<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Faults;

use PHPUnit\Framework\TestCase;
use Restate\EndpointResponse;
use Restate\Tests\Support\Protocol\FrameCodec;

/**
 * The fault plan, its file format and the decisions of the fault injector, without an endpoint. The injector's
 * wiring (writing responses, killing workers) runs against Restate in tests/Integration/FaultInjectionTest.php.
 */
final class FaultInjectorTest extends TestCase
{
    private string $stateDir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/restate-php-faults-' . bin2hex(random_bytes(4));
        mkdir($this->stateDir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = glob($this->stateDir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->stateDir);
    }

    public function testFaultsDescribeThemselvesAndSurviveTheirFileFormat(): void
    {
        $faults = [
            'kill-before-output' => Fault::killBeforeOutput(),
            'kill-after-frames:2' => Fault::killAfterFrames(2),
            'truncate-after-frames:1+half' => Fault::truncateAfterFrames(1, halfFrame: true),
            'exit' => Fault::exit(),
            'delay:250ms' => Fault::delay(250),
            'kill-after-response' => Fault::killAfterResponse(),
            'respond:503' => Fault::respond(503, ['retry-after' => '2']),
        ];

        foreach ($faults as $description => $fault) {
            self::assertSame($description, $fault->describe());
            self::assertEquals($fault, Fault::fromArray($fault->toArray()));
        }
    }

    public function testFaultsRejectNegativeCountsAndUnknownActions(): void
    {
        foreach ([
            static fn () => Fault::truncateAfterFrames(-1),
            static fn () => Fault::delay(-5),
            static fn () => Fault::respond(42),
            static fn () => Fault::fromArray(['action' => 'explode']),
            static fn () => FaultPlan::forInvocation(''),
            static fn () => FaultPlan::forIdempotencyKey('k')->onAttempt(0, Fault::exit()),
        ] as $case => $invalid) {
            try {
                $invalid();
                self::fail("Case {$case} was accepted.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAPlanIsFoundByInvocationIdFirstThenByIdempotencyKey(): void
    {
        FaultPlan::forIdempotencyKey('idem-1')->onAttempt(2, Fault::exit())->write($this->stateDir);

        $byKey = FaultPlan::find($this->stateDir, 'inv_1', 'idem-1');
        self::assertNotNull($byKey);
        self::assertNull($byKey->faultFor(1));
        self::assertEquals(Fault::exit(), $byKey->faultFor(2));
        self::assertNull(FaultPlan::find($this->stateDir, 'inv_1', null));
        self::assertNull(FaultPlan::find($this->stateDir, 'inv_1', 'idem-2'));

        FaultPlan::forInvocation('inv_1')->onAttempt(3, Fault::delay(10))->onAttempt(1, Fault::killBeforeOutput())->write($this->stateDir);
        $byId = FaultPlan::find($this->stateDir, 'inv_1', 'idem-1');
        self::assertNotNull($byId);
        self::assertSame([1, 3], array_keys($byId->faults));

        $byId->remove($this->stateDir);
        self::assertEquals($byKey, FaultPlan::find($this->stateDir, 'inv_1', 'idem-1'));
    }

    public function testAPlanWithoutFaultsIsStillAPlan(): void
    {
        FaultPlan::forInvocation('inv_1')->write($this->stateDir);

        $plan = FaultPlan::find($this->stateDir, 'inv_1', null);
        self::assertNotNull($plan);
        self::assertSame([], $plan->faults);
    }

    public function testPrefixCutsAtFrameBoundariesComputedFromTheFrames(): void
    {
        $frames = [FrameCodec::runCommandFrame(1, 'charge'), FrameCodec::runCompletionSuccessFrame(1, '42'), FrameCodec::suspensionFrame([1])];
        $body = implode('', $frames);

        self::assertSame('', FaultInjector::prefix($body, 0));
        self::assertSame($frames[0], FaultInjector::prefix($body, 1));
        self::assertSame($frames[0] . $frames[1], FaultInjector::prefix($body, 2));
        self::assertSame($body, FaultInjector::prefix($body, 3));
        self::assertSame($body, FaultInjector::prefix($body, 10));

        $half = FaultInjector::prefix($body, 1, halfFrame: true);
        self::assertSame($frames[0] . substr($frames[1], 0, intdiv(strlen($frames[1]), 2)), $half);
        self::assertSame([FrameCodec::RUN_COMMAND], FrameCodec::messageTypes($frames[0]));
        // Half a frame does not parse: Restate decodes the frames before it and drops the rest.
        $this->expectException(\InvalidArgumentException::class);
        FrameCodec::frames($half);
    }

    public function testOutcomes(): void
    {
        $body = FrameCodec::runCommandFrame(1, 'charge') . FrameCodec::runCompletionSuccessFrame(1, '42') . FrameCodec::suspensionFrame([1]);
        $response = new EndpointResponse(200, [['content-type', 'application/vnd.restate.invocation.v7']], $body);

        self::assertEquals(new FaultOutcome(true, $body, FaultOutcome::COMPLETE), FaultInjector::outcome($response, null, true));
        self::assertEquals(new FaultOutcome(true, $body, FaultOutcome::COMPLETE, 300), FaultInjector::outcome($response, Fault::delay(300), true));
        self::assertEquals(new FaultOutcome(false, '', FaultOutcome::EXITED), FaultInjector::outcome($response, Fault::exit(), true));
        self::assertEquals(new FaultOutcome(false, '', FaultOutcome::KILLED), FaultInjector::outcome($response, Fault::killBeforeOutput(), true));
        // Where the process may not kill itself, a kill writes the protocol head and what it got to, then exits.
        self::assertEquals(new FaultOutcome(true, '', FaultOutcome::EXITED), FaultInjector::outcome($response, Fault::killBeforeOutput(), false));
        $two = FaultInjector::prefix($body, 2);
        self::assertEquals(new FaultOutcome(true, $two, FaultOutcome::KILLED), FaultInjector::outcome($response, Fault::killAfterFrames(2), true));
        self::assertEquals(new FaultOutcome(true, $two, FaultOutcome::EXITED), FaultInjector::outcome($response, Fault::killAfterFrames(2), false));
        self::assertEquals(new FaultOutcome(true, $two, FaultOutcome::TRUNCATED), FaultInjector::outcome($response, Fault::truncateAfterFrames(2), true));
        self::assertEquals(new FaultOutcome(true, $body, FaultOutcome::KILLED), FaultInjector::outcome($response, Fault::killAfterResponse(), true));
        self::assertEquals(new FaultOutcome(true, $body, FaultOutcome::EXITED), FaultInjector::outcome($response, Fault::killAfterResponse(), false));
        self::assertEquals(new FaultOutcome(true, '', FaultOutcome::REPLACED, status: 429, headers: ['retry-after' => '1']), FaultInjector::outcome($response, Fault::respond(429, ['retry-after' => '1']), true));
        // A cut after the last frame cuts nothing.
        self::assertEquals(new FaultOutcome(true, $body, FaultOutcome::COMPLETE), FaultInjector::outcome($response, Fault::truncateAfterFrames(3), true));
    }

    public function testTheIdempotencyKeyComesFromTheStartMessage(): void
    {
        $input = FrameCodec::inputCommandFrame('"Mary"');

        self::assertSame('idem-1', FaultInjector::idempotencyKey(FrameCodec::startFrame('inv_1', 1, idempotencyKey: 'idem-1') . $input));
        self::assertNull(FaultInjector::idempotencyKey(FrameCodec::startFrame('inv_1', 1) . $input));
        self::assertNull(FaultInjector::idempotencyKey($input));
        self::assertNull(FaultInjector::idempotencyKey(''));
        self::assertNull(FaultInjector::idempotencyKey("\x00\x00\x00\x00\x00\x00\x00\x09\x0a"));
    }

    public function testTheAttemptLogNumbersAttemptsAndKeepsTheirResponses(): void
    {
        $log = new AttemptLog($this->stateDir, 'inv_1');

        self::assertSame(1, $log->start());
        $log->respond(1, 200, [FrameCodec::RUN_COMMAND, FrameCodec::PROPOSE_RUN_COMPLETION], 'truncate-after-frames:1', FaultOutcome::TRUNCATED, 10, 30);
        self::assertSame(2, $log->start());
        self::assertSame(1, new AttemptLog($this->stateDir, 'inv_2')->start());

        $attempts = FaultInjector::attempts($this->stateDir, 'inv_1');
        self::assertCount(2, $attempts);
        self::assertSame(1, $attempts[0]['attempt']);
        self::assertSame(getmypid(), $attempts[0]['pid']);
        self::assertSame(
            ['status' => 200, 'frames' => [FrameCodec::RUN_COMMAND, FrameCodec::PROPOSE_RUN_COMPLETION], 'fault' => 'truncate-after-frames:1', 'end' => FaultOutcome::TRUNCATED, 'written' => 10, 'length' => 30],
            $attempts[0]['response'],
        );
        // The second attempt has not been answered (yet, or ever: a killed worker writes nothing more).
        self::assertSame(['attempt' => 2, 'pid' => getmypid(), 'response' => null], $attempts[1]);
        self::assertSame([], FaultInjector::attempts($this->stateDir, 'inv_3'));
    }

    public function testOnlyABuiltInServerOrFpmWorkerMayKillItself(): void
    {
        // PHPUnit's process is neither a php -S nor a php-fpm worker.
        self::assertFalse(ProcessFaults::isBuiltInServerWorker());
        self::assertFalse(ProcessFaults::isFpmWorker());
        self::assertFalse(ProcessFaults::canKillItself());
    }
}
