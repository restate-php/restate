<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Restate\Tests\Integration\Services\ChaosRecorder;
use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\IdentityKeys;
use Restate\Tests\Integration\Support\IntegrationEndpoint;
use Restate\Tests\Support\Faults\FaultProxy;
use Restate\Testing\RestateTestEnvironment;

/**
 * Request identity when a signed request is old by the time the endpoint checks it. Restate signs every request with
 * a token valid from its `nbf` to its `exp`, 60 s later, and the endpoint checks both with zero leeway (shared-core's
 * verifier, through the extension).
 *
 * Neither side's clock can be skewed with libfaketime. Under php-fpm with libfaketime preloaded and 120 s ahead, PHP's
 * `time()` moved but the extension's token check still accepted the tokens: most likely because PHP loads extensions with RTLD_DEEPBIND, so the extension binds libc's clock directly,
 * past LD_PRELOAD. restate-server 1.8.1 under libfaketime hangs at start (no log line, no CPU). So this test makes the
 * request old instead: a FaultProxy holds the body back for 62 s, which looks to the token check like a clock 62 s
 * ahead.
 *
 * Measured with Restate 1.8.1: the held request is refused with 401 before the handler runs, Restate retries it like
 * any other failure, and the retry, signed anew, completes. Behind nginx the held request never gets that far: nginx's
 * `client_body_timeout` (60 s) closes it first, and Restate retries that too. Behind Caddy (caddy-fpm) neither:
 * Caddy 2.11's `read_idle_timeout` (60 s by default, reset on every read of the body) aborts the body read, which
 * cancels the request, and php_fastcgi answers 499 (RT0012) without reaching php-fpm; Restate retries that too. Under
 * FrankenPHP, whose PHP measures `max_execution_time` (30 s by default) in wall-clock time, the limit runs out while
 * the endpoint waits for the body: the attempt ends in a fatal error page (RT0012), which Restate retries the same
 * way. A skew larger than the lifetime would refuse every attempt the same way: a 401 storm until the retry policy
 * gives up, not a visible configuration error.
 */
#[Group('failure-nightly')]
final class ClockSkewTest extends TestCase
{
    private ?RestateTestEnvironment $environment = null;

    private ?FaultProxy $proxy = null;

    private ?string $pemFile = null;

    #[\Override]
    protected function tearDown(): void
    {
        $this->proxy?->stop();
        $this->environment?->stop();
        if ($this->pemFile !== null) {
            @unlink($this->pemFile);
        }
    }

    public function testARequestOlderThanItsTokenIsRefusedAndTheRetrySucceeds(): void
    {
        [$pem, $identityKey] = IdentityKeys::keyPair();
        $pemFile = tempnam(sys_get_temp_dir(), 'restate-php-identity-');
        self::assertIsString($pemFile);
        $this->pemFile = $pemFile;
        file_put_contents($pemFile, $pem);
        chmod($pemFile, 0o644);

        $this->environment = RestateTestEnvironment::start(
            IntegrationEndpoint::get()->withEnvironment(['RESTATE_PHP_TEST_IDENTITY_KEYS' => $identityKey]),
            IdentityKeys::restate($pemFile),
        );
        $this->proxy = FaultProxy::start('127.0.0.1', $this->environment->endpointPort(), IntegrationEndpoint::stateDir());
        $this->environment->registerDeployment('http://' . $this->environment->testHostForRestate() . ':' . $this->proxy->port, http2: false);

        $this->proxy->fault(FaultProxy::STALL_REQUEST, milliseconds: 62_000);
        $sent = HttpClient::postJson($this->environment->ingressUrl() . '/Chaos/steps/send', '{"runs":1}');
        $id = json_decode($sent->body, true)['invocationId'] ?? null;
        self::assertIsString($id, $sent->body);

        $output = HttpClient::get($this->environment->ingressUrl() . "/restate/invocation/{$id}/attach", timeout: 120.0);
        self::assertSame('{"attempt":2,"steps":[1]}', $output->body);

        $connections = $this->proxy->connectionsOf($id);
        self::assertSame('stall-request', $connections[0]['mode'], (string) json_encode($connections));
        self::assertCount(3, $connections, 'The held attempt, then the two attempts of an unfaulted run.');
        // The held attempt never reached the handler: its token had expired.
        self::assertCount(2, new ChaosRecorder(IntegrationEndpoint::stateDir())->attempts($id));
        // Behind nginx the held request never reaches PHP: nginx's client_body_timeout (60 s) closes it first. Behind
        // Caddy its read_idle_timeout (60 s) aborts the body read, and php_fastcgi answers 499 (client closed
        // request) instead. Under FrankenPHP PHP's max_execution_time (30 s, wall-clock time there) runs out while the
        // endpoint waits for the body, and the attempt ends in PHP's fatal error page.
        self::assertStringContainsString(
            match (true) {
                str_starts_with(IntegrationEndpoint::server(), 'nginx-fpm') => 'connection closed before message completed',
                IntegrationEndpoint::server() === 'caddy-fpm' => 'unexpected http status code: 499',
                str_starts_with(IntegrationEndpoint::server(), 'frankenphp') => 'unexpected content type \'Some("text/html',
                default => '401 Unauthorized',
            },
            $this->environment->restateLog(500),
        );
    }
}
