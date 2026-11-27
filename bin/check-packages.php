<?php

declare(strict_types=1);

// Checks the Composer packages in packages/ (docs/decisions/0029-package-split.md), for `just check-packages`:
//
// - each composer.json passes `composer validate --strict`;
// - each package's code uses exactly the packages and extensions its composer.json requires: no class, function or
//   constant of another restate/* package, a third-party package or an extension it does not require (or, for a
//   third-party package, suggest), and no requirement it does not use (shipmonk/composer-dependency-analyser, run per
//   package over its src/ and bin/);
// - the root composer.json matches: it replaces every package, autoloads every package's src/, and requires (or
//   requires for development) every third-party package and extension a package requires, with the same constraint, and
//   every third-party package a package suggests, so the one development install is what the packages declare;
// - the extension's PIE package, extension/composer.json, validates, installs the module `restate` and has the packages'
//   PHP constraint;
// - no two packages that share a namespace define the same type;
// - no package declares anything in `Restate\Extension\`, the extension's namespace (the extension's own classes and
//   functions, and those extension/restate.stub.php lists, must all be in it).
//
// Run it with the Restate extension loaded (`just check-packages` does), so that `Restate\Extension\` resolves to
// `ext-restate`.

use ShipMonk\ComposerDependencyAnalyser\Analyser;
use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;
use ShipMonk\ComposerDependencyAnalyser\Result\AnalysisResult;
use ShipMonk\ComposerDependencyAnalyser\Result\SymbolUsage;
use ShipMonk\ComposerDependencyAnalyser\Stopwatch;

$root = dirname(__DIR__);
$loader = require $root . '/vendor/autoload.php';

if (!extension_loaded('restate')) {
    fwrite(STDERR, "check-packages: load the Restate extension (php -d extension=extension/target/debug/restate.so).\n");
    exit(2);
}

/** @return array<string, mixed> */
$readJson = static function (string $file): array {
    $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new \UnexpectedValueException("{$file} is not a JSON object.");
    }

    /** @var array<string, mixed> $data */
    return $data;
};

/**
 * @param array<string, mixed> $json
 * @return array<string, string>
 */
$links = static function (array $json, string $key): array {
    $links = $json[$key] ?? [];
    \assert(is_array($links));

    /** @var array<string, string> $links */
    return $links;
};

/**
 * The classes, interfaces, traits, enums and functions a PHP file declares, fully qualified; methods, closures and
 * anonymous classes are not declarations.
 *
 * @return list<string>
 */
$declarations = static function (string $file): array {
    $tokens = array_values(array_filter(\PhpToken::tokenize((string) file_get_contents($file)), static fn (\PhpToken $token): bool => !$token->isIgnorable()));
    $namespace = '';
    $declared = [];
    $depth = 0;
    // The brace depth of top-level code: 1 inside `namespace X { … }`, otherwise 0. A function declared deeper is a
    // method (or declared inside a function body, which a package does not do).
    $topLevel = 0;
    foreach ($tokens as $i => $token) {
        $previous = $tokens[$i - 1] ?? null;
        $next = $tokens[$i + 1] ?? null;
        if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            ++$depth;
        } elseif ($token->is('}')) {
            --$depth;
        } elseif ($token->is(T_NAMESPACE) && $next !== null && $next->is([T_STRING, T_NAME_QUALIFIED, '{'])) {
            $namespace = $next->is('{') ? '' : $next->text . '\\';
            $topLevel = $depth + ($next->is('{') || ($tokens[$i + 2] ?? null)?->is('{') === true ? 1 : 0);
        } elseif ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && $next !== null && $next->is(T_STRING) && !($previous?->is([T_DOUBLE_COLON, T_NEW]) ?? false)) {
            $declared[] = $namespace . $next->text;
        } elseif ($token->is(T_FUNCTION) && $depth === $topLevel) {
            $name = $next !== null && $next->is('&') ? ($tokens[$i + 2] ?? null) : $next;
            if ($name !== null && $name->is(T_STRING)) {
                $declared[] = $namespace . $name->text;
            }
        }
    }

    return $declared;
};

