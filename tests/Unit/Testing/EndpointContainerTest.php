<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Restate\Testing\EndpointContainer;

final class EndpointContainerTest extends TestCase
{
    public function testDefaults(): void
    {
        $container = EndpointContainer::forImage('example.com/app:1');

        self::assertSame('example.com/app:1', $container->image);
        self::assertSame(8080, $container->port);
        self::assertSame([], $container->volumes);
        self::assertSame([], $container->environment);
        self::assertSame([], $container->command);
        self::assertNull($container->bindAddress);
        self::assertSame('', $container->path);
        self::assertFalse($container->http2);
        self::assertSame([], $container->runArguments);
    }

    public function testEveryOptionIsKeptAndTheOriginalIsUnchanged(): void
    {
        $original = EndpointContainer::forImage('example.com/app:1', 9080);
        $container = $original
            ->withVolume(__DIR__, '/app', readOnly: true)
            ->withVolume(__DIR__ . '/..', '/data')
            ->withEnvironment(['APP_ENV' => 'test'])
            ->withEnvironment(['DEBUG' => '1'])
            ->withCommand(['frankenphp', 'run'])
            ->withBindAddress('127.0.0.1')
            ->withPath('/restate')
            ->withHttp2()
            ->withRunArguments(['--cpus', '1'])
            ->withRunArguments(['--memory', '256m']);

        self::assertSame(9080, $container->port);
        self::assertSame([
            ['hostPath' => realpath(__DIR__), 'containerPath' => '/app', 'readOnly' => true],
            ['hostPath' => realpath(__DIR__ . '/..'), 'containerPath' => '/data', 'readOnly' => false],
        ], $container->volumes);
        self::assertSame(['APP_ENV' => 'test', 'DEBUG' => '1'], $container->environment);
        self::assertSame(['frankenphp', 'run'], $container->command);
        self::assertSame('127.0.0.1', $container->bindAddress);
        self::assertSame('/restate', $container->path);
        self::assertTrue($container->http2);
        self::assertFalse($container->withHttp2(false)->http2);
        self::assertSame(['--cpus', '1', '--memory', '256m'], $container->runArguments);

        self::assertEquals(EndpointContainer::forImage('example.com/app:1', 9080), $original);
    }

    public function testRejectsAnEmptyImage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EndpointContainer::forImage('');
    }

    public function testRejectsPortsOutOfRange(): void
    {
        foreach ([0, 65536] as $port) {
            try {
                EndpointContainer::forImage('app', $port);
                self::fail("Port {$port} was accepted.");
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString((string) $port, $exception->getMessage());
            }
        }
    }

    public function testRejectsAMissingHostPathAndARelativeContainerPath(): void
    {
        $container = EndpointContainer::forImage('app');

        try {
            $container->withVolume('/does/not/exist', '/app');
            self::fail('A missing host path was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('/does/not/exist', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $container->withVolume(__DIR__, 'app');
    }

    public function testRejectsInvalidEnvironmentNamesBindAddressesAndPaths(): void
    {
        $container = EndpointContainer::forImage('app');

        foreach ([
            static fn () => $container->withEnvironment(['A=B' => 'x']),
            static fn () => $container->withBindAddress('localhost'),
            static fn () => $container->withPath('restate'),
            static fn () => $container->withCommand(['']),
            static fn () => $container->withRunArguments(['']),
            // The harness names, publishes and removes the container itself.
            static fn () => $container->withRunArguments(['--name', 'mine']),
            static fn () => $container->withRunArguments(['--publish=8080:8080']),
            static fn () => $container->withRunArguments(['-p8080:8080']),
            static fn () => $container->withRunArguments(['-d']),
            static fn () => $container->withRunArguments(['--rm']),
        ] as $case => $with) {
            try {
                $with();
                self::fail("Invalid option {$case} was accepted.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
