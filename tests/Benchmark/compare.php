<?php

declare(strict_types=1);

/*
 * Compares the phpbench runs (and callgrind counts) bin/bench-compare.sh made of a base and a head revision; see
 * Restate\Tests\Benchmark\Tooling\BenchComparison. Prints a Markdown report, appends it to --summary (the GitHub job
 * summary) and writes --json; exits 1 when something regressed.
 *
 *   php tests/Benchmark/compare.php base-1.xml head-1.xml head-2.xml base-2.xml \
 *       [--callgrind-base=base.tsv --callgrind-head=head.tsv] [--summary=file] [--json=file] \
 *       [--threshold=20] [--rstdev=10] [--memory-threshold=10] [--callgrind-threshold=3]
 *
 * A callgrind file has one `<subject>\t<instructions per revolution>` line per subject.
 */

use Restate\Tests\Benchmark\Tooling\BenchComparison;
use Restate\Tests\Benchmark\Tooling\PhpbenchDump;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!isset($argv)) {
    fwrite(STDERR, "compare.php: run it from the command line.\n");
    exit(64);
}

$files = [];
$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $match) === 1) {
        $options[$match[1]] = $match[2];
    } else {
        $files[] = $argument;
    }
}
if (count($files) !== 4) {
    fwrite(STDERR, "Usage: php tests/Benchmark/compare.php <base-1.xml> <head-1.xml> <head-2.xml> <base-2.xml> [--option=value…]\n");
    exit(2);
}

/** @return array<string, float> */
$instructions = static function (?string $file): array {
    if ($file === null || !is_file($file)) {
        return [];
    }
    $counts = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines === false ? [] : $lines as $line) {
        [$subject, $count] = explode("\t", $line) + [1 => ''];
        if (is_numeric($count)) {
            $counts[$subject] = (float) $count;
        }
    }

    return $counts;
};

$comparison = BenchComparison::compare(
    PhpbenchDump::read($files[0]),
    PhpbenchDump::read($files[1]),
    PhpbenchDump::read($files[2]),
    PhpbenchDump::read($files[3]),
    $instructions($options['callgrind-base'] ?? null),
    $instructions($options['callgrind-head'] ?? null),
    (float) ($options['threshold'] ?? 20),
    (float) ($options['rstdev'] ?? 10),
    (float) ($options['memory-threshold'] ?? 10),
    (float) ($options['callgrind-threshold'] ?? 3),
);

$markdown = $comparison->markdown();
echo $markdown;
if (isset($options['summary']) && $options['summary'] !== '') {
    file_put_contents($options['summary'], $markdown, FILE_APPEND);
}
if (isset($options['json']) && $options['json'] !== '') {
    file_put_contents($options['json'], json_encode($comparison->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}

$regressions = $comparison->regressions();
if ($regressions !== []) {
    fwrite(STDERR, 'Regressed: ' . implode(', ', $regressions) . "\n");
    exit(1);
}