/** The pseudo package name the analyser gives a file in packages/<directory>/ (see $classLoaders below). */
$local = static fn (string $package): string => 'packages/' . substr($package, strlen('restate/'));

$rootJson = $readJson($root . '/composer.json');
$rootRequires = [...$links($rootJson, 'require'), ...$links($rootJson, 'require-dev')];
$rootReplace = $links($rootJson, 'replace');
$rootAutoload = $rootJson['autoload'] ?? [];
\assert(is_array($rootAutoload));
$rootPsr4 = $rootAutoload['psr-4'] ?? [];
\assert(is_array($rootPsr4));

// The analyser names a dependency after the two path segments below a vendor directory. Composer's class loader finds
// restate/* classes in packages/<directory>/src/, so with the repository as a second "vendor directory" they belong to
// "packages/<directory>"; everything in vendor/ matches vendor/ first.
$classLoaders = [$root . '/vendor' => $loader, $root => new \Composer\Autoload\ClassLoader()];

$composer = getenv('COMPOSER_BINARY');
$composer = is_string($composer) && $composer !== '' ? [PHP_BINARY, $composer] : ['composer'];

$failures = 0;
$fail = static function (string $message) use (&$failures): void {
    ++$failures;
    echo "  {$message}\n";
};

$validate = static function (string $file) use ($composer, $fail): void {
    $process = proc_open([...$composer, 'validate', '--strict', '--no-check-lock', '--no-interaction', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    \assert(is_resource($process));
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        $fail("composer validate --strict failed:\n" . trim($output));
    }
};

$files = glob($root . '/packages/*/composer.json');
\assert(is_array($files));
foreach ($files as $file) {
    $directory = dirname($file);
    $json = $readJson($file);
    $name = $json['name'] ?? null;
    \assert(is_string($name));
    echo "{$name} (packages/" . basename($directory) . ")\n";

    $validate($file);

    // The package and the root composer.json.
    if ($name !== 'restate/' . basename($directory)) {
        $fail("The package in packages/" . basename($directory) . " must be named restate/" . basename($directory) . '.');
    }
    if (($rootReplace[$name] ?? null) !== 'self.version') {
        $fail("The root composer.json must replace {$name} with self.version.");
    }
    $requires = $links($json, 'require');
    foreach ($requires as $dependency => $constraint) {
        if (str_starts_with($dependency, 'restate/')) {
            if ($constraint !== 'self.version') {
                $fail("{$dependency} must be required with self.version (all packages share one version), not {$constraint}.");
            }
        } elseif ($dependency !== 'ext-restate' && ($rootRequires[$dependency] ?? null) !== $constraint) {
            $fail("The root composer.json must require {$dependency} {$constraint}, as {$name} does (it has " . ($rootRequires[$dependency] ?? 'nothing') . ').');
        }
    }
    $suggests = array_keys($links($json, 'suggest'));
    foreach ($suggests as $suggested) {
        if (!str_starts_with($suggested, 'restate/') && !str_starts_with($suggested, 'ext-') && !isset($rootRequires[$suggested])) {
            $fail("The root composer.json must require {$suggested} (for development), which {$name} suggests, so the code using it is analysed and tested.");
        }
    }
    $autoload = $json['autoload'] ?? [];
    \assert(is_array($autoload));
    $psr4 = $autoload['psr-4'] ?? [];
    \assert(is_array($psr4));
    foreach ($psr4 as $prefix => $path) {
        \assert(is_string($path));
        $expected = 'packages/' . basename($directory) . '/' . $path;
        if (!in_array($expected, (array) ($rootPsr4[$prefix] ?? []), true)) {
            $fail("The root composer.json must autoload {$prefix} from {$expected}.");
        }
    }

    // What the code uses.
    $configuration = new Configuration()
        ->disableComposerAutoloadPathScan()
        ->addPathToScan($directory . '/src', isDev: false);
    $bin = $json['bin'] ?? [];
    \assert(is_array($bin));
    foreach ($bin as $script) {
        \assert(is_string($script));
        $configuration->addPathToScan($directory . '/' . $script, isDev: false);
    }
    foreach ($suggests as $suggested) {
        // An optional dependency: the code that uses it only runs when the user installs it.
        if (str_starts_with($suggested, 'ext-')) {
            $configuration->ignoreErrorsOnExtension($suggested, [ErrorType::SHADOW_DEPENDENCY]);
        } elseif (!str_starts_with($suggested, 'restate/')) {
            $configuration->ignoreErrorsOnPackage($suggested, [ErrorType::SHADOW_DEPENDENCY]);
        }
    }

    $dependencies = [$local($name) => false];
    foreach (array_keys($requires) as $dependency) {
        if ($dependency !== 'php') {
            $dependencies[str_starts_with($dependency, 'restate/') ? $local($dependency) : $dependency] = false;
        }
    }

    $result = new Analyser(new Stopwatch(), $root . '/vendor', $classLoaders, $configuration, $dependencies)->run();
    $before = $failures;
    /** @param array<string, array<string, list<SymbolUsage>>> $errors what => symbol (or '') => where it is used */
    $report = static function (string $what, array $errors) use ($fail, $root): void {
        foreach ($errors as $dependency => $symbols) {
            $where = [];
            foreach ($symbols as $symbol => $usages) {
                $usage = $usages[0];
                $where[] = ($symbol !== '' ? "{$symbol} in " : '') . substr($usage->getFilepath(), strlen($root) + 1) . ':' . $usage->getLineNumber();
            }
            $dependency = str_starts_with($dependency, 'packages/') ? 'restate/' . substr($dependency, strlen('packages/')) : $dependency;
            $fail("{$what} {$dependency}: " . implode(', ', array_slice($where, 0, 3)) . (count($where) > 3 ? ', …' : ''));
        }
    };
    $report('Uses, but does not require,', $result->getShadowDependencyErrors());
    $report('Uses an unknown class', array_map(static fn (array $usages): array => ['' => $usages], $result->getUnknownClassErrors()));
    $report('Uses an unknown function', array_map(static fn (array $usages): array => ['' => $usages], $result->getUnknownFunctionErrors()));
    foreach ($result->getUnusedDependencyErrors() as $unused) {
        $unused = str_starts_with($unused, 'packages/') ? 'restate/' . substr($unused, strlen('packages/')) : $unused;
        $fail("Requires, but does not use, {$unused}.");
    }

    if ($failures === $before) {
        echo "  ok: {$result->getScannedFilesCount()} files use exactly what composer.json requires\n";
    }
}

// The extension's PIE package (docs/decisions/0030-prebuilt-extension-binaries.md): extension/composer.json, the root of the
// split extension repository. PIE installs the module named by php-ext.extension-name, so that must be the module
// `restate/sdk` requires as ext-restate; it needs no code of its own, only the PHP versions every package supports.
$extensionFile = $root . '/extension/composer.json';
$extensionJson = $readJson($extensionFile);
echo "restate/ext-restate (extension)\n";
$before = $failures;
$validate($extensionFile);
$phpExt = $extensionJson['php-ext'] ?? [];
\assert(is_array($phpExt));
if (($extensionJson['name'] ?? null) !== 'restate/ext-restate' || ($extensionJson['type'] ?? null) !== 'php-ext') {
    $fail('extension/composer.json must be the php-ext package restate/ext-restate.');
}
if (($phpExt['extension-name'] ?? null) !== new \ReflectionExtension('restate')->getName()) {
    $fail('php-ext.extension-name must be restate, the name the extension registers.');
}
if ($links($extensionJson, 'require') !== ['php' => $links($rootJson, 'require')['php'] ?? null]) {
    $fail('extension/composer.json must require php only, with the constraint of the root composer.json and every package.');
}
if (isset($rootReplace['restate/ext-restate'])) {
    $fail('The root composer.json must not replace restate/ext-restate: the root never requires the extension.');
}
// The packages and the extension are released together at the extension's crate version (docs/decisions/0031-release.md),
// and restate/sdk requires the extension of its own minor version.
$crate = (string) file_get_contents($root . '/extension/Cargo.toml');
if (preg_match('/^\[workspace\.package\]$(?:\n(?!\[).*)*?\nversion = "(\d+)\.(\d+)\.\d+"$/m', $crate, $crateVersion) !== 1) {
    $fail('extension/Cargo.toml has no [workspace.package] version X.Y.Z.');
} else {
    $sdkConstraint = $links($readJson($root . '/packages/sdk/composer.json'), 'require')['ext-restate'] ?? null;
    $wantConstraint = "~{$crateVersion[1]}.{$crateVersion[2]}.0";
    if ($sdkConstraint !== $wantConstraint) {
        $fail("restate/sdk must require ext-restate {$wantConstraint}, the minor version of the extension crate, not " . var_export($sdkConstraint, true) . '.');
    }
}
if ($failures === $before) {
    echo "  ok\n";
}

foreach (array_keys($rootReplace) as $replaced) {
    if (!is_file($root . '/packages/' . substr($replaced, strlen('restate/')) . '/composer.json')) {
        echo "The root composer.json replaces {$replaced}, which is not in packages/.\n";
        ++$failures;
    }
}

// Packages that share a namespace (restate/common and restate/sdk both map `Restate\`) must not both define a type.
foreach ($rootPsr4 as $prefix => $paths) {
    $owners = [];
    foreach ((array) $paths as $path) {
        \assert(is_string($path));
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $path, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $type = $prefix . str_replace('/', '\\', substr($file->getPathname(), strlen($root . '/' . $path), -4));
            if (isset($owners[$type])) {
                echo "{$type} is defined in both {$owners[$type]} and {$path}.\n";
                ++$failures;
            }
            $owners[$type] = $path;
        }
    }
}

