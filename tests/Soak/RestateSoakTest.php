<?php

declare(strict_types=1);

namespace Restate\Tests\Soak;

use PHPUnit\Framework\TestCase;
use Restate\Testing\EndpointContainer;
use Restate\Testing\RestateContainer;
use Restate\Testing\RestateTestEnvironment;
use Restate\Tests\Integration\Support\IntegrationEndpoints;
use Restate\Tests\Integration\Support\WorkerProbe;
use Restate\Tests\Soak\Support\SoakSettings;
use Restate\Tests\Support\RequestIdentityTestSupport;
use Restate\Tests\Support\Repository;

/**
 * About 300,000 invocations (RESTATE_PHP_SOAK_INVOCATIONS; 3,000 in a quick run) through a real Restate 1.8.1
 * that signs its requests, into a FrankenPHP worker endpoint (release build with diagnostics, 8 threads) serving the
 * Soak services (tests/Integration/Services/soak.php): plain calls, suspensions (sleeps), runs that end attempts,
 * retries, awakeables resolved through Restate, and a counter per key.
 *
 * Gated, exactly: every invocation answers what it should, every counter ends at the number of adds it got, no
 * invocation is left unfinished, no live VM is left in the worker, and Restate's log has no 401 (a request identity
 * the endpoint refused: tokens expire 60 s after signing, so a queue longer than that would show here) and no RT0012
 * (a response that is not a protocol response). Reported only: throughput, and latency p50/p99 per tenth of the run,
 * whose drift a leak that slows every request down would show.
 */
final class RestateSoakTest extends TestCase
{
    private const int KEYS = 1_000;

    private const int CONCURRENCY = 64;

    private ?string $pemFile = null;

    private ?RestateTestEnvironment $environment = null;

    private ?string $stateDir = null;

    #[\Override]
    protected function tearDown(): void
    {
        $this->environment?->stop();
        if ($this->pemFile !== null) {
            @unlink($this->pemFile);
        }
        if ($this->stateDir !== null) {
            exec('rm -rf ' . escapeshellarg($this->stateDir));
        }
    }

