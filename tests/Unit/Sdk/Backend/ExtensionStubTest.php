<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\Backend;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Restate\Tests\Support\ExtensionProcess;
use Restate\Tests\Support\Repository;

/**
 * extension/restate.stub.php must describe exactly what the built extension defines: the same classes, functions,
 * methods, parameters, return types, properties and constants. Reflection of the loaded extension and of the stub
 * file (each in its own process, since both define the same names) must agree.
 *
 * Stub functions marked `@restate-optional` exist only in some builds (a cargo feature, such as `diagnostics()`):
 * a build without one is fine, a build with one must match the stub like everything else. Run the test against such
 * a build with RESTATE_PHP_EXTENSION_PATH.
 */
#[Group('extension')]
final class ExtensionStubTest extends TestCase
{
    private const string SURFACE_SCRIPT = __DIR__ . '/../../../Support/extension-surface.php';

    private const string STUB = Repository::ROOT . '/extension/restate.stub.php';

    /** What every build defines, optional symbols aside. */
    private const array FUNCTIONS = ['Restate\\Extension\\contract_version', 'Restate\\Extension\\protocol_versions', 'Restate\\Extension\\version'];

    public function testStubDescribesTheLoadedExtensionExactly(): void
    {
        $extension = self::surface(ExtensionProcess::runFile(self::SURFACE_SCRIPT, ['extension']));
        $stub = self::surface(ExtensionProcess::runFile(self::SURFACE_SCRIPT, ['stub', self::STUB], loadExtension: false));

        foreach ($stub['optional'] as $name) {
            if (!isset($extension['functions'][$name])) {
                unset($stub['functions'][$name]);
            }
        }
        unset($stub['optional'], $extension['optional']);

        self::assertSame(
            json_encode($extension, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            json_encode($stub, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'extension/restate.stub.php differs from the loaded extension; update the stub (or the extension).',
        );
    }

    public function testEverythingTheExtensionDefinesIsInTheExtensionNamespace(): void
    {
        $extension = self::surface(ExtensionProcess::runFile(self::SURFACE_SCRIPT, ['extension']));
        $stub = self::surface(ExtensionProcess::runFile(self::SURFACE_SCRIPT, ['stub', self::STUB], loadExtension: false));

        self::assertSame([], $extension['outsideNamespace']);
        self::assertSame(['Restate\\Extension\\ExtensionException', 'Restate\\Extension\\IdentityVerifier', 'Restate\\Extension\\Vm'], array_keys($extension['classes']));
        self::assertSame(self::FUNCTIONS, array_values(array_diff(array_keys($extension['functions']), $stub['optional'])));
    }

    public function testOnlyTheDiagnosticsFunctionIsOptional(): void
    {
        $stub = self::surface(ExtensionProcess::runFile(self::SURFACE_SCRIPT, ['stub', self::STUB], loadExtension: false));

        self::assertSame(['Restate\\Extension\\diagnostics'], $stub['optional']);
    }

    /** @return array{classes: array<string, mixed>, functions: array<string, mixed>, optional: list<string>, outsideNamespace: list<string>} */
    private static function surface(string $json): array
    {
        $surface = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($surface);

        /** @var array{classes: array<string, mixed>, functions: array<string, mixed>, optional: list<string>, outsideNamespace: list<string>} $surface */
        return $surface;
    }
}
