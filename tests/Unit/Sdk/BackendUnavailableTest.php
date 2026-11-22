<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Restate\Internal\Backend\Extension\Backend as ExtensionBackend;
use Restate\Internal\Package;
use Restate\Tests\Support\ExtensionProcess;

/**
 * The extension is the only backend: building an endpoint without it fails, saying how to install it and which
 * version this package needs. Each case runs in a child process so that whether the extension is loaded is explicit.
 */
final class BackendUnavailableTest extends TestCase
{
    private const string BUILD_ENDPOINT = <<<'PHP'
        try {
            BUILD;
            $result = ['built' => true];
        } catch (Throwable $e) {
            $result = ['built' => false, 'class' => $e::class, 'message' => $e->getMessage()];
        }
        echo json_encode(['extension_loaded' => extension_loaded('restate')] + $result);
        PHP;

    public function testBuildingAnEndpointWithoutTheExtensionSaysHowToInstallItAndWhichVersionIsRequired(): void
    {
        foreach ([
            'builder' => "Restate\\Endpoint::builder()->bind(Restate\\Service::new('Greeter'))->build()",
            'fromServices' => "Restate\\Endpoint::fromServices([Restate\\Service::new('Greeter')])",
            'psr' => "Restate\\Endpoint::psr([Restate\\Service::new('Greeter')], new Restate\\Tests\\Unit\\Sdk\\PsrTestResponseFactory())",
        ] as $entry => $build) {
            $result = ExtensionProcess::runJson(self::BUILD_ENDPOINT, ['BUILD' => $build], loadExtension: false);

            self::assertIsArray($result);
            self::assertFalse($result['extension_loaded'], $entry);
            self::assertFalse($result['built'], $entry);
            self::assertSame(\Restate\BackendUnavailableException::class, $result['class'], $entry);
            self::assertIsString($result['message']);
            self::assertStringContainsString('The Restate PHP extension (restate) is not loaded', $result['message'], $entry);
            self::assertStringContainsString(
                sprintf('%s %s requires Restate PHP extension contract version %d', Package::NAME, Package::version(), ExtensionBackend::CONTRACT_VERSION),
                $result['message'],
                $entry,
            );
            self::assertStringContainsString('pie install restate/ext-restate', $result['message'], $entry);
            self::assertStringContainsString('extension=', $result['message'], $entry);
        }
    }

    #[Group('extension')]
    public function testBuildingAnEndpointWithTheExtensionLoadedServesRequests(): void
    {
        $result = ExtensionProcess::runJson(<<<'PHP'
            $endpoint = Restate\Endpoint::fromServices([Restate\Service::new('Greeter')]);
            echo json_encode([
                'extension_loaded' => extension_loaded('restate'),
                'health' => $endpoint->handleRequest(['REQUEST_URI' => '/health'], '')->statusCode,
            ]);
            PHP);

        self::assertSame(['extension_loaded' => true, 'health' => 200], $result);
    }
}
