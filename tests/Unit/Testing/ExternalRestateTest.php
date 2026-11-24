<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Restate\Testing\ExternalRestate;
use Restate\Testing\RestateContainer;

final class ExternalRestateTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    #[\Override]
    protected function setUp(): void
    {
        foreach ([ExternalRestate::ADMIN_URL_VARIABLE, ExternalRestate::INGRESS_URL_VARIABLE] as $name) {
            $this->saved[$name] = getenv($name);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function testTheUrlsAreKeptWithoutATrailingSlashAndTheDefaultsApply(): void
    {
        $restate = ExternalRestate::at('http://127.0.0.1:9070/', 'http://127.0.0.1:8080');

        self::assertSame('http://127.0.0.1:9070', $restate->adminUrl);
        self::assertSame('http://127.0.0.1:8080', $restate->ingressUrl);
        self::assertSame('127.0.0.1', $restate->testHost);
        self::assertNull($restate->logFile);
        self::assertSame(RestateContainer::DEFAULT_STARTUP_TIMEOUT_SECONDS, $restate->startupTimeoutSeconds);
    }

    public function testEveryOptionIsKeptAndTheOriginalIsUnchanged(): void
    {
        $original = ExternalRestate::at('http://127.0.0.1:9070', 'http://127.0.0.1:8080');
        $restate = $original
            ->withTestHost('host.docker.internal')
            ->withLogFile('/tmp/restate.log')
            ->withStartupTimeout(5);

        self::assertSame('host.docker.internal', $restate->testHost);
        self::assertSame('/tmp/restate.log', $restate->logFile);
        self::assertSame(5, $restate->startupTimeoutSeconds);
        self::assertSame('http://127.0.0.1:9070', $restate->adminUrl);
        self::assertEquals(ExternalRestate::at('http://127.0.0.1:9070', 'http://127.0.0.1:8080'), $original);
        self::assertSame('[::1]', $original->withTestHost('[::1]')->testHost);
    }

    public function testRejectsInvalidOptions(): void
    {
        $restate = ExternalRestate::at('http://127.0.0.1:9070', 'http://127.0.0.1:8080');
        foreach ([
            'admin URL without a scheme' => static fn () => ExternalRestate::at('127.0.0.1:9070', 'http://127.0.0.1:8080'),
            'ingress URL of another scheme' => static fn () => ExternalRestate::at('http://127.0.0.1:9070', 'unix:///tmp/restate.sock'),
            'URL with a query' => static fn () => ExternalRestate::at('http://127.0.0.1:9070?x=1', 'http://127.0.0.1:8080'),
            'URL with credentials' => static fn () => ExternalRestate::at('http://user:pass@127.0.0.1:9070', 'http://127.0.0.1:8080'),
            'empty test host' => static fn () => $restate->withTestHost(''),
            'test host with a port' => static fn () => $restate->withTestHost('127.0.0.1:9000'),
            'empty log file' => static fn () => $restate->withLogFile(''),
            'zero timeout' => static fn () => $restate->withStartupTimeout(0),
        ] as $case => $with) {
            try {
                $with();
                self::fail("Invalid option ({$case}) was accepted.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFromEnvironmentReadsTheVariablesThatDevenvExports(): void
    {
        putenv(ExternalRestate::ADMIN_URL_VARIABLE . '=http://127.0.0.1:9071');
        putenv(ExternalRestate::INGRESS_URL_VARIABLE . '=http://127.0.0.1:8081/');

        $restate = ExternalRestate::fromEnvironment();

        self::assertSame('http://127.0.0.1:9071', $restate->adminUrl);
        self::assertSame('http://127.0.0.1:8081', $restate->ingressUrl);
    }

    public function testFromEnvironmentNamesAMissingVariable(): void
    {
        putenv(ExternalRestate::ADMIN_URL_VARIABLE . '=http://127.0.0.1:9071');
        putenv(ExternalRestate::INGRESS_URL_VARIABLE);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(ExternalRestate::INGRESS_URL_VARIABLE . ' is not set');

        ExternalRestate::fromEnvironment();
    }
}
