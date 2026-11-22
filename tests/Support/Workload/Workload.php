<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Workload;

use Restate\Tests\Support\Protocol\FrameCodec;

/**
 * Realistic request bodies for an endpoint serving {@see WorkloadServices}, built with {@see FrameCodec} as Restate
 * sends them (start message, input command, then the journal Restate has), one named constructor per request class.
 * Each request knows the frames a correct response has, so a driver can check every answer, not just time it.
 *
 * Drive them with an {@see InProcessDriver} (benchmarks: `Endpoint::handleHttpRequest()` directly) or an
 * {@see HttpDriver} (soak tests: chunked bodies with no `Content-Length` to an endpoint URL, as Restate sends them).
 */
final class Workload
{
    /** Service protocol v7, what Restate 1.8 negotiates. */
    public const string CONTENT_TYPE = 'application/vnd.restate.invocation.v7';

    public const string INVOCATION_ID = 'inv_1workload0000000000000000000000';

    private function __construct()
    {
    }

    /**
     * Every request class with typical parameters, by name, for a run that goes through them one by one (a soak run
     * pins a leak to a class by running each alone first).
     *
     * @return array<string, \Closure(string): WorkloadRequest> invocation id => request
     */
    public static function classes(): array
    {
        return [
            'start' => static fn (string $id): WorkloadRequest => self::start(invocationId: $id),
            'replay-runs:10' => static fn (string $id): WorkloadRequest => self::replayRuns(10, $id),
            'replay-runs:100' => static fn (string $id): WorkloadRequest => self::replayRuns(100, $id),
            'eager-state:100' => static fn (string $id): WorkloadRequest => self::eagerState(100, $id),
            'large-input:1048576' => static fn (string $id): WorkloadRequest => self::largeInput(1 << 20, $id),
            'suspension' => static fn (string $id): WorkloadRequest => self::suspension($id),
            'failing-attempt' => static fn (string $id): WorkloadRequest => self::failingAttempt($id),
            'terminal-failure' => static fn (string $id): WorkloadRequest => self::terminalFailure($id),
            'journal-mismatch' => static fn (string $id): WorkloadRequest => self::journalMismatch(substr($id, -16), $id),
            'logging' => static fn (string $id): WorkloadRequest => self::logging(substr($id, -16), $id),
        ];
    }

    /** A first attempt: start message and input, nothing journaled yet. `Workload/echo` answers with the input. */
    public static function start(string $input = 'Mary', string $invocationId = self::INVOCATION_ID): WorkloadRequest
    {
        return self::request('start', '/invoke/Workload/echo', $invocationId, json_encode($input, JSON_THROW_ON_ERROR), [], [FrameCodec::OUTPUT_COMMAND, FrameCodec::END]);
    }

    /**
     * A retry after `$runs` sequential runs completed: the journal has each run command and its completion, so the
     * handler replays them all and completes (the request a long journal's last attempt is).
     */
    public static function replayRuns(int $runs, string $invocationId = self::INVOCATION_ID): WorkloadRequest
    {
        $journal = [];
        for ($step = 1; $step <= $runs; $step++) {
            $journal[] = FrameCodec::runCommandFrame($step, "step-{$step}");
            $journal[] = FrameCodec::completionFrame(FrameCodec::RUN_COMPLETION, $step, value: (string) $step);
        }

        return self::request("replay-runs:{$runs}", '/invoke/Workload/runs', $invocationId, (string) $runs, $journal, [FrameCodec::OUTPUT_COMMAND, FrameCodec::END]);
    }

    /** A virtual object call whose state Restate sends up front (eager state): `$keys` keys, all read by the handler. */
    public static function eagerState(int $keys, string $invocationId = self::INVOCATION_ID): WorkloadRequest
    {
        $state = [];
        for ($key = 0; $key < $keys; $key++) {
            $state["k{$key}"] = '1';
        }

        // Each read of a state key Restate sent is journaled as it completes: one command per key, no round trip.
        $expected = [...array_fill(0, $keys, FrameCodec::GET_EAGER_STATE_COMMAND), FrameCodec::OUTPUT_COMMAND, FrameCodec::END];

        return self::request("eager-state:{$keys}", '/invoke/WorkloadObject/readState', $invocationId, (string) $keys, [], $expected, 'workload', $state);
    }

