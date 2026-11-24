<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Restate\Tests\Integration\Services\ChaosRecorder;
use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\HttpResponse;
use Restate\Tests\Integration\Support\HttpTransportException;
use Restate\Tests\Integration\Support\IntegrationEndpoint;
use Restate\Tests\Integration\Support\JsonLinesLogger;
use Restate\Tests\Support\Repository;
use Restate\Tests\Support\RequestIdentityTestSupport;
use Restate\Testing\EndpointContainer;
use Restate\Testing\Internal\Process;
use Restate\Testing\RestateTestEnvironment;

/**
 * Front-door hazards of PHP-FPM (docs/serving.md, "PHP-FPM and FastCGI"), each
 * against php-fpm behind a front door of the fpm-extension images (docker/fpm/), in an environment of its own:
 *
 * - Bodies through each front door at its defaults (RESTATE_PHP_FPM_PROFILE=defaults). Restate sends every invocation
 *   body without a length: Caddy php_fastcgi answers 411 above 4 KiB, nginx 413 above 1 MiB, and Apache
 *   mod_proxy_fcgi without `proxy-sendcl` hands php-fpm an empty body above 16 KiB. Each fails every attempt, visibly,
 *   until the retry policy (three attempts, KILL) ends it; the handler never runs. Large responses pass all of them.
 * - Timeouts shorter than an attempt: nginx `fastcgi_read_timeout` and Caddy's `read_timeout` (504) and php-fpm
 *   `request_terminate_timeout` (502) fail the attempt; Restate retries it and the run executes again. A handler that
 *   is always slower never completes; only its retry policy stops it.
 * - `clear_env = yes`: an identity key read from the environment is missing, and the endpoint refuses to build
 *   instead of serving unsigned requests.
 *
 * Nightly (group serving-hazards, `composer test:serving-hazards`): it needs the fpm-extension and fpm-extension-apache
 * images (bin/test-fpm.sh builds them) and waits out real timeouts.
 */
#[Group('serving-hazards')]
#[Group('managed')]
final class ServingHazardsTest extends TestCase
{
    public const string DEFAULT_APACHE_IMAGE = 'restate.local/restate-php-fpm:extension-apache';

    private const int KIB = 1 << 10;

    private const int MIB = 1 << 20;

    private ?RestateTestEnvironment $environment = null;

    #[After]
    public function stopEnvironment(): void
    {
        if ($this->environment !== null) {
            $status = $this->status();
            if ($status->isFailure() || $status->isError()) {
                $this->environment->dumpLogs();
            }
            $this->environment->stop();
            $this->environment = null;
        }
    }

    public function testCaddyAtItsDefaultsAnswers411ToBodiesOver4KiB(): void
    {
        $restate = $this->start(IntegrationEndpoint::fpm('caddy', ['RESTATE_PHP_FPM_PROFILE' => 'defaults'])->withHttp2());

        $this->assertIngests($restate, 4 * self::KIB - 512);
        $this->assertRefusedAtTheFrontDoor($restate, 5 * self::KIB, '411');
        $this->assertLargeOutputPasses($restate);
    }

    public function testNginxAtItsDefaultsAnswers413ToBodiesOver1MiB(): void
    {
        $restate = $this->start(IntegrationEndpoint::fpm('nginx', ['RESTATE_PHP_FPM_PROFILE' => 'defaults']));

        $this->assertIngests($restate, 512 * self::KIB);
        // Restate maps the 413 to "content too large".
        $this->assertRefusedAtTheFrontDoor($restate, 2 * self::MIB, 'service replied with content too large');
        self::assertStringContainsString('client intended to send too large chunked body', $restate->endpointLog());
        $this->assertLargeOutputPasses($restate);
    }

