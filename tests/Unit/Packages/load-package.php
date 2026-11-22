<?php

declare(strict_types=1);

/*
 * Loads every type of one package in packages/ (the first argument) with nothing but what its composer.json requires
 * (PackagesLoadWithTheirRequirementsTest runs it with `php -n`): an autoloader in front of Composer's loads a class
 * only from the package itself, the restate/* packages it requires and the third-party packages they require (each
 * transitively, the third-party ones as installed); it refuses every other class and reports it.
 */

$root = dirname(__DIR__, 3);
$loader = require $root . '/vendor/autoload.php';
$package = $argv[1] ?? '';

/** @return array<string, mixed> */
$json = static function (string $file): array {
    $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    \assert(is_array($data));

    /** @var array<string, mixed> $data */
    return $data;
};

/** @var array<string, array{name: string, require?: array<string, string>, install-path: string}> $installed */
$installed = [];
$installedJson = $json($root . '/vendor/composer/installed.json');
\assert(is_array($installedJson['packages'] ?? null));
foreach ($installedJson['packages'] as $entry) {
    \assert(is_array($entry) && is_string($entry['name'] ?? null));
    /** @var array{name: string, require?: array<string, string>, install-path: string} $entry */
    $installed[$entry['name']] = $entry;
}

// The packages whose classes may load: restate/* ones by their src/, third-party ones by their install path.
$allowed = [];
$queue = ['restate/' . $package];
$seen = [];
while ($queue !== []) {
    $name = array_shift($queue);
    if (isset($seen[$name])) {
        continue;
    }
    $seen[$name] = true;

    if (str_starts_with($name, 'restate/')) {
        $directory = $root . '/packages/' . substr($name, strlen('restate/'));
        $requires = $json($directory . '/composer.json')['require'] ?? [];
        $allowed[] = $directory . '/src/';
    } elseif (isset($installed[$name])) {
        $requires = $installed[$name]['require'] ?? [];
        $allowed[] = realpath($root . '/vendor/composer/' . $installed[$name]['install-path']) . '/';
    } else {
        continue; // a virtual package (psr/http-client-implementation) or one a replace covers
    }

    \assert(is_array($requires));
    foreach (array_keys($requires) as $dependency) {
        if (str_contains((string) $dependency, '/')) {
            $queue[] = (string) $dependency;
        }
    }
}

/** @var array<string, string> $refused */
$refused = [];
spl_autoload_register(static function (string $class) use ($loader, $allowed, $root, &$refused): void {
    $file = $loader->findFile($class);
    $file = $file === false ? false : realpath($file);
    if ($file === false) {
        return;
    }
    foreach ($allowed as $directory) {
        if (str_starts_with($file, $directory)) {
            require $file;

            return;
        }
    }
    $refused[$class] = substr($file, strlen($root) + 1);
}, prepend: true);
$loader->unregister();

$autoload = $json("{$root}/packages/{$package}/composer.json")['autoload'] ?? [];
\assert(is_array($autoload) && is_array($autoload['psr-4'] ?? null));
$types = 0;
$loaded = 0;
$errors = [];
foreach ($autoload['psr-4'] as $namespace => $path) {
    $directory = "{$root}/packages/{$package}/" . $path;
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
        \assert($file instanceof \SplFileInfo);
        if ($file->getExtension() !== 'php') {
            continue;
        }
        ++$types;
        $type = $namespace . str_replace('/', '\\', substr($file->getPathname(), strlen($directory), -4));
        try {
            if (class_exists($type) || interface_exists($type) || trait_exists($type) || enum_exists($type)) {
                ++$loaded;
            }
        } catch (\Throwable $e) {
            $errors[$type] = $e->getMessage();
        }
    }
}

echo json_encode([
    'types' => $types,
    'loaded' => $loaded,
    'refused' => $refused,
    'errors' => $errors,
    'extensions' => get_loaded_extensions(),
], JSON_THROW_ON_ERROR);