    /** An input of about `$bytes` bytes (a JSON string), echoed back. */
    public static function largeInput(int $bytes, string $invocationId = self::INVOCATION_ID): WorkloadRequest
    {
        return self::request("large-input:{$bytes}", '/invoke/Workload/echo', $invocationId, json_encode(str_repeat('x', max(0, $bytes - 2)), JSON_THROW_ON_ERROR), [], [FrameCodec::OUTPUT_COMMAND, FrameCodec::END]);
    }

    /** A handler that sleeps: the request has no completion for it, so the attempt ends in a suspension. */
    public static function suspension(string $invocationId = self::INVOCATION_ID): WorkloadRequest
    {
        return self::request('suspension', '/invoke/Workload/sleep', $invocationId, '60000', [], [FrameCodec::SLEEP_COMMAND, FrameCodec::SUSPENSION]);
    }

    /** A handler that throws: the attempt fails with an error message, and Restate would retry it. */
    public static function failingAttempt(string $invocationId = self::INVOCATION_ID): WorkloadRequest
    {
        return self::request('failing-attempt', '/invoke/Workload/fail', $invocationId, '"workload failure"', [], [FrameCodec::ERROR]);
    }

    /** A handler that throws a terminal error: the attempt writes the failure as the output and ends. */
    public static function terminalFailure(string $invocationId = self::INVOCATION_ID): WorkloadRequest
    {
        return self::request('terminal-failure', '/invoke/Workload/terminal', $invocationId, '"workload terminal failure"', [], [FrameCodec::OUTPUT_COMMAND, FrameCodec::END]);
    }

    /**
     * A retry whose journal has a run named `mismatch-<token>` where the handler runs `step`: shared-core reports a
     * journal mismatch (570), as an error frame. With shared-core's events captured at debug, the endpoint logs one
     * record `Failed with error (570) …` whose message names the token, so a test can tell which invocation's record
     * it is without trusting the record's own context.
     */
    public static function journalMismatch(string $token, string $invocationId = self::INVOCATION_ID): WorkloadRequest
    {
        $journal = [FrameCodec::runCommandFrame(1, "mismatch-{$token}"), FrameCodec::completionFrame(FrameCodec::RUN_COMPLETION, 1, value: '"x"')];

        return self::request('journal-mismatch', '/invoke/Workload/run', $invocationId, '"step"', $journal, [FrameCodec::ERROR]);
    }

    /**
     * A first attempt of `Workload/run` with this run name: the run executes and the attempt ends with its proposed
     * completion. With the panic probe's name, a debug build of the extension panics inside `sysRun()` instead.
     */
    public static function run(string $name, string $invocationId = self::INVOCATION_ID): WorkloadRequest
    {
        return self::request('run', '/invoke/Workload/run', $invocationId, json_encode($name, JSON_THROW_ON_ERROR), [], [FrameCodec::RUN_COMMAND, FrameCodec::PROPOSE_RUN_COMPLETION, FrameCodec::SUSPENSION]);
    }

    /** A handler that logs `$marker` through its context's logger (the context and its logger point at each other). */
    public static function logging(string $marker, string $invocationId = self::INVOCATION_ID): WorkloadRequest
    {
        return self::request('logging', '/invoke/Workload/log', $invocationId, json_encode($marker, JSON_THROW_ON_ERROR), [], [FrameCodec::OUTPUT_COMMAND, FrameCodec::END]);
    }

    /**
     * @param list<string> $journal frames after the input command
     * @param list<int> $expectedFrames
     * @param array<string, string> $state
     */
    private static function request(string $class, string $path, string $invocationId, string $input, array $journal, array $expectedFrames, string $key = '', array $state = []): WorkloadRequest
    {
        $body = FrameCodec::startFrame($invocationId, 1 + count($journal), $key, $state)
            . FrameCodec::inputCommandFrame($input)
            . implode('', $journal);

        return new WorkloadRequest($class, $path, self::CONTENT_TYPE, $invocationId, $body, $expectedFrames);
    }
}
