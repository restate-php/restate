<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/bin',
        __DIR__ . '/examples',
        __DIR__ . '/extension/tests',
        __DIR__ . '/packages',
        __DIR__ . '/test-services',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true)
    ->append([__DIR__ . '/extension/restate.stub.php']);

return (new PhpCsFixer\Config())
    // PHP CS Fixer refuses to run on a PHP newer than the newest syntax it knows (8.5 in 3.95). The code must parse
    // on PHP 8.4, the oldest supported minor, so it has no newer syntax for the fixer to misread: composer check runs
    // on every supported minor (8.6 included) instead of failing there.
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRiskyAllowed(false)
    ->setRules([
        '@PHP8x4Migration' => true,
        '@PSR12' => true,
    ])
    ->setFinder($finder);
