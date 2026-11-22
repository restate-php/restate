<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Support;

use Restate\Testing\EndpointContainer;
use Restate\Testing\PhpCliServer;
use Restate\Tests\Support\Faults\FaultProxy;

/**
 * Base class for tests that put a {@see FaultProxy} between Restate and the endpoint: an environment of its own (the
 * fault-injection endpoint, configured apart so the harness starts another Restate), whose deployment is registered
 * through one proxy shared by every class using it. Each test starts with the proxy passing everything through.
 */
abstract class ProxiedTestCase extends FaultInjectionTestCase
{
    private static ?FaultProxy $proxy = null;

    #[\Override]
    protected static function restateEndpoint(): PhpCliServer|EndpointContainer
    {
        // Nothing reads RESTATE_PHP_TEST_PROXIED. It is only there so this configuration differs from
        // IntegrationEndpoint::withFaults() and the harness starts a Restate for the proxied tests alone: proxy()
        // registers the proxy as the newest deployment, which would otherwise route the invocations of every other
        // fault-injection test through it.
        return IntegrationEndpoint::withFaults()->withEnvironment(['RESTATE_PHP_TEST_PROXIED' => '1']);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::proxy()->heal();
    }

    /** The proxy, started and registered as the deployment on first use. */
    protected static function proxy(): FaultProxy
    {
        if (self::$proxy !== null) {
            return self::$proxy;
        }

        $proxy = FaultProxy::start('127.0.0.1', self::restate()->endpointPort(), self::stateDir());
        register_shutdown_function($proxy->stop(...));
        // The same services under a new URI: Restate sends new invocations to the newest deployment.
        self::restate()->registerDeployment('http://' . self::restate()->testHostForRestate() . ':' . $proxy->port, http2: false);

        return self::$proxy = $proxy;
    }

    /**
     * The proxied connections of an invocation's attempts, once they have all closed.
     *
     * @return list<array{connection: int, mode: string, accepted: int, request: ?string, invocation: ?string, end: ?string, closed: ?int}>
     */
    protected static function connectionsOf(string $invocationId, float $timeout = 10.0): array
    {
        $deadline = microtime(true) + $timeout;
        do {
            $connections = self::proxy()->connectionsOf($invocationId);
            if ($connections !== [] && array_filter($connections, static fn (array $c): bool => $c['closed'] === null) === []) {
                return $connections;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail("The proxied connections of {$invocationId} did not all close: " . json_encode($connections) . "\n" . self::proxy()->log());
    }
}
