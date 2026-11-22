<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\Backend;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Restate\Tests\Support\ExtensionProcess;
use Restate\Tests\Support\Repository;

/**
 * Runs the `extension` suite (tests/Extension: the extension backend against a real VM, and the backend contract the
 * fakes check against) in a child PHP process with the built extension loaded, and fails with its output when any of
 * it fails. The extension cannot be loaded into this process, which tests the paths without it.
 */
#[Group('extension')]
final class ExtensionSuiteTest extends TestCase
{
    public function testExtensionSuitePasses(): void
    {
        $output = ExtensionProcess::runFile(Repository::ROOT . '/vendor/bin/phpunit', [
            '--configuration',
            Repository::ROOT . '/phpunit.xml.dist',
            '--testsuite',
            'extension',
            '--do-not-cache-result',
            '--colors=never',
        ]);

        self::assertMatchesRegularExpression('/^OK \(\d+ tests?, \d+ assertions?\)$/m', $output, $output);
    }
}
