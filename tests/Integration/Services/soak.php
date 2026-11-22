<?php

declare(strict_types=1);

use Restate\Context;
use Restate\InvocationConfiguration;
use Restate\InvocationRetryPolicy;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Service;
use Restate\Target;
use Restate\VirtualObject;

/*
 * The handlers of the end-to-end soak through Restate (tests/Soak/RestateSoakTest.php). Restate keeps no journal of a
 * completed invocation (journalRetention 0) and idempotency results for a minute, so hundreds of thousands of
 * invocations do not fill its storage. Retries are 10 ms apart.
 *
 * Soak:
 * - greet(name): "Hello <name>".
 * - sleepy(ms): three durable sleeps, so the invocation suspends three times; returns "slept".
 * - steps(n): n sequential runs (each ends an attempt in request/response mode), the i-th returning i; their sum.
 * - flaky(): fails its first two attempts (counted in a file per invocation under RESTATE_PHP_TEST_STATE_DIR, removed
 *   when it succeeds), then returns the attempt, 3.
 * - awakeable(value): creates an awakeable, has resolveAwakeable resolve it with value through Restate, returns what
 *   it was resolved with.
 * SoakCounter (virtual object): add(n) returns the new count; get (shared) returns it.
 */

$configuration = new InvocationConfiguration(journalRetentionMillis: 0, idempotencyRetentionMillis: 60_000);
$retries = new InvocationRetryPolicy(initialIntervalMillis: 10, exponentiationFactor: 1.0);

$attemptFile = static function (string $invocationId): string {
    $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR');
    $directory = (is_string($stateDir) && $stateDir !== '' ? $stateDir : dirname(__DIR__, 3) . '/var') . '/soak-attempts';
    if (!is_dir($directory)) {
        @mkdir($directory, 0o777, true);
    }

    return $directory . '/' . hash('sha256', $invocationId);
};

return [
    Service::new('Soak', invocationRetryPolicy: $retries, invocationConfiguration: $configuration)
        ->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}")
        ->handler('sleepy', static function (Context $ctx, int $milliseconds): string {
            for ($i = 0; $i < 3; ++$i) {
                $ctx->sleep($milliseconds);
            }

            return 'slept';
        })
        ->handler('steps', static function (Context $ctx, int $steps): int {
            $sum = 0;
            for ($step = 1; $step <= $steps; ++$step) {
                $sum += $ctx->run("step-{$step}", static fn (): int => $step);
            }

            return $sum;
        })
        ->handler('flaky', static function (Context $ctx) use ($attemptFile): int {
            $file = $attemptFile($ctx->request()->id);
            file_put_contents($file, "attempt\n", FILE_APPEND | LOCK_EX);
            $attempts = file($file);
            $attempt = $attempts === false ? 1 : count($attempts);
            if ($attempt < 3) {
                throw new \RuntimeException("flaky attempt {$attempt}");
            }
            @unlink($file);

            return $attempt;
        })
        ->handler('awakeable', static function (Context $ctx, string $value): mixed {
            $awakeable = $ctx->awakeable();
            $ctx->send(Target::service('Soak', 'resolveAwakeable'), ['id' => $awakeable->id(), 'value' => $value]);

            return $awakeable->value();
        })
        ->handler('resolveAwakeable', static function (Context $ctx, array $request): string {
            $ctx->resolveAwakeable((string) $request['id'], $request['value'] ?? null);

            return 'resolved';
        })
        ->definition(),
    VirtualObject::new('SoakCounter', invocationRetryPolicy: $retries, invocationConfiguration: $configuration)
        ->handler('add', static function (ObjectContext $ctx, int $amount): int {
            $count = (int) $ctx->get('count') + $amount;
            $ctx->set('count', $count);

            return $count;
        })
        ->sharedHandler('get', static fn (ObjectSharedContext $ctx): int => (int) $ctx->get('count'))
        ->definition(),
];
