<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Restate\Testing\EndpointContainer;
use Restate\Testing\PhpCliServer;
use Restate\Testing\RestateContainer;
use Restate\Testing\RestateProcess;
use Restate\Testing\RestateTestEnvironment;
use Restate\Testing\TestEnvironmentException;

/**
 * What the harness does before it starts anything. Starting Restate for real is covered by the integration
 * suite, which runs on this harness.
 */
final class RestateTestEnvironmentTest extends TestCase
{
    private string|false $path = false;

    #[\Override]
    protected function setUp(): void
    {
        $this->path = getenv('PATH');
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv($this->path === false ? 'PATH' : "PATH={$this->path}");
    }

    public function testFailsBeforeStartingAnythingWhenTheConfiguredContainerRuntimeDoesNotExist(): void
    {
        try {
            RestateTestEnvironment::start(
                PhpCliServer::forScript(__FILE__),
                RestateContainer::default()->withContainerRuntime('/does/not/exist/docker'),
            );
            self::fail('The environment started without a container runtime.');
        } catch (TestEnvironmentException $exception) {
            self::assertStringContainsString('/does/not/exist/docker', $exception->getMessage());
        }
    }

    public function testFailsWithAClearMessageWhenNoContainerRuntimeIsOnThePath(): void
    {
        putenv('PATH=' . sys_get_temp_dir() . '/restate-php-no-such-directory');

        $this->expectException(TestEnvironmentException::class);
        $this->expectExceptionMessageMatches('/`docker`.*`podman`/');

        RestateTestEnvironment::start(PhpCliServer::forScript(__FILE__));
    }

    public function testARestateProcessNeedsNoContainerRuntimeAndFailsBeforeStartingAnythingWithoutItsBinary(): void
    {
        // Neither docker, podman nor restate-server on PATH: the failure names restate-server, not a container runtime.
        putenv('PATH=' . sys_get_temp_dir() . '/restate-php-no-such-directory');

        try {
            RestateTestEnvironment::start(PhpCliServer::forScript(__FILE__), RestateProcess::default());
            self::fail('The environment started without restate-server.');
        } catch (TestEnvironmentException $exception) {
            self::assertStringContainsString('`restate-server` on PATH', $exception->getMessage());
            self::assertStringContainsString('RestateProcess::withBinary()', $exception->getMessage());
            self::assertStringNotContainsString('docker', $exception->getMessage());
        }
    }

    public function testARestateProcessWithAMissingBinaryNamesIt(): void
    {
        $this->expectException(TestEnvironmentException::class);
        $this->expectExceptionMessage('/does/not/exist/restate-server was not found or is not executable');

        RestateTestEnvironment::start(PhpCliServer::forScript(__FILE__), RestateProcess::default()->withBinary('/does/not/exist/restate-server'));
    }

    public function testAnEndpointContainerWithARestateProcessStillNeedsAContainerRuntime(): void
    {
        putenv('PATH=' . sys_get_temp_dir() . '/restate-php-no-such-directory');

        $this->expectException(TestEnvironmentException::class);
        $this->expectExceptionMessageMatches('/`docker`.*`podman`/');

        RestateTestEnvironment::start(EndpointContainer::forImage('example.com/endpoint:dev'), RestateProcess::default()->withBinary(PHP_BINARY));
    }

    public function testASharedEnvironmentThatFailedToStartIsNotStartedAgain(): void
    {
        $endpoint = PhpCliServer::forScript(__FILE__)->withEnvironment(['RESTATE_PHP_TEST_SHARED_FAILURE' => bin2hex(random_bytes(4))]);
        $restate = RestateContainer::default()->withContainerRuntime('/does/not/exist/docker');

        try {
            RestateTestEnvironment::shared($endpoint, $restate);
            self::fail('The environment started without a container runtime.');
        } catch (TestEnvironmentException $first) {
        }

        try {
            RestateTestEnvironment::shared($endpoint, $restate);
            self::fail('The environment started without a container runtime.');
        } catch (TestEnvironmentException $second) {
            self::assertStringContainsString('failed to start earlier', $second->getMessage());
            self::assertSame($first, $second->getPrevious());
        }
    }
}
