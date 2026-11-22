<?php

declare(strict_types=1);

/*
 * Entry point of the load tests; bin/load-test.sh builds the endpoint image and calls it in the dev shell.
 *
 *   php tests/Load/run.php <scenario|all> [--quick] [--repetitions N] [--image TAG] [--cpus N]
 *                          [--cpuset-endpoint LIST] [--cpuset-restate LIST] [--partitions N]
 *                          [--results-dir DIR] [--baselines-dir DIR] [--gate]
 *   php tests/Load/run.php --list
 *
 * Writes one result JSON per scenario to <results dir>/<scenario>/<UTC timestamp>-<commit>.json, prints a Markdown
 * summary against the rolling median of the last 5 comparable results and, when the file exists, the pinned baseline
 * <baselines dir>/<scenario>.json (default tests/Load/baselines/, where none is committed), and appends it to
 * $GITHUB_STEP_SUMMARY when set. Exit status: 1 on a hard
 * failure (a 401, unexpected attempts, failed calls), 3 on a CPU-per-invocation regression with --gate, else 0.
 */

use Restate\Tests\Load\LoadHistory;
use Restate\Tests\Load\LoadRunner;
use Restate\Tests\Load\LoadScenario;
use Restate\Tests\Support\Repository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * @param list<string> $arguments
 *
 * @return array<string, string|bool|null>
 */
function loadOptions(array $arguments): array
{
    $options = ['scenario' => null, 'quick' => false, 'repetitions' => null, 'image' => null, 'cpus' => null, 'cpuset-endpoint' => null, 'cpuset-restate' => null, 'partitions' => '4', 'results-dir' => null, 'baselines-dir' => null, 'gate' => false, 'list' => false];
    while ($arguments !== []) {
        $argument = array_shift($arguments);
        if ($argument === '--quick' || $argument === '--gate' || $argument === '--list') {
            $options[substr($argument, 2)] = true;
        } elseif (str_starts_with($argument, '--') && array_key_exists(substr($argument, 2), $options)) {
            $value = array_shift($arguments);
            if ($value === null) {
                throw new InvalidArgumentException("{$argument} needs a value.");
            }
            $options[substr($argument, 2)] = $value;
        } elseif (!str_starts_with($argument, '--') && $options['scenario'] === null) {
            $options['scenario'] = $argument;
        } else {
            throw new InvalidArgumentException("Unknown argument {$argument}.");
        }
    }

    return $options;
}

/** @return array<string, string> */
function loadHost(): array
{
    $cpuinfo = (string) @file_get_contents('/proc/cpuinfo');
    $model = preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $match) === 1 ? trim($match[1]) : php_uname('m');
    $cpus = (string) max(1, (int) preg_match_all('/^processor\s*:/m', $cpuinfo));
    $hostname = (string) gethostname();

    return [
        'cpu' => $model,
        'cpus' => $cpus,
        'kernel' => php_uname('r'),
        // What decides comparability: the same machine (by name and CPU), never across hosts.
        'fingerprint' => hash('sha256', "{$hostname}|{$model}|{$cpus}"),
    ];
}

function loadEnv(string $name, string $default): string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
}

function loadCommit(): string
{
    $commit = trim((string) shell_exec('git -C ' . escapeshellarg(Repository::ROOT) . ' rev-parse --short=12 HEAD 2>/dev/null'));

    return $commit === '' ? 'unknown' : $commit;
}

if (!isset($argv)) {
    fwrite(STDERR, "run.php: run it from the command line.\n");
    exit(64);
}

try {
    $options = loadOptions(array_slice($argv, 1));
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\nUsage: php tests/Load/run.php <scenario|all> [--quick] [--repetitions N] [--image TAG] [--gate] …\n");
    exit(2);
}

if ($options['list'] === true) {
    foreach (LoadScenario::all() as $name => $scenario) {
        echo "{$name}\t{$scenario->description}\n";
    }
    exit(0);
}

$names = $options['scenario'] === 'all' ? array_keys(LoadScenario::all()) : [(string) $options['scenario']];
$quick = $options['quick'] === true;
$image = is_string($options['image']) ? $options['image'] : loadEnv('LOAD_ENDPOINT_IMAGE', 'restate.local/restate-php-frankenphp:extension-zts-release');
$root = realpath(Repository::ROOT);
$root = $root === false ? Repository::ROOT : $root;
$resultsDir = is_string($options['results-dir']) ? $options['results-dir'] : loadEnv('LOAD_RESULTS_DIR', $root . '/var/load/results');
$baselinesDir = is_string($options['baselines-dir']) ? $options['baselines-dir'] : __DIR__ . '/baselines';
$repetitions = is_string($options['repetitions']) ? max(1, (int) $options['repetitions']) : ($quick ? 1 : 3);
$host = loadHost();
$commit = loadCommit();

$status = 0;
foreach ($names as $name) {
    $scenario = LoadScenario::named($name);
    $stamp = gmdate('Ymd\THis\Z');
    $workDir = $root . "/var/load/work/{$name}-{$stamp}";
    $runner = new LoadRunner(
        $scenario,
        $quick,
        $repetitions,
        $image,
        $workDir,
        cpus: is_string($options['cpus']) ? $options['cpus'] : null,
        cpusetEndpoint: is_string($options['cpuset-endpoint']) ? $options['cpuset-endpoint'] : null,
        cpusetRestate: is_string($options['cpuset-restate']) ? $options['cpuset-restate'] : null,
        partitions: (int) $options['partitions'],
    );

    $result = ['commit' => $commit, 'date' => gmdate('c'), 'host' => $host, 'php' => PHP_VERSION, ...$runner->run()];
    $directory = "{$resultsDir}/{$name}";
    if (!is_dir($directory) && !mkdir($directory, 0o777, true)) {
        throw new RuntimeException("Could not create {$directory}.");
    }
    $file = "{$directory}/{$stamp}-{$commit}.json";
    file_put_contents($file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

    $failures = LoadHistory::failures($result);
    $comparison = LoadHistory::compare($result, LoadHistory::load($directory, except: $file), LoadHistory::read("{$baselinesDir}/{$name}.json"));
    $summary = LoadHistory::markdown($result, $comparison, $failures) . "\nResult: `{$file}`\n";
    echo $summary, "\n";
    $githubSummary = getenv('GITHUB_STEP_SUMMARY');
    if (is_string($githubSummary) && $githubSummary !== '') {
        file_put_contents($githubSummary, $summary . "\n", FILE_APPEND);
    }

    if ($failures !== []) {
        $status = 1;
    } elseif ($options['gate'] === true && $comparison['regressions'] !== [] && $status === 0) {
        $status = 3;
    }
}

exit($status);