    public function testHundredsOfThousandsOfInvocationsCompleteExactly(): void
    {
        $report = SoakSettings::reportDir('restate');
        $this->environment = $environment = $this->start(SoakSettings::threads(8));
        $total = SoakSettings::invocations();
        $ingress = $environment->ingressUrl();

        $adds = [];
        $prefix = bin2hex(random_bytes(4));
        $wrong = [];
        $windows = [];
        $window = max(1, intdiv($total, 10));
        $latencies = [];
        $started = microtime(true);

        $calls = (static function () use ($total, $prefix): \Generator {
            for ($i = 0; $i < $total; ++$i) {
                $kind = $i % 20;
                if ($kind < 8) {
                    yield ['Soak/greet', json_encode("soak {$i}", JSON_THROW_ON_ERROR), json_encode("Hello soak {$i}", JSON_THROW_ON_ERROR)];
                } elseif ($kind < 11) {
                    $key = $i % self::KEYS;
                    yield ["SoakCounter/{$prefix}-{$key}/add", '1', null];
                } elseif ($kind < 13) {
                    yield ['Soak/sleepy', '5', '"slept"'];
                } elseif ($kind < 15) {
                    yield ['Soak/awakeable', json_encode("value {$i}", JSON_THROW_ON_ERROR), json_encode("value {$i}", JSON_THROW_ON_ERROR)];
                } elseif ($kind < 16) {
                    yield ['Soak/flaky', null, '3'];
                } else {
                    yield ['Soak/steps', '3', '6'];
                }
            }
        })();

        $done = 0;
        self::call($ingress, $calls, function (array $call, int $status, string $body, float $seconds) use (&$adds, &$wrong, &$latencies, &$windows, &$done, $window): void {
            [$path, , $expected] = $call;
            if (str_ends_with($path, '/add')) {
                $counter = substr($path, 0, -strlen('/add'));
                $adds[$counter] = ($adds[$counter] ?? 0) + 1;
            }
            if ($status !== 200 || ($expected !== null && $body !== $expected)) {
                $wrong[$path] = ($wrong[$path] ?? 0) + 1;
                if ($wrong[$path] <= 3) {
                    fwrite(STDERR, "unexpected answer from {$path}: {$status} {$body}\n");
                }
            }
            $latencies[] = $seconds;
            if (++$done % $window === 0) {
                $windows[] = self::percentiles($latencies);
                $latencies = [];
            }
        });
        $elapsed = microtime(true) - $started;
        if ($latencies !== []) {
            $windows[] = self::percentiles($latencies);
        }

        SoakSettings::report($report, sprintf('%d invocations in %.0f s: %.0f invocations/s, %d in flight', $total, $elapsed, $total / $elapsed, self::CONCURRENCY));
        foreach ($windows as $index => [$p50, $p99]) {
            SoakSettings::report($report, sprintf('tenth %2d: p50 %6.1f ms  p99 %7.1f ms', $index + 1, $p50 * 1000, $p99 * 1000));
        }

        // Every counter got every add, once.
        $counts = [];
        $counterCalls = (static function () use ($adds): \Generator {
            foreach ($adds as $counter => $count) {
                yield ["{$counter}/get", null, (string) $count];
            }
        })();
        self::call($ingress, $counterCalls, function (array $call, int $status, string $body) use (&$counts): void {
            if ($status !== 200 || $body !== $call[2]) {
                $counts[$call[0]] = "{$status} {$body}, expected {$call[2]}";
            }
        });

        $unfinished = self::unfinished($environment);
        $stats = self::workerStats($environment);
        $restateLog = $environment->restateLog(100_000_000);
        // The endpoint's own count (WorkerProbe records every answer that is not 2xx), and Restate's log.
        $statuses = $this->statuses();
        $unauthorized = ($statuses[401] ?? 0) + (int) preg_match_all('/401 Unauthorized|status code \'?401\b/', $restateLog);
        $unexpectedContent = substr_count($restateLog, 'RT0012');
        SoakSettings::report($report, sprintf('wrong answers %s; wrong counters %d; unfinished %d; endpoint answers by status %s; 401s %d, Restate log RT0012 %d; worker: live_vms %d, rust_live_bytes %d, rssAnon %d KiB', json_encode($wrong), count($counts), $unfinished, json_encode($statuses), $unauthorized, $unexpectedContent, $stats['diagnostics']['live_vms'] ?? -1, $stats['diagnostics']['rust_live_bytes'] ?? -1, $stats['process']['rssAnonKib'] ?? -1));

        self::assertSame([], $wrong, 'Invocations that did not answer what they should.');
        self::assertSame([], array_slice($counts, 0, 10, true), 'Counters that lost or repeated adds.');
        self::assertSame(0, $unfinished, 'Invocations Restate has not finished.');
        self::assertSame(0, $unauthorized, 'Restate saw 401s: the endpoint refused a request identity.');
        self::assertSame(0, $unexpectedContent, 'Restate saw responses that were not protocol responses (RT0012).');
        self::assertSame(0, $stats['diagnostics']['live_vms'] ?? null);
    }

