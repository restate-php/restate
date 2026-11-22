<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Restate\Tests\Support\Repository;

/**
 * The framework-agnostic part of the test harness never loads PHPUnit: PHPUnit is a development dependency of the
 * SDK, and only `Restate\Testing\PHPUnit\` may need it.
 */
final class TestingWithoutPhpUnitTest extends TestCase
{
    public function testConfiguringAndStartingTheHarnessLoadsNoPhpUnitClass(): void
    {
        $code = <<<'PHP'
            require AUTOLOAD;
            spl_autoload_register(static function (string $class): void {
                if (str_starts_with($class, 'PHPUnit\\')) {
                    fwrite(STDOUT, "loaded {$class}\n");
                    exit(1);
                }
            }, prepend: true);

            $endpoint = Restate\Testing\PhpCliServer::forScript(AUTOLOAD)->withWorkers(2);
            Restate\Testing\EndpointContainer::forImage('example.com/app:1');
            $restate = Restate\Testing\RestateContainer::default()->withContainerRuntime('/does/not/exist/docker');
            try {
                Restate\Testing\RestateTestEnvironment::shared($endpoint, $restate);
            } catch (Restate\Testing\TestEnvironmentException) {
                echo "ok\n";
            }
            PHP;

        $process = proc_open(
            [PHP_BINARY, '-r', str_replace('AUTOLOAD', var_export(Repository::ROOT . '/vendor/autoload.php', true), $code)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $stdout . $stderr);
        self::assertSame("ok\n", $stdout, $stderr);
    }
}
