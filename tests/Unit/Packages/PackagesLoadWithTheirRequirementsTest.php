<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Packages;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Tests\Support\Packages;

/**
 * Every type of every package in packages/ loads in a plain PHP process (`php -n`: no php.ini, so no Restate
 * extension) with only the packages its composer.json requires, directly or through them; a class from anywhere else
 * is refused (docs/decisions/0029-package-split.md). `just check-packages` checks the same requirements statically,
 * over every class, function and constant the code names; this checks the class hierarchy as PHP resolves it.
 */
final class PackagesLoadWithTheirRequirementsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function packages(): iterable
    {
        foreach (array_keys(Packages::NAMESPACES) as $package) {
            yield $package => [$package];
        }
    }

    #[DataProvider('packages')]
    public function testEveryTypeLoadsWithOnlyThePackagesItRequires(string $package): void
    {
        $script = __DIR__ . '/load-package.php';
        $process = proc_open([PHP_BINARY, '-n', $script, $package], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "php -n {$script} {$package} failed:\n{$stderr}\n{$stdout}");

        /** @var array{types: int, loaded: int, refused: array<string, string>, errors: array<string, string>, extensions: list<string>} $output */
        $output = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);

        self::assertNotContains('restate', $output['extensions']);
        self::assertSame([], $output['refused'], "restate/{$package} loads classes of packages it does not require.");
        self::assertSame([], $output['errors']);
        self::assertGreaterThan(0, $output['types']);
        self::assertSame($output['types'], $output['loaded']);
    }

    public function testTheListOfPackagesIsComplete(): void
    {
        $directories = glob(dirname(__DIR__, 3) . '/packages/*', GLOB_ONLYDIR);
        self::assertIsArray($directories);
        $directories = array_map(basename(...), $directories);
        sort($directories);
        $known = array_keys(Packages::NAMESPACES);
        sort($known);

        self::assertSame($directories, $known);
    }
}
