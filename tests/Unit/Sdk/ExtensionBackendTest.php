<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\Internal\Backend\Extension\Backend as ExtensionBackend;
use Restate\Internal\BackendException;
use Restate\Internal\Package;

final class ExtensionBackendTest extends TestCase
{
    public function testHandshakeAcceptsTheContractVersionOfThisPackage(): void
    {
        $this->expectNotToPerformAssertions();

        ExtensionBackend::assertCompatible('9.9.9', ExtensionBackend::CONTRACT_VERSION);
    }

    public function testHandshakeRejectsAnOlderExtensionAndSaysToUpgradeIt(): void
    {
        $message = self::handshakeFailure('0.0.7', ExtensionBackend::CONTRACT_VERSION - 1);

        self::assertStringContainsString(sprintf('Restate PHP extension 0.0.7 implements contract version %d', ExtensionBackend::CONTRACT_VERSION - 1), $message);
        self::assertStringContainsString(Package::NAME, $message);
        self::assertStringContainsString(sprintf('requires Restate PHP extension contract version %d', ExtensionBackend::CONTRACT_VERSION), $message);
        self::assertStringContainsString('Upgrade the extension', $message);
        self::assertStringContainsString('pie install restate/ext-restate', $message);
    }

    public function testHandshakeRejectsANewerExtensionAndSaysToUpgradeThePackage(): void
    {
        $message = self::handshakeFailure('3.0.0', ExtensionBackend::CONTRACT_VERSION + 1);

        self::assertStringContainsString(sprintf('Restate PHP extension 3.0.0 implements contract version %d', ExtensionBackend::CONTRACT_VERSION + 1), $message);
        self::assertStringContainsString(sprintf('requires Restate PHP extension contract version %d', ExtensionBackend::CONTRACT_VERSION), $message);
        self::assertStringContainsString('The extension is newer than this package: upgrade ' . Package::NAME, $message);
    }

    public function testHandshakeNamesThePackageVersionComposerReports(): void
    {
        $version = Package::version();
        self::assertNotNull($version, 'Composer reports this package as installed.');

        self::assertStringContainsString(Package::NAME . ' ' . $version . ' requires', self::handshakeFailure('0.1.0', ExtensionBackend::CONTRACT_VERSION + 1));
    }

    private static function handshakeFailure(string $extensionVersion, int $contractVersion): string
    {
        try {
            ExtensionBackend::assertCompatible($extensionVersion, $contractVersion);
        } catch (BackendException $e) {
            return $e->getMessage();
        }

        self::fail('Expected the handshake to fail.');
    }
}