    private function start(int $threads): RestateTestEnvironment
    {
        [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair();
        $seed = substr($secretKey, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);
        // PKCS#8 PrivateKeyInfo { version 0, id-Ed25519, OCTET STRING { OCTET STRING seed } }, as RequestIdentityTest.
        $pem = "-----BEGIN PRIVATE KEY-----\n" . base64_encode(hex2bin('302e020100300506032b657004220420') . $seed) . "\n-----END PRIVATE KEY-----\n";
        $this->pemFile = (string) tempnam(sys_get_temp_dir(), 'restate-php-soak-identity-');
        file_put_contents($this->pemFile, $pem);
        chmod($this->pemFile, 0o644);

        $this->stateDir = $stateDir = sys_get_temp_dir() . '/restate-php-soak-restate-' . bin2hex(random_bytes(4));
        mkdir($stateDir, 0o777, true);
        chmod($stateDir, 0o777);

        $endpoint = EndpointContainer::forImage(SoakSettings::releaseImage(), 8080)
            ->withVolume(Repository::ROOT, '/app', readOnly: true)
            ->withVolume($stateDir, $stateDir)
            ->withCommand(['frankenphp', 'run', '--config', '/app/docker/frankenphp/worker.Caddyfile'])
            ->withEnvironment([
                'RESTATE_PHP_TEST_STATE_DIR' => $stateDir,
                'RESTATE_PHP_TEST_IDENTITY_KEYS' => $identityKey,
                'RESTATE_PHP_TEST_WORKER_NUM' => (string) $threads,
                'RESTATE_PHP_TEST_DISPLAY_ERRORS' => '0',
                IntegrationEndpoints::LOGGER_VARIABLE => '/dev/null',
            ]);

        return RestateTestEnvironment::start(
            $endpoint,
            RestateContainer::default()
                ->withVolume($this->pemFile, '/etc/restate/request-identity.pem', readOnly: true)
                ->withEnvironment(['RESTATE_WORKER__INVOKER__REQUEST_IDENTITY_PRIVATE_KEY_PEM_FILE' => '/etc/restate/request-identity.pem']),
        );
    }

    /**
     * Calls through the ingress, CONCURRENCY at a time, on kept-alive connections.
     *
     * @param iterable<array{0: string, 1: ?string, 2: ?string}> $calls path, JSON body, expected JSON answer (null: any)
     * @param \Closure(array{0: string, 1: ?string, 2: ?string}, int, string, float): void $onAnswer
     */
    private static function call(string $ingress, iterable $calls, \Closure $onAnswer): void
    {
        $multi = curl_multi_init();
        curl_multi_setopt($multi, CURLMOPT_MAXCONNECTS, self::CONCURRENCY);
        $pending = (static fn (): \Generator => yield from $calls)();
        $inFlight = [];
        $add = static function () use ($multi, $pending, &$inFlight, $ingress): void {
            if (!$pending->valid()) {
                return;
            }
            $call = $pending->current();
            $pending->next();
            $handle = curl_init("{$ingress}/{$call[0]}");
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $call[1] ?? '',
                CURLOPT_HTTPHEADER => $call[1] === null ? ['Content-Type:', 'Expect:'] : ['content-type: application/json', 'Expect:'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120,
            ]);
            $inFlight[spl_object_id($handle)] = [$handle, $call];
            curl_multi_add_handle($multi, $handle);
        };
        for ($i = 0; $i < self::CONCURRENCY; ++$i) {
            $add();
        }
        while ($inFlight !== []) {
            curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
            while (($done = curl_multi_info_read($multi)) !== false) {
                $handle = $done['handle'];
                [, $call] = $inFlight[spl_object_id($handle)];
                unset($inFlight[spl_object_id($handle)]);
                $status = $done['result'] === CURLE_OK ? curl_getinfo($handle, CURLINFO_RESPONSE_CODE) : 0;
                $body = $done['result'] === CURLE_OK ? (string) curl_multi_getcontent($handle) : curl_error($handle);
                $seconds = curl_getinfo($handle, CURLINFO_TOTAL_TIME);
                curl_multi_remove_handle($multi, $handle);
                $add();
                $onAnswer($call, $status, $body, $seconds);
            }
        }
        curl_multi_close($multi);
    }

    /** @return array<int, int> the endpoint's answers by HTTP status, summed over every worker script run */
    private function statuses(): array
    {
        $statuses = [];
        $files = glob($this->stateDir . '/workers/*.json');
        foreach ($files === false ? [] : $files as $file) {
            $worker = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            foreach (is_array($worker) && is_array($worker['statuses'] ?? null) ? $worker['statuses'] : [] as $status => $count) {
                $statuses[(int) $status] = ($statuses[(int) $status] ?? 0) + (int) $count;
            }
        }
        ksort($statuses);

        return $statuses;
    }

    /** Invocations Restate still has that did not complete, once the queue drained (polled for up to 10 minutes). */
    private static function unfinished(RestateTestEnvironment $environment): int
    {
        $deadline = microtime(true) + 600;
        do {
            $rows = $environment->query("SELECT count(*) AS unfinished FROM sys_invocation WHERE status != 'completed'");
            $unfinished = (int) ($rows[0]['unfinished'] ?? -1);
            if ($unfinished === 0) {
                return 0;
            }
            sleep(2);
        } while (microtime(true) < $deadline);

        return $unfinished;
    }

    /** @return array<string, mixed> */
    private static function workerStats(RestateTestEnvironment $environment): array
    {
        $body = @file_get_contents($environment->endpointUrl() . WorkerProbe::PATH);

        return is_string($body) ? (array) json_decode($body, true, flags: JSON_THROW_ON_ERROR) : [];
    }

    /**
     * @param list<float> $seconds
     *
     * @return array{float, float} p50, p99
     */
    private static function percentiles(array $seconds): array
    {
        sort($seconds);
        $count = count($seconds);

        return [$seconds[(int) floor(($count - 1) * 0.5)], $seconds[(int) floor(($count - 1) * 0.99)]];
    }
}
