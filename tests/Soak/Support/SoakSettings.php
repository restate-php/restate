<?php

declare(strict_types=1);

namespace Restate\Tests\Soak\Support;

use Restate\Tests\Support\Repository;

/**
 * How big a soak run is and what it runs against, from the environment (bin/test-soak.sh sets them):
 *
 * - RESTATE_PHP_SOAK_QUICK=1                 a short local run: a few percent of the requests; the RSS slope is
 *                                            reported, not gated (too noisy that short).
 * - RESTATE_PHP_SOAK_RELEASE_IMAGE           worker image with the release diagnostics build (frankenphp-worker-release).
 * - RESTATE_PHP_SOAK_DEBUG_IMAGE             worker image with the debug diagnostics build (frankenphp-worker-debug).
 * - RESTATE_PHP_SOAK_INVOCATIONS             invocations of the end-to-end soak through Restate (default 300,000;
 *                                            quick 3,000).
 * - RESTATE_PHP_SOAK_REPORT_DIR              where runs write their samples and summaries (default var/soak).
 */
final class SoakSettings
{
    public const string DEFAULT_RELEASE_IMAGE = 'restate.local/restate-php-frankenphp:worker-release';

    public const string DEFAULT_DEBUG_IMAGE = 'restate.local/restate-php-frankenphp:worker-debug';

    private function __construct()
    {
    }

    public static function quick(): bool
    {
        return self::env('RESTATE_PHP_SOAK_QUICK') === '1';
    }

    /** `$full` requests in a full run, `$quick` in a quick one. */
    public static function count(int $full, int $quick): int
    {
        return self::quick() ? $quick : $full;
    }

    public static function releaseImage(): string
    {
        return self::env('RESTATE_PHP_SOAK_RELEASE_IMAGE') ?? self::DEFAULT_RELEASE_IMAGE;
    }

    public static function debugImage(): string
    {
        return self::env('RESTATE_PHP_SOAK_DEBUG_IMAGE') ?? self::DEFAULT_DEBUG_IMAGE;
    }

    public static function invocations(): int
    {
        $value = self::env('RESTATE_PHP_SOAK_INVOCATIONS');

        return $value !== null ? max(1, (int) $value) : self::count(300_000, 3_000);
    }

    /** A fresh directory for one run's report files. */
    public static function reportDir(string $run): string
    {
        $directory = (self::env('RESTATE_PHP_SOAK_REPORT_DIR') ?? Repository::ROOT . '/var/soak') . '/' . $run . '-' . gmdate('Ymd\THis\Z');
        if (!is_dir($directory) && !mkdir($directory, 0o777, true)) {
            throw new \RuntimeException("Could not create {$directory}.");
        }

        return $directory;
    }

    /** Prints a report line (PHPUnit shows stderr) and appends it to the run's summary file. */
    public static function report(string $directory, string $text): void
    {
        fwrite(STDERR, $text . "\n");
        file_put_contents($directory . '/summary.txt', $text . "\n", FILE_APPEND);
    }

    /**
     * A worker thread count the runner can afford: `$wanted`, at most the CPUs.
     *
     * @return int<1, max>
     */
    public static function threads(int $wanted): int
    {
        $cpus = (int) shell_exec('nproc 2>/dev/null');

        return max(1, min($wanted, $cpus > 0 ? $cpus : $wanted));
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