    public function testApacheWithoutProxySendclGivesPhpAnEmptyBodyOver16KiB(): void
    {
        $restate = $this->start(IntegrationEndpoint::fpm('apache', ['RESTATE_PHP_FPM_PROFILE' => 'defaults'], self::apacheImage()));

        $this->assertIngests($restate, 8 * self::KIB);
        // php-fpm reads no body: the VM has no journal, and its error (shared-core code 571) is the attempt's failure.
        $id = $this->assertRefusedAtTheFrontDoor($restate, 64 * self::KIB, 'The input was closed while still waiting to receive all journal to replay');
        // The endpoint saw the attempts, each with an empty body, and reported them to Restate as retryable errors.
        $records = array_filter(
            JsonLinesLogger::read(IntegrationEndpoint::stateDir() . '/endpoint-log.jsonl'),
            static fn (array $record): bool => ($record['context']['restate.invocation.id'] ?? null) === $id,
        );
        self::assertNotEmpty($records, 'The endpoint logged nothing about the empty-bodied attempts.');
        $this->assertLargeOutputPasses($restate);
    }

    public function testApacheWithProxySendclTakesLargeBodies(): void
    {
        $restate = $this->start(IntegrationEndpoint::fpm('apache', image: self::apacheImage()));

        $this->assertIngests($restate, 9 * self::MIB);
        $this->assertLargeOutputPasses($restate);
    }

    public function testAnNginxReadTimeoutShorterThanTheAttemptFailsItAndTheRunExecutesAgain(): void
    {
        $restate = $this->start(IntegrationEndpoint::fpm('nginx', ['RESTATE_PHP_FPM_READ_TIMEOUT' => '2']));

        // Attempt 1 sleeps 5 s in its run; nginx answers 504 after 2 s. Attempt 2 runs it again (not journaled yet),
        // attempt 3 replays it.
        $id = $this->send($restate, 'Chaos/slowRun', '{"ms":5000,"times":1}');
        self::assertSame('{"attempt":3,"execution":2}', $this->attach($restate, $id, 60.0)->body);
        self::assertSame([1, 2], array_column(self::recorder()->effects($id, 'slow'), 'attempt'));
        self::assertStringContainsString('upstream timed out', $restate->endpointLog());

        // Always slower than the front door: every attempt gets 504 until the service's retry policy pauses it. With
        // Restate's 600 s abort timeout and nginx's 60 s default, that is any handler over 60 s.
        $slow = $this->send($restate, 'Chaos/slowRun', '{"ms":3000}');
        $restate->waitForInvocationStatus($slow, 'paused', timeoutSeconds: 60.0);
        self::assertCount(5, self::recorder()->attempts($slow));
    }

    public function testACaddyReadTimeoutShorterThanTheAttemptFailsItAndTheRunExecutesAgain(): void
    {
        $restate = $this->start(IntegrationEndpoint::fpm('caddy', ['RESTATE_PHP_FPM_READ_TIMEOUT' => '2'])->withHttp2());

        $id = $this->send($restate, 'Chaos/slowRun', '{"ms":5000,"times":1}');
        self::assertSame('{"attempt":3,"execution":2}', $this->attach($restate, $id, 60.0)->body);
        self::assertSame([1, 2], array_column(self::recorder()->effects($id, 'slow'), 'attempt'));
    }

    public function testAnFpmRequestTerminateTimeoutKillsTheWorkerAndTheAttemptIsRetried(): void
    {
        $restate = $this->start(IntegrationEndpoint::fpm('nginx', ['RESTATE_PHP_FPM_REQUEST_TERMINATE_TIMEOUT' => '2']));

        $id = $this->send($restate, 'Chaos/slowRun', '{"ms":5000,"times":1}');
        self::assertSame('{"attempt":3,"execution":2}', $this->attach($restate, $id, 60.0)->body);
        self::assertSame([1, 2], array_column(self::recorder()->effects($id, 'slow'), 'attempt'));
        self::assertMatchesRegularExpression('/execution timed out .*terminating/', $restate->endpointLog());
        // nginx reads a reset connection and answers 502, which Restate retries.
        self::assertStringContainsString('Connection reset by peer) while reading response header from upstream', $restate->endpointLog());
        self::assertStringContainsString("'502 Bad Gateway'", $restate->restateLog(400));

        // php-fpm replaced the worker: the pool still serves.
        $settings = HttpClient::postJson($restate->ingressUrl() . '/LargeBodies/settings', null);
        self::assertTrue($settings->isSuccessful(), $settings->body);
    }

