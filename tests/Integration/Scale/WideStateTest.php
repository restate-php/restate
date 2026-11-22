<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Scale;

/**
 * Wide state, eager (Restate's default): every key comes with the invocation, so every read is local and each
 * handler takes one attempt, however many keys it reads. On the shared server.
 */
final class WideStateTest extends WideStateTestCase
{
    #[\Override]
    protected static function mode(): string
    {
        return 'eager';
    }

    #[\Override]
    protected static function prKeys(): int
    {
        return 2000;
    }

    #[\Override]
    protected static function readAttempts(int $keys, int $reads, bool $listsKeys): array
    {
        return [1, 1];
    }

    #[\Override]
    protected static function readAllClearedAttempts(): int
    {
        return 1;
    }
}
