<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Restate\Testing\Internal\ExtensionLocator;

final class ExtensionLocatorTest extends TestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/restate-php-extension-locator-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/extension/target/debug', 0o700, true);
        mkdir($this->root . '/extension/target/release', 0o700, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (['debug', 'release'] as $profile) {
            @unlink("{$this->root}/extension/target/{$profile}/restate.so");
            rmdir("{$this->root}/extension/target/{$profile}");
        }
        rmdir($this->root . '/extension/target');
        rmdir($this->root . '/extension');
        rmdir($this->root);
    }

    public function testAnExplicitPathWins(): void
    {
        $this->build('debug');

        self::assertSame('/explicit.so', ExtensionLocator::locate('/explicit.so', '/from-env.so', $this->root));
    }

    public function testThenTheEnvironmentVariable(): void
    {
        $this->build('debug');
        $path = realpath(__FILE__);
        self::assertIsString($path);

        self::assertSame($path, ExtensionLocator::locate(null, __FILE__, $this->root));
    }

    public function testAnEnvironmentVariableNamingNoFileFails(): void
    {
        $this->expectException(\Restate\Testing\TestEnvironmentException::class);
        $this->expectExceptionMessage('RESTATE_PHP_EXTENSION_PATH');

        ExtensionLocator::locate(null, '/does/not/exist.so', $this->root);
    }

    public function testThenTheDebugBuildOfThisPackage(): void
    {
        $this->build('release');
        $this->build('debug');

        self::assertSame(realpath("{$this->root}/extension/target/debug/restate.so"), ExtensionLocator::locate(null, null, $this->root));
    }

    public function testThenTheReleaseBuild(): void
    {
        $this->build('release');

        self::assertSame(realpath("{$this->root}/extension/target/release/restate.so"), ExtensionLocator::locate(null, null, $this->root));
    }

    public function testOtherwiseNothingSoPhpIniDecides(): void
    {
        self::assertNull(ExtensionLocator::locate(null, null, $this->root));
    }

    public function testWithoutAProjectRootNothingSoPhpIniDecides(): void
    {
        $this->build('debug');
        self::assertNull(ExtensionLocator::locate(null, null, null));
    }

    private function build(string $profile): void
    {
        touch("{$this->root}/extension/target/{$profile}/restate.so");
    }
}