    /**
     * clear_env = yes, php-fpm's own default (the official images set no): the router reads its identity key with
     * `getenv()` as docs/serving.md shows, gets false, and building the endpoint throws (identity keys fail closed),
     * so the endpoint answers 500 to everything instead of serving unsigned requests. With clear_env = no the
     * same container verifies: unsigned discovery is 401, signed discovery 200. No Restate is involved.
     */
    public function testClearEnvHidesAnIdentityKeyFromTheEnvironmentAndTheEndpointRefusesToBuild(): void
    {
        [$identityKey, $signed] = RequestIdentityTestSupport::signedIdentity('/discover');
        $accept = ['accept' => 'application/vnd.restate.endpointmanifest.v4+json'];

        $hidden = self::runContainer(['RESTATE_PHP_FPM_CLEAR_ENV' => 'yes', 'RESTATE_IDENTITY_KEY' => $identityKey]);
        try {
            self::assertSame(500, $hidden['get']('/health', [])->status);
            $discover = $hidden['get']('/discover', [...$accept, ...$signed]);
            self::assertSame(500, $discover->status, $discover->body);
            self::assertMatchesRegularExpression('/Uncaught InvalidArgumentException: .*identity key/i', $hidden['log']());
        } finally {
            $hidden['stop']();
        }

        $visible = self::runContainer(['RESTATE_PHP_FPM_CLEAR_ENV' => 'no', 'RESTATE_IDENTITY_KEY' => $identityKey]);
        try {
            self::assertSame(200, $visible['get']('/health', [])->status);
            self::assertSame(401, $visible['get']('/discover', $accept)->status);
            $discover = $visible['get']('/discover', [...$accept, ...$signed]);
            self::assertSame(200, $discover->status, $discover->body);
            self::assertStringContainsString('IdentityFromEnvironment', $discover->body);
        } finally {
            $visible['stop']();
        }
    }

    private function start(EndpointContainer $endpoint): RestateTestEnvironment
    {
        return $this->environment = RestateTestEnvironment::start($endpoint);
    }

