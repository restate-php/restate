<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Testing\PhpCliServer;

final class PhpCliServerTest extends TestCase
{
    public function testDefaults(): void
    {
        $server = PhpCliServer::forScript(__FILE__);

        self::assertSame(realpath(__FILE__), $server->routerScript);
        self::assertNull($server->documentRoot);
        self::assertNull($server->phpBinary);
        self::assertNull($server->extensionPath);
        self::assertSame(['display_errors' => '0', 'log_errors' => '1', 'enable_post_data_reading' => '0'], $server->iniSettings);
        self::assertSame(PhpCliServer::DEFAULT_INI_SETTINGS, $server->iniSettings);
        self::assertSame([], $server->environment);
        self::assertSame(PhpCliServer::DEFAULT_WORKERS, $server->workers);
        self::assertNull($server->bindAddress);
        self::assertNull($server->workingDirectory);
        self::assertSame('', $server->path);
    }

    public function testEveryOptionIsKeptAndTheOriginalIsUnchanged(): void
    {
        $original = PhpCliServer::forScript(__FILE__);
        $server = $original
            ->withDocumentRoot(__DIR__ . '/..')
            ->withPhpBinary('/usr/bin/php8.4')
            ->withExtension(__FILE__)
            ->withIniSetting('memory_limit', '256M')
            ->withIniSetting('display_errors', 'stderr')
            ->withEnvironment(['APP_ENV' => 'test'])
            ->withEnvironment(['DEBUG' => '1'])
            ->withWorkers(2)
            ->withBindAddress('::')
            ->withWorkingDirectory(__DIR__)
            ->withPath('/restate');

        self::assertSame(realpath(__DIR__ . '/..'), $server->documentRoot);
        self::assertSame('/usr/bin/php8.4', $server->phpBinary);
        self::assertSame(realpath(__FILE__), $server->extensionPath);
        // display_errors replaces the default in place; memory_limit is added after the defaults.
        self::assertSame(['display_errors' => 'stderr', 'log_errors' => '1', 'enable_post_data_reading' => '0', 'memory_limit' => '256M'], $server->iniSettings);
        self::assertSame(['APP_ENV' => 'test', 'DEBUG' => '1'], $server->environment);
        self::assertSame(2, $server->workers);
        self::assertSame('::', $server->bindAddress);
        self::assertSame(realpath(__DIR__), $server->workingDirectory);
        self::assertSame('/restate', $server->path);

        self::assertEquals(PhpCliServer::forScript(__FILE__), $original);
    }

    public function testRejectsAMissingRouterScript(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/does/not/exist.php');

        PhpCliServer::forScript('/does/not/exist.php');
    }

    public function testRejectsAMissingExtension(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/does/not/exist.so');

        PhpCliServer::forScript(__FILE__)->withExtension('/does/not/exist.so');
    }

    public function testRejectsMissingDirectories(): void
    {
        $server = PhpCliServer::forScript(__FILE__);

        foreach ([$server->withDocumentRoot(...), $server->withWorkingDirectory(...)] as $with) {
            try {
                $with('/does/not/exist');
                self::fail('A missing directory was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('/does/not/exist', $exception->getMessage());
            }
        }
    }

    public function testRejectsAnEmptyPhpBinary(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PhpCliServer::forScript(__FILE__)->withPhpBinary('');
    }

    public function testRejectsFewerThanOneWorker(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 1');

        PhpCliServer::forScript(__FILE__)->withWorkers(0);
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidIniSettingNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PhpCliServer::forScript(__FILE__)->withIniSetting($name, 'x');
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidEnvironmentVariableNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PhpCliServer::forScript(__FILE__)->withEnvironment([$name => 'x']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'with =' => ['a=b'];
        yield 'with NUL' => ["a\0b"];
    }

    #[DataProvider('invalidBindAddresses')]
    public function testRejectsBindAddressesThatAreNotIpAddresses(string $address): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PhpCliServer::forScript(__FILE__)->withBindAddress($address);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBindAddresses(): iterable
    {
        yield 'empty' => [''];
        yield 'host name' => ['localhost'];
        yield 'with port' => ['127.0.0.1:8080'];
        yield 'bracketed IPv6' => ['[::1]'];
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsInvalidPaths(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PhpCliServer::forScript(__FILE__)->withPath($path);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'relative' => ['restate'];
        yield 'trailing slash' => ['/restate/'];
        yield 'root' => ['/'];
        yield 'query' => ['/restate?x=1'];
    }
}
