<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Restate\Testing\RestateContainer;

final class RestateContainerTest extends TestCase
{
    private string|false $imageVariable = false;

    #[\Override]
    protected function setUp(): void
    {
        $this->imageVariable = getenv('RESTATE_PHP_RESTATE_IMAGE');
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv($this->imageVariable === false ? 'RESTATE_PHP_RESTATE_IMAGE' : "RESTATE_PHP_RESTATE_IMAGE={$this->imageVariable}");
    }

    public function testTheDefaultImageIsPinned(): void
    {
        putenv('RESTATE_PHP_RESTATE_IMAGE');
        $container = RestateContainer::default();

        self::assertSame(RestateContainer::DEFAULT_IMAGE, $container->image);
        self::assertMatchesRegularExpression('/:\d+\.\d+\.\d+$/', RestateContainer::DEFAULT_IMAGE);
        self::assertSame([], $container->environment);
        self::assertSame([], $container->volumes);
        self::assertNull($container->containerRuntime);
        self::assertSame(RestateContainer::DEFAULT_STARTUP_TIMEOUT_SECONDS, $container->startupTimeoutSeconds);
        self::assertSame([], $container->runArguments);
    }

    public function testTheImageVariableReplacesTheDefaultImage(): void
    {
        putenv('RESTATE_PHP_RESTATE_IMAGE=docker.restate.dev/restatedev/restate:1.7.14');

        self::assertSame('docker.restate.dev/restatedev/restate:1.7.14', RestateContainer::default()->image);
    }

    public function testAnExplicitImageWinsOverTheImageVariable(): void
    {
        putenv('RESTATE_PHP_RESTATE_IMAGE=docker.restate.dev/restatedev/restate:1.7.14');

        self::assertSame('example.com/restate:dev', RestateContainer::forImage('example.com/restate:dev')->image);
    }

    public function testEveryOptionIsKeptAndTheOriginalIsUnchanged(): void
    {
        $original = RestateContainer::forImage('example.com/restate:dev');
        $container = $original
            ->withEnvironment(['RESTATE_WORKER__INVOKER__INACTIVITY_TIMEOUT' => '0s'])
            ->withEnvironment(['RESTATE_LOG_FILTER' => 'restate=debug'])
            ->withVolume(__FILE__, '/etc/restate/key.pem', readOnly: true)
            ->withVolume(__DIR__, '/data')
            ->withContainerRuntime('podman')
            ->withStartupTimeout(5)
            ->withRunArguments(['--cpus', '2'])
            ->withRunArguments(['--memory=1g']);

        self::assertSame(
            ['RESTATE_WORKER__INVOKER__INACTIVITY_TIMEOUT' => '0s', 'RESTATE_LOG_FILTER' => 'restate=debug'],
            $container->environment,
        );
        self::assertSame([
            ['hostPath' => realpath(__FILE__), 'containerPath' => '/etc/restate/key.pem', 'readOnly' => true],
            ['hostPath' => realpath(__DIR__), 'containerPath' => '/data', 'readOnly' => false],
        ], $container->volumes);
        self::assertSame('podman', $container->containerRuntime);
        self::assertSame(5, $container->startupTimeoutSeconds);
        self::assertSame(['--cpus', '2', '--memory=1g'], $container->runArguments);

        self::assertEquals(RestateContainer::forImage('example.com/restate:dev'), $original);
    }

    public function testRejectsInvalidOptions(): void
    {
        foreach ([
            'empty image' => static fn () => RestateContainer::forImage(''),
            'environment name' => static fn () => RestateContainer::default()->withEnvironment(['' => 'x']),
            'empty runtime' => static fn () => RestateContainer::default()->withContainerRuntime(''),
            'zero timeout' => static fn () => RestateContainer::default()->withStartupTimeout(0),
            'missing volume source' => static fn () => RestateContainer::default()->withVolume('/does/not/exist', '/data'),
            'relative volume target' => static fn () => RestateContainer::default()->withVolume(__DIR__, 'data'),
            'volume target with a colon' => static fn () => RestateContainer::default()->withVolume(__DIR__, '/data:z'),
            'empty run argument' => static fn () => RestateContainer::default()->withRunArguments(['--cpus', '']),
            'run argument the harness sets' => static fn () => RestateContainer::default()->withRunArguments(['--publish', '8080:8080']),
            'run argument the harness sets, short' => static fn () => RestateContainer::default()->withRunArguments(['-P']),
            'run argument the harness sets, with a value' => static fn () => RestateContainer::default()->withRunArguments(['--name=restate']),
            'detached' => static fn () => RestateContainer::default()->withRunArguments(['--detach']),
        ] as $case => $with) {
            try {
                $with();
                self::fail("Invalid option ({$case}) was accepted.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