    /** `LargeBodies/ingest` of `$bytes` completes, in one attempt. */
    private function assertIngests(RestateTestEnvironment $restate, int $bytes): void
    {
        $input = str_repeat('i', $bytes);
        $id = $this->send($restate, 'LargeBodies/ingest', $input, 'application/octet-stream');
        $response = $this->attach($restate, $id, 60.0);

        self::assertSame(200, $response->status, $response->body);
        self::assertSame(['bytes' => $bytes, 'sha1' => sha1($input), 'attempt' => 1], json_decode($response->body, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * `LargeBodies/ingest` of `$bytes` fails every attempt before the handler runs, and the retry policy ends it with
     * a failure that names the cause.
     */
    private function assertRefusedAtTheFrontDoor(RestateTestEnvironment $restate, int $bytes, string $cause): string
    {
        $id = $this->send($restate, 'LargeBodies/ingest', str_repeat('i', $bytes), 'application/octet-stream');
        $response = $this->attach($restate, $id, 60.0);

        self::assertGreaterThanOrEqual(500, $response->status, $response->body);
        $row = $restate->waitForInvocationStatus($id, 'completed');
        self::assertSame('failure', $row['completion_result'] ?? null);
        self::assertStringContainsString($cause, (string) ($row['completion_failure'] ?? ''), (string) json_encode($row, JSON_UNESCAPED_SLASHES));
        self::assertSame([], self::recorder()->attempts($id), 'The handler ran.');
        // Transient for Restate: retried twice, then the policy's third attempt ends it.
        $log = $restate->restateLog(2000);
        // (A record can span lines: the error carries the endpoint's message.)
        self::assertSame(2, preg_match_all('/Invocation error, retrying in (?:(?!Invocation error)[\s\S])*?restate\.invocation\.id=' . preg_quote($id, '/') . '\b/', $log), $log);

        return $id;
    }

    /** A 31 MiB response, just under Restate's message limit, passes the front door. */
    private function assertLargeOutputPasses(RestateTestEnvironment $restate): void
    {
        $bytes = 31 * self::MIB;
        $id = $this->send($restate, 'LargeBodies/output', (string) $bytes);
        $response = $this->attach($restate, $id, 90.0);

        self::assertSame(200, $response->status, substr($response->body, 0, 500));
        self::assertSame($bytes, strlen($response->body));
    }

    /** @param array<string, string> $headers */
    private function send(RestateTestEnvironment $restate, string $path, string $body, string $contentType = 'application/json', array $headers = []): string
    {
        $response = HttpClient::post($restate->ingressUrl() . '/' . $path . '/send', $body, ['content-type' => $contentType, ...$headers], timeout: 60.0);
        self::assertSame(202, $response->status, "{$path}/send: {$response->body}");
        $id = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)['invocationId'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function attach(RestateTestEnvironment $restate, string $invocationId, float $timeout): HttpResponse
    {
        return HttpClient::get($restate->ingressUrl() . "/restate/invocation/{$invocationId}/attach", timeout: $timeout);
    }

    private static function recorder(): ChaosRecorder
    {
        return new ChaosRecorder(IntegrationEndpoint::stateDir());
    }

    private static function apacheImage(): string
    {
        $image = getenv('RESTATE_PHP_TEST_FPM_APACHE_IMAGE');

        return is_string($image) && $image !== '' ? $image : self::DEFAULT_APACHE_IMAGE;
    }

    /**
     * Run the fpm-extension image on its own, serving tests/Integration/Support/identity-from-env.php behind nginx,
     * without Restate (the harness always registers its endpoint, which these requests must not depend on).
     *
     * @param array<string, string> $environment
     *
     * @return array{get: \Closure(string, array<string, string>): HttpResponse, log: \Closure(): string, stop: \Closure(): void}
     */
    private static function runContainer(array $environment): array
    {
        $image = getenv('RESTATE_PHP_TEST_FPM_IMAGE');
        $image = is_string($image) && $image !== '' ? $image : IntegrationEndpoint::DEFAULT_FPM_IMAGE;
        $docker = Process::findExecutable('docker') ?? Process::findExecutable('podman') ?? self::fail('No docker or podman on PATH.');
        $name = 'restate-php-test-hazard-' . bin2hex(random_bytes(4));

        $command = [$docker, 'run', '--detach', '--rm', '--name', $name, '--label', RestateTestEnvironment::CONTAINER_LABEL,
            '--publish', '127.0.0.1::8080', '--volume', Repository::ROOT . ':/app:ro',
            '--env', 'RESTATE_PHP_FPM_SCRIPT=/app/tests/Integration/Support/identity-from-env.php'];
        foreach ($environment as $variable => $value) {
            array_push($command, '--env', "{$variable}={$value}");
        }
        [$exitCode, $stdout, $stderr] = Process::run([...$command, $image]);
        self::assertSame(0, $exitCode, "docker run {$image} failed: {$stdout}{$stderr}");

        $stop = static function () use ($docker, $name): void {
            Process::run([$docker, 'rm', '--force', $name]);
        };
        [, $port] = Process::run([$docker, 'port', $name, '8080/tcp']);
        if (preg_match('/:(\d+)\s*$/m', $port, $match) !== 1) {
            $stop();
            self::fail("No published port for {$name}: {$port}");
        }
        $base = 'http://127.0.0.1:' . $match[1];

        $get = static fn (string $path, array $headers): HttpResponse => HttpClient::get($base . $path, $headers, timeout: 10.0);
        $deadline = microtime(true) + 30.0;
        while (true) {
            try {
                // 502 while nginx is up and php-fpm not yet.
                $reason = 'HTTP ' . $get('/health', [])->status;
                if ($reason !== 'HTTP 502') {
                    break;
                }
            } catch (HttpTransportException $exception) {
                $reason = $exception->getMessage();
            }
            if (microtime(true) > $deadline) {
                $stop();
                self::fail("{$name} did not answer: {$reason}");
            }
            usleep(200_000);
        }

        return [
            'get' => $get,
            'log' => static fn (): string => implode('', array_slice(Process::run([$docker, 'logs', $name]), 1)),
            'stop' => $stop,
        ];
    }
}
