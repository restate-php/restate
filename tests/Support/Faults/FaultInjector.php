<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Faults;

use Restate\Endpoint;
use Restate\EndpointResponse;
use Restate\Tests\Support\Protocol\FrameCodec;

/**
 * Middleware around `Endpoint` for failure tests, for a classic router script (`php -S`, php-fpm) and for a worker
 * loop alike: `$faults->serve($endpoint, $_SERVER, file_get_contents('php://input'))` instead of
 * `$endpoint->handle(…)`.
 *
 * It does nothing unless enabled (RESTATE_PHP_TEST_FAULTS=1, {@see fromEnvironment()}). Enabled, it counts the
 * attempts of every invocation by the `x-restate-invocation-id` header Restate sends on each one, in its own log in
 * the state directory, and looks for a {@see FaultPlan}. Without a plan the request goes to `Endpoint::handle()`
 * unchanged. With one, the endpoint handles the request (`handleRequest()`: the handler and its runs execute in
 * full), the injector records the response's frame types, and then applies the plan's {@see Fault} for this attempt,
 * if any: it writes the status, headers and body itself, as `Endpoint::handle()` does, cut where the fault says.
 *
 * Kills only ever hit a `php -S` worker (never its master) or a php-fpm pool worker ({@see ProcessFaults}); elsewhere
 * they degrade to truncate-and-exit, which looks the same to Restate once output was written.
 */