// `Restate\Extension\` belongs to the extension, which defines its classes and functions when PHP starts
// (extension/restate.stub.php describes them): a package type there would at best be dead code and at worst a fatal
// "cannot redeclare" in every process that loads the extension. Checked on the declarations themselves, not the file
// paths, and without regard to case, as PHP resolves class and function names.
$reservedNamespace = 'Restate\\Extension\\';
$isReserved = static fn (string $name): bool => strncasecmp($name, $reservedNamespace, strlen($reservedNamespace)) === 0;
$extension = new \ReflectionExtension('restate');
$extensionDefines = [];
foreach ([...$declarations($root . '/extension/restate.stub.php'), ...$extension->getClassNames(), ...array_keys($extension->getFunctions())] as $defined) {
    $extensionDefines[strtolower($defined)] = $defined;
    if (!$isReserved($defined)) {
        echo "The extension (or extension/restate.stub.php) defines {$defined}, outside {$reservedNamespace}.\n";
        ++$failures;
    }
}
$packageFiles = glob($root . '/packages/*/composer.json');
\assert(is_array($packageFiles));
foreach ($packageFiles as $file) {
    $directory = dirname($file);
    $sources = [];
    $bin = $readJson($file)['bin'] ?? [];
    \assert(is_array($bin));
    foreach ($bin as $script) {
        \assert(is_string($script));
        $sources[] = $directory . '/' . $script;
    }
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory . '/src', \FilesystemIterator::SKIP_DOTS)) as $source) {
        \assert($source instanceof \SplFileInfo);
        if ($source->getExtension() === 'php') {
            $sources[] = $source->getPathname();
        }
    }
    foreach ($sources as $source) {
        foreach ($declarations($source) as $declared) {
            if ($isReserved($declared)) {
                $what = isset($extensionDefines[strtolower($declared)]) ? 'redeclares' : 'declares';
                echo substr($source, strlen($root) + 1) . " {$what} {$declared}: {$reservedNamespace} is reserved for the extension.\n";
                ++$failures;
            }
        }
    }
}

if ($failures > 0) {
    echo "\n{$failures} problem(s) in the packages; see docs/decisions/0029-package-split.md.\n";
    exit(1);
}
