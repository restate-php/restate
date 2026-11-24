<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Restate\Tests\Integration\Support\FaultInjectionTestCase;
use Restate\Tests\Integration\Support\IntegrationEndpoint;
use Restate\Testing\EndpointContainer;
use Restate\Testing\PhpCliServer;

/**
 * The kernel's OOM killer instead of PHP's `memory_limit`. The php-fpm endpoint container gets a 256 MiB
 * memory limit (`--memory`, no swap), and the handler lifts `memory_limit` (-1) and allocates until the cgroup's OOM
 * killer ends its worker with SIGKILL: no fatal error, no 500, the front door sees the worker die. php-fpm replaces the
 * worker, Restate retries, and the next attempt completes; the container survives. Compare AbruptEndTest, where
 * `memory_limit` ends the attempt with PHP's fatal error and a 500. php-fpm only (a `php -S` endpoint is no
 * container).
 */
#[Group('failure-nightly')]
#[Group('managed')]
final class ContainerOomTest extends FaultInjectionTestCase
{
    #[\Override]
    protected static function restateEndpoint(): PhpCliServer|EndpointContainer
    {
        $endpoint = IntegrationEndpoint::withFaults();

        return $endpoint instanceof EndpointContainer
            ? $endpoint->withRunArguments(['--memory', '256m', '--memory-swap', '256m'])
            : $endpoint;
    }

    #[\Override]
    protected function setUp(): void
    {
        if (!self::isFpm()) {
            self::markTestSkipped('Runs on php-fpm (RESTATE_PHP_TEST_SERVER=nginx-fpm, nginx-fpm-h2c or caddy-fpm).');
        }
        parent::setUp();
    }

    public function testTheOomKillerEndsTheWorkerAndTheRetryCompletes(): void
    {
        $offset = self::restate()->endpointLogSize();
        $id = self::sendInvocation('Chaos/exhaustMemoryInHandler', '{"limit":"-1","times":1}');

        $failure = self::waitForRow($id, static fn (array $row): bool => is_string($row['last_failure'] ?? null) && $row['last_failure'] !== '' || ($row['status'] ?? null) === 'completed', what: 'a failure');
        self::assertSame('2', self::attachInvocation($id, 30.0)->body, 'Failure seen: ' . json_encode($failure, JSON_UNESCAPED_SLASHES));
        self::assertCount(2, self::chaos()->attempts($id));
        self::assertTrue(self::restate()->isEndpointRunning(), 'The OOM killer took the whole endpoint container down.');

        $deadline = microtime(true) + 5;
        while (!str_contains(self::restate()->endpointLog($offset), 'SIGKILL') && microtime(true) < $deadline) {
            usleep(100_000);
        }
        // php-fpm: "child N exited on signal 9 (SIGKILL)"; never PHP's own "Allowed memory size" fatal error.
        self::assertStringContainsString('exited on signal 9 (SIGKILL)', self::restate()->endpointLog($offset));
        self::assertStringNotContainsString('Allowed memory size', self::restate()->endpointLog($offset));
        self::assertEndpointServes(3);
    }
}
