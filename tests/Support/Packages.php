<?php

declare(strict_types=1);

namespace Restate\Tests\Support;

/** The Composer packages of this repository (packages/<name>), for tests that check what each one loads. */
final class Packages
{
    /** Each package: its directory under packages/ and the namespace its src/ maps to (PSR-4). */
    public const array NAMESPACES = [
        'common' => 'Restate\\',
        'sdk' => 'Restate\\',
        'ingress' => 'Restate\\Ingress\\',
        'testing' => 'Restate\\Testing\\',
    ];

    private function __construct()
    {
    }

    /** The package whose src/ defines a type, or null for a type defined elsewhere (a test, a dependency) or nowhere. */
    public static function of(string $type): ?string
    {
        if (!class_exists($type) && !interface_exists($type) && !trait_exists($type)) {
            return null;
        }

        $file = new \ReflectionClass($type)->getFileName();
        $root = (string) realpath(Repository::ROOT) . '/packages/';
        if ($file === false || !str_starts_with($file, $root)) {
            return null;
        }

        $package = strstr(substr($file, strlen($root)), '/', true);

        return $package !== false && str_starts_with(substr($file, strlen($root . $package)), '/src/') ? $package : null;
    }

    /**
     * The name of every type a package's src/ defines, one per file (PSR-4).
     *
     * @param key-of<self::NAMESPACES> $package
     *
     * @return list<string>
     */
    public static function types(string $package): array
    {
        $directory = Repository::ROOT . "/packages/{$package}/src/";
        $types = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->getExtension() === 'php') {
                $types[] = self::NAMESPACES[$package] . str_replace('/', '\\', substr($file->getPathname(), strlen($directory), -4));
            }
        }
        sort($types);

        return $types;
    }
}
