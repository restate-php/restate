<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Restate\Testing\EndpointContainer;
use Restate\Testing\Internal\Commands;
use Restate\Testing\PhpCliServer;
use Restate\Testing\RestateContainer;
use Restate\Testing\RestateProcess;
use Restate\Testing\RestateTestEnvironment;

final class CommandsTest extends TestCase
{
    public function testPhpCliServerLoadsTheExtensionAndIniSettingsAndServesTheRouterScript(): void
    {
        $server = PhpCliServer::forScript(__FILE__)
            ->withIniSetting('memory_limit', '256M')
            ->withIniSetting('display_errors', '1')
            ->withDocumentRoot(__DIR__ . '/..');

        self::assertSame(
            [
                '/usr/bin/php', '-d', 'extension=/ext/restate.so',
                // The defaults, one replaced in place, then the added setting.
                '-d', 'display_errors=1', '-d', 'log_errors=1', '-d', 'enable_post_data_reading=0',
                '-d', 'memory_limit=256M', '-S', '0.0.0.0:8123', '-t', realpath(__DIR__ . '/..'), realpath(__FILE__)],
            Commands::phpCliServer($server, '/usr/bin/php', '/ext/restate.so', '0.0.0.0', 8123),
        );
    }

    public function testPhpCliServerWithoutAnExtensionPathLeavesItToPhpIniAndDefaultsTheDocumentRootToTheScriptDirectory(): void
    {
        self::assertSame(
            ['php', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'enable_post_data_reading=0', '-S', '[::]:8123', '-t', realpath(__DIR__), realpath(__FILE__)],
            Commands::phpCliServer(PhpCliServer::forScript(__FILE__), 'php', null, '::', 8123),
        );
    }

    public function testRestatePublishesBothPortsOnLoopbackOnlyAndReachesTheHostThroughHostDockerInternal(): void
    {
        $command = Commands::restate(
            'docker',
            RestateContainer::forImage('example.com/restate:dev')->withEnvironment(['RESTATE_LOG_FILTER' => 'debug', 'RESTATE_DEFAULT_NUM_PARTITIONS' => '2']),
            'restate-1',
            32768,
            32769,
        );

        self::assertSame(['docker', 'run', '--detach', '--name', 'restate-1', '--label', RestateTestEnvironment::CONTAINER_LABEL], array_slice($command, 0, 7));
        self::assertSame(['example.com/restate:dev'], array_slice($command, -1));
        $options = implode(' ', $command);
        self::assertStringContainsString('--add-host host.docker.internal:host-gateway', $options);
        // Host ports the harness chose, so that a restart keeps them.
        self::assertStringContainsString('--publish 127.0.0.1:32768:8080 --publish 127.0.0.1:32769:9070 example.com/restate:dev', $options);
        self::assertStringContainsString('--env RESTATE_LOG_FILTER=debug', $options);
        // Configured variables replace the harness defaults.
        self::assertStringContainsString('--env RESTATE_DEFAULT_NUM_PARTITIONS=2', $options);
        self::assertStringNotContainsString('RESTATE_DEFAULT_NUM_PARTITIONS=1', $options);
        self::assertStringNotContainsString('--volume', $options);
    }

    public function testARestateProcessGetsTheHarnessDefaultsItsConfigurationAndLoopbackAddresses(): void
    {
        self::assertSame(['/opt/bin/restate-server', '--no-logo'], Commands::restateProcess('/opt/bin/restate-server'));

        $environment = Commands::restateProcessEnvironment(
            RestateProcess::default()->withEnvironment(['RESTATE_LOG_FILTER' => 'debug', 'RESTATE_DEFAULT_NUM_PARTITIONS' => '2']),
            '/tmp/scratch/restate',
            'restate-php-test',
            32768,
            32769,
            32770,
        );

        self::assertSame([
            // The defaults, as for a container, with a configured one replaced; then the configuration.
            'RESTATE_DEFAULT_NUM_PARTITIONS' => '2',
            'RESTATE_LOG_FORMAT' => 'compact',
            'RESTATE_LOG_DISABLE_ANSI_CODES' => 'true',
            'RESTATE_LOG_FILTER' => 'debug',
            // What the harness owns: the data directory, TCP on loopback ports it chose (so a restart keeps them).
            'RESTATE_BASE_DIR' => '/tmp/scratch/restate',
            'RESTATE_NODE_NAME' => 'restate-php-test',
            'RESTATE_LISTEN_MODE' => 'tcp',
            'RESTATE_BIND_ADDRESS' => '127.0.0.1:32770',
            'RESTATE_ADVERTISED_ADDRESS' => 'http://127.0.0.1:32770/',
            'RESTATE_INGRESS__BIND_ADDRESS' => '127.0.0.1:32768',
            'RESTATE_ADMIN__BIND_ADDRESS' => '127.0.0.1:32769',
        ], $environment);
        self::assertSame(RestateProcess::HARNESS_VARIABLES, array_values(array_intersect(RestateProcess::HARNESS_VARIABLES, array_keys($environment))));
    }

    public function testARestateProcessRejectsAPortOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Commands::restateProcessEnvironment(RestateProcess::default(), '/tmp/restate', 'node', 8080, 9070, 0);
    }

    public function testRestateMountsItsVolumes(): void
    {
        $command = Commands::restate(
            'docker',
            RestateContainer::forImage('example.com/restate:dev')
                ->withVolume(__FILE__, '/etc/restate/key.pem', readOnly: true)
                ->withVolume(__DIR__, '/data'),
            'restate-1',
            32768,
            32769,
        );

        self::assertStringContainsString(
            '--volume ' . realpath(__FILE__) . ':/etc/restate/key.pem:ro --volume ' . realpath(__DIR__) . ':/data ',
            implode(' ', $command),
        );
        self::assertSame(['example.com/restate:dev'], array_slice($command, -1));
    }

    public function testEndpointContainerPublishesItsPortOnTheBindAddress(): void
    {
        $container = EndpointContainer::forImage('example.com/app:1', 9080)
            ->withVolume(__DIR__, '/app', readOnly: true)
            ->withEnvironment(['APP_ENV' => 'test'])
            ->withCommand(['frankenphp', 'run']);

        self::assertSame(
            [
                'podman', 'run', '--rm', '--name', 'endpoint-1', '--label', RestateTestEnvironment::CONTAINER_LABEL,
                '--volume', realpath(__DIR__) . ':/app:ro',
                '--env', 'APP_ENV=test',
                '--publish', '[::1]:8123:9080',
                'example.com/app:1', 'frankenphp', 'run',
            ],
            Commands::endpointContainer('podman', $container, 'endpoint-1', '::1', 8123),
        );
    }

    public function testRunArgumentsGoRightBeforeTheImage(): void
    {
        $restate = Commands::restate(
            'docker',
            RestateContainer::forImage('example.com/restate:dev')->withRunArguments(['--cpus', '2'])->withRunArguments(['--memory', '1g']),
            'restate-1',
            32768,
            32769,
        );
        self::assertSame(['--publish', '127.0.0.1:32769:9070', '--cpus', '2', '--memory', '1g', 'example.com/restate:dev'], array_slice($restate, -7));

        $endpoint = Commands::endpointContainer(
            'docker',
            EndpointContainer::forImage('example.com/app:1')->withRunArguments(['--memory=256m'])->withCommand(['php-fpm']),
            'endpoint-1',
            '0.0.0.0',
            8123,
        );
        self::assertSame(['--publish', '0.0.0.0:8123:8080', '--memory=256m', 'example.com/app:1', 'php-fpm'], array_slice($endpoint, -5));
    }

    public function testRestateRejectsAPortOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Commands::restate('docker', RestateContainer::forImage('restate'), 'restate-1', 0, 9070);
    }

    public function testContainerLifecycleCommands(): void
    {
        self::assertSame(['podman', 'stop', '--time', '10', 'restate-1'], Commands::stopContainer('podman', 'restate-1', 10));
        self::assertSame(['docker', 'stop', '--time', '0', 'restate-1'], Commands::stopContainer('docker', 'restate-1', -3));
        self::assertSame(['docker', 'kill', '--signal', '9', 'restate-1'], Commands::signalContainer('docker', 'restate-1', 9));
        self::assertSame(['docker', 'kill', '--signal', '12', 'endpoint-1'], Commands::signalContainer('docker', 'endpoint-1', 12));
        foreach (['start', 'pause', 'unpause', 'wait'] as $verb) {
            self::assertSame(['docker', $verb, 'restate-1'], Commands::container('docker', $verb, 'restate-1'));
        }
        self::assertSame(['docker', 'rm', '--force', '--volumes', 'restate-1'], Commands::removeContainer('docker', 'restate-1'));
    }

    public function testHostAddressesAreBracketedForIpv6AndTheWildcardBecomesLoopback(): void
    {
        self::assertSame('127.0.0.1', Commands::reachableHost('0.0.0.0'));
        self::assertSame('[::1]', Commands::reachableHost('::'));
        self::assertSame('192.168.1.5', Commands::reachableHost('192.168.1.5'));
        self::assertSame('[fd00::5]', Commands::reachableHost('fd00::5'));
    }
}