final readonly class FaultInjector
{
    public const string ENABLE_VARIABLE = 'RESTATE_PHP_TEST_FAULTS';

    public function __construct(
        private string $stateDir,
        private bool $enabled = true,
    ) {
    }

    /** Enabled by RESTATE_PHP_TEST_FAULTS=1, with its files in RESTATE_PHP_TEST_STATE_DIR. */
    public static function fromEnvironment(): self
    {
        $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR');

        return new self(
            is_string($stateDir) && $stateDir !== '' ? $stateDir : sys_get_temp_dir(),
            getenv(self::ENABLE_VARIABLE) === '1',
        );
    }

    /**
     * Handle one request and write its response, faulted as planned.
     *
     * @param array<string, mixed> $server `$_SERVER` of the request
     */
    public function serve(Endpoint $endpoint, array $server, string $body): void
    {
        $invocationId = $server['HTTP_X_RESTATE_INVOCATION_ID'] ?? null;
        if (!$this->enabled || !is_string($invocationId) || $invocationId === '') {
            $endpoint->handle($server, $body);

            return;
        }

        $log = new AttemptLog($this->stateDir, $invocationId);
        $attempt = $log->start();
        $plan = FaultPlan::find($this->stateDir, $invocationId, self::idempotencyKey($body));
        if ($plan === null) {
            $endpoint->handle($server, $body);

            return;
        }

        $response = $endpoint->handleRequest($server, $body);
        $fault = $plan->faultFor($attempt);
        $outcome = self::outcome($response, $fault, ProcessFaults::canKillItself());
        $log->respond($attempt, $response->statusCode, self::frameTypes($response->body), $fault?->describe(), $outcome->end, strlen($outcome->body), strlen($response->body));

        self::write($response, $outcome);
    }

    /**
     * What a fault makes of a response. Pure, so it is unit-tested; `$canKill` says whether this process may SIGKILL
     * itself ({@see ProcessFaults::canKillItself()}).
     */
    public static function outcome(EndpointResponse $response, ?Fault $fault, bool $canKill): FaultOutcome
    {
        return match ($fault?->action) {
            null => new FaultOutcome(true, $response->body, FaultOutcome::COMPLETE),
            Fault::DELAY => new FaultOutcome(true, $response->body, FaultOutcome::COMPLETE, $fault->milliseconds),
            Fault::EXIT => new FaultOutcome(false, '', FaultOutcome::EXITED),
            // Degraded: a protocol response without frames, then the process ends.
            Fault::KILL_BEFORE_OUTPUT => $canKill ? new FaultOutcome(false, '', FaultOutcome::KILLED) : new FaultOutcome(true, '', FaultOutcome::EXITED),
            Fault::KILL_AFTER_FRAMES => new FaultOutcome(true, self::prefix($response->body, $fault->frames, $fault->halfFrame), $canKill ? FaultOutcome::KILLED : FaultOutcome::EXITED),
            Fault::KILL_AFTER_RESPONSE => new FaultOutcome(true, $response->body, $canKill ? FaultOutcome::KILLED : FaultOutcome::EXITED),
            // The handler ran, but its response is replaced: what a proxy or a load-shedding front door answers.
            Fault::RESPOND => new FaultOutcome(true, '', FaultOutcome::REPLACED, status: $fault->status, headers: $fault->headers),
            Fault::TRUNCATE_AFTER_FRAMES => self::truncated($response->body, $fault),
            default => throw new \LogicException("Unknown fault {$fault->action}."),
        };
    }

    /**
     * The first `$frames` whole frames of a protocol body, plus the first half of the next one with `$halfFrame`
     * (at least one byte of it). All of it when the body has no more frames than that.
     */
    public static function prefix(string $body, int $frames, bool $halfFrame = false): string
    {
        $parsed = FrameCodec::frames($body);
        $length = 0;
        foreach (array_slice($parsed, 0, $frames) as $frame) {
            $length += 8 + strlen($frame['payload']);
        }
        if ($halfFrame && isset($parsed[$frames])) {
            $length += max(1, intdiv(8 + strlen($parsed[$frames]['payload']), 2));
        }

        return substr($body, 0, $length);
    }

    /**
     * The attempts the injector saw for an invocation, in order. `response` is null for an attempt without a plan,
     * or one that ended (killed, exited, crashed) before the endpoint returned a response.
     *
     * @return list<array{attempt: int, pid: int, response: ?array{status: int, frames: ?list<int>, fault: ?string, end: string, written: int, length: int}}>
     */
    public static function attempts(string $stateDir, string $invocationId): array
    {
        return new AttemptLog($stateDir, $invocationId)->read();
    }

    private static function truncated(string $body, Fault $fault): FaultOutcome
    {
        $prefix = self::prefix($body, $fault->frames, $fault->halfFrame);

        return new FaultOutcome(true, $prefix, strlen($prefix) < strlen($body) ? FaultOutcome::TRUNCATED : FaultOutcome::COMPLETE);
    }

    /** @return ?list<int> the frame types of a protocol body; null for one that is not made of whole frames */
    private static function frameTypes(string $body): ?array
    {
        try {
            return FrameCodec::messageTypes($body);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** The idempotency key in the start message of a request body (service protocol v7), if any. */
    public static function idempotencyKey(string $body): ?string
    {
        $header = strlen($body) >= 8 ? unpack('ntype/nflags/Nlength', $body) : false;
        if ($header === false || $header['type'] !== 0x0000) {
            return null;
        }

        try {
            $key = FrameCodec::fields(substr($body, 8, $header['length']))[12][0] ?? null;
        } catch (\InvalidArgumentException) {
            return null;
        }

        return is_string($key) ? $key : null;
    }

    private static function write(EndpointResponse $response, FaultOutcome $outcome): void
    {
        if ($outcome->delayMilliseconds > 0) {
            usleep($outcome->delayMilliseconds * 1000);
        }

        if ($outcome->writeHead && $outcome->status !== null) {
            http_response_code($outcome->status);
            header('content-type: text/plain');
            foreach ($outcome->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        } elseif ($outcome->writeHead) {
            http_response_code($response->statusCode);
            foreach ($response->headers as [$name, $value]) {
                header("{$name}: {$value}", strtolower($name) === 'content-type');
            }
        }
        echo $outcome->body;

        if ($outcome->end === FaultOutcome::KILLED || $outcome->end === FaultOutcome::EXITED) {
            // What was written must reach the client before the process ends; with nothing written, a flush would
            // send the status line and headers.
            if ($outcome->writeHead || $outcome->body !== '') {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
            }
            $outcome->end === FaultOutcome::KILLED ? ProcessFaults::killWorkerOrExit() : exit(0);
        }
    }
}
