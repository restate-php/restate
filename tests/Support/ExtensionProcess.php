<?php

declare(strict_types=1);

namespace Restate\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Runs PHP with the built Restate extension loaded, in a child process: the unit suite itself runs without the
 * extension (it tests the "extension not loaded" paths and drives handlers through fakes), and an extension cannot be
 * loaded into a running process.
 *
 * The extension comes from RESTATE_PHP_EXTENSION_PATH, default extension/target/debug/restate.so
 * (`composer build:extension`). A missing build fails the test; it does not skip it.
 */
final class ExtensionProcess
{
    public static function path(): string
    {
        $path = getenv('RESTATE_PHP_EXTENSION_PATH');
        $path = is_string($path) && $path !== '' ? $path : Repository::ROOT . '/extension/target/debug/restate.so';

        Assert::assertFileExists($path, 'Build the extension first: composer build:extension');

        return $path;
    }

    /**
     * Runs a PHP script file with the extension loaded and returns its standard output.
     *
     * @param list<string> $arguments
     */
    public static function runFile(string $script, array $arguments = [], bool $loadExtension = true): string
    {
        $command = [PHP_BINARY];
        if ($loadExtension) {
            $command[] = '-d';
            $command[] = 'extension=' . self::path();
        }

        return self::run([...$command, $script, ...$arguments]);
    }

    /**
     * Runs PHP code (without the opening tag) with the Composer autoloader and, unless $loadExtension is false, the
     * extension loaded, and returns what it prints, decoded as JSON.
     *
     * @param array<string, string> $replace placeholders replaced in the code before it runs
     */
    public static function runJson(string $code, array $replace = [], bool $loadExtension = true): mixed
    {
        $output = self::run([
            PHP_BINARY,
            ...($loadExtension ? ['-d', 'extension=' . self::path()] : []),
            '-r',
            'require ' . var_export(Repository::ROOT . '/vendor/autoload.php', true) . ";\n" . strtr($code, $replace),
        ]);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $command */
    private static function run(array $command): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        Assert::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        Assert::assertSame(0, $exitCode, "PHP child process failed:\n{$stderr}\n{$stdout}");

        return $stdout;
    }
}
