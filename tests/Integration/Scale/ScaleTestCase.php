<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Scale;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Restate\Tests\Integration\Services\ChaosRecorder;
use Restate\Tests\Integration\Services\ScaleValues;
use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\HttpResponse;
use Restate\Tests\Integration\Support\IntegrationTestCase;

/**
 * Base class of the scale tests: what they measure an invocation with (the attempts and side effects the scale services
 * record, tests/Integration/Services/scale.php, and Restate's journal events), a concurrent ingress driver, and the
 * measurement report.
 *
 * The large parameterisations are in the PHPUnit group `scale` (excluded by default, `just test integration php-cli --group scale`, nightly);
 * a small variant of each runs with the rest of the integration suite.
 */
abstract class ScaleTestCase extends IntegrationTestCase
{
    /** File the measurements are appended to, one JSON object per line; unset: not recorded. */
    public const string REPORT_VARIABLE = 'RESTATE_PHP_TEST_SCALE_REPORT';

    protected static function recorder(): ChaosRecorder
    {
        return new ChaosRecorder(self::stateDir());
    }

    /**
     * How long each recorded attempt ran inside the handler, in milliseconds, in attempt order (-1: no end recorded).
     *
     * @return list<int>
     */
    protected static function attemptMillis(string $invocationId): array
    {
        $ends = [];
        foreach (self::recorder()->effects($invocationId, 'attempt-end') as $end) {
            $ends[$end['attempt']] = $end['at'];
        }

        $durations = [];
        foreach (self::recorder()->attempts($invocationId) as $index => $start) {
            $durations[] = isset($ends[$index + 1]) ? $ends[$index + 1] - $start['at'] : -1;
        }

        return $durations;
    }

    /**
     * The time from each recorded attempt's start to the next one's, in milliseconds, in attempt order: the whole
     * cycle of an attempt that suspended (bootstrap, replay, response, Restate resuming the invocation and sending the
     * next request), where {@see attemptMillis()} counts only the time inside the handler.
     *
     * @return list<int>
     */
    protected static function attemptCycleMillis(string $invocationId): array
    {
        $starts = array_column(self::recorder()->attempts($invocationId), 'at');
        $cycles = [];
        for ($i = 1; $i < count($starts); $i++) {
            $cycles[] = $starts[$i] - $starts[$i - 1];
        }

        return $cycles;
    }

    /**
     * Restate's journal events of the invocation of the given type (`TransientError`, `Paused`, …), oldest first.
     * Restate records a transient error when an attempt fails, though not every repetition of the same error.
     *
     * @return list<array<string, mixed>>
     */
    protected static function journalEvents(string $invocationId, string $type): array
    {
        return self::restate()->query(sprintf(
            "SELECT event_type, event_json, appended_at FROM sys_journal_events WHERE id = '%s' AND event_type = '%s' ORDER BY appended_at",
            $invocationId,
            $type,
        ));
    }

    /** Poll until the invocation recorded `$count` attempt ends. */
    protected static function waitForAttemptEnds(string $invocationId, int $count, float $timeout = 60.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (count(self::recorder()->effects($invocationId, 'attempt-end')) < $count) {
            if (microtime(true) > $deadline) {
                self::fail(sprintf('Invocation %s ended %d attempts within %ss, expected %d.', $invocationId, count(self::recorder()->effects($invocationId, 'attempt-end')), $timeout, $count));
            }
            usleep(5_000);
        }
    }

    /**
     * The ids of the awakeables a FanOut awakeable handler created, once its first attempt wrote them.
     *
     * @return list<string>
     */
    protected static function awakeableIds(string $invocationId, int $count, float $timeout = 60.0): array
    {
        $file = ScaleValues::awakeablesFile(self::stateDir(), $invocationId);
        $deadline = microtime(true) + $timeout;
        do {
            clearstatcache(true, $file);
            $ids = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (is_array($ids) && count($ids) === $count) {
                /** @var list<string> $ids */
                return $ids;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail("Invocation {$invocationId} did not publish {$count} awakeable ids within {$timeout}s.");
    }

    protected static function resolveAwakeable(string $awakeableId, mixed $value): void
    {
        $response = HttpClient::postJson(self::restate()->ingressUrl() . "/restate/awakeables/{$awakeableId}/resolve", json_encode($value, JSON_THROW_ON_ERROR));
        self::assertTrue($response->isSuccessful(), "Resolving {$awakeableId}: HTTP {$response->status} {$response->body}");
    }

    protected static function rejectAwakeable(string $awakeableId, string $message): void
    {
        $response = HttpClient::post(self::restate()->ingressUrl() . "/restate/awakeables/{$awakeableId}/reject", $message, ['Content-Type' => 'text/plain']);
        self::assertTrue($response->isSuccessful(), "Rejecting {$awakeableId}: HTTP {$response->status} {$response->body}");
    }

    /**
     * POST every request to the ingress with at most `$concurrency` in flight (Guzzle's pool over curl_multi), and return
     * the responses by request key. A transport failure fails the test.
     *
     * @param array<array-key, array{path: string, body: ?string, headers?: array<string, string>}> $requests
     * @return array<array-key, HttpResponse>
     */
    protected static function postConcurrently(array $requests, int $concurrency, float $timeout = 300.0): array
    {
        $client = new Client(['base_uri' => self::restate()->ingressUrl() . '/', 'http_errors' => false, 'timeout' => $timeout]);
        $responses = [];
        $failures = [];
        $keys = array_keys($requests);

        $pool = new Pool($client, (static function () use ($requests): \Generator {
            foreach ($requests as $key => $request) {
                $headers = ($request['headers'] ?? []) + ($request['body'] === null ? [] : ['Content-Type' => 'application/json']);
                yield $key => new Request('POST', ltrim($request['path'], '/'), $headers, $request['body'] ?? '');
            }
        })(), [
            'concurrency' => $concurrency,
            'fulfilled' => static function (ResponseInterface $response, int|string $key) use (&$responses): void {
                $responses[$key] = new HttpResponse($response->getStatusCode(), (string) $response->getBody());
            },
            'rejected' => static function (mixed $reason, int|string $key) use (&$failures): void {
                $failures[$key] = $reason instanceof \Throwable ? $reason->getMessage() : get_debug_type($reason);
            },
        ]);
        $pool->promise()->wait();

        self::assertSame([], $failures, 'Ingress requests failed in transport.');
        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = $responses[$key];
        }

        return $ordered;
    }

    /**
     * Append a measurement to the report file ({@see REPORT_VARIABLE}), if one is configured.
     *
     * @param array<string, mixed> $measurements
     */
    protected static function report(string $scenario, array $measurements): void
    {
        $file = getenv(self::REPORT_VARIABLE);
        if (!is_string($file) || $file === '') {
            return;
        }
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0o777, true);
        }

        file_put_contents($file, json_encode(['scenario' => $scenario, 'server' => is_string($server = getenv('RESTATE_PHP_TEST_SERVER')) && $server !== '' ? $server : 'php-cli', 'restate' => self::restate()->serverVersion()] + $measurements, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Sample a list of per-attempt durations at a few attempts (first, 10%, 50%, last), keyed by attempt number.
     *
     * @param list<int> $millis
     * @return array<int, int>
     */
    protected static function sampled(array $millis): array
    {
        $count = count($millis);
        $samples = [];
        foreach ([1, intdiv($count, 10), intdiv($count, 2), $count] as $attempt) {
            if ($attempt >= 1 && $attempt <= $count) {
                $samples[$attempt] = $millis[$attempt - 1];
            }
        }

        return $samples;
    }
}
