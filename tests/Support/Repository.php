<?php

declare(strict_types=1);

namespace Restate\Tests\Support;

/** Where this repository's files are, for tests that start processes or read files outside their own directory. */
final class Repository
{
    /** The repository root: the directory holding the root composer.json, vendor/, extension/ and packages/. */
    public const string ROOT = __DIR__ . '/../..';

    private function __construct()
    {
    }
}
