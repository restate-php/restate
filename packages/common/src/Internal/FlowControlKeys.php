<?php

declare(strict_types=1);

namespace Restate\Internal;

use InvalidArgumentException;

/**
 * The format Restate requires of a scope and a limit key (its flow control): a scope is 1 to 36 characters from
 * `[a-zA-Z0-9_.-]`; a limit key is one or two such levels separated by `/`. Restate rejects anything else, so the SDK
 * checks before an invocation is journaled or sent.
 *
 * @internal
 */
final class FlowControlKeys
{
    private const string LEVEL = '[a-zA-Z0-9_.-]{1,36}';

    public static function assertScope(string $scope): void
    {
        if (preg_match('/^' . self::LEVEL . '$/D', $scope) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid scope "%s": a scope is 1 to 36 characters from [a-zA-Z0-9_.-].',
                $scope,
            ));
        }
    }

    public static function assertLimitKey(string $limitKey): void
    {
        if (preg_match('/^' . self::LEVEL . '(\/' . self::LEVEL . ')?$/D', $limitKey) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid limit key "%s": a limit key is one or two levels separated by "/", each 1 to 36 characters from [a-zA-Z0-9_.-].',
                $limitKey,
            ));
        }
    }

    /** A limit key applies within a scope: Restate rejects one on an unscoped target. */
    public static function assertLimitKeyFor(?string $scope, ?string $limitKey): void
    {
        if ($limitKey === null) {
            return;
        }
        self::assertLimitKey($limitKey);
        if ($scope === null) {
            throw new InvalidArgumentException(sprintf(
                'The limit key "%s" needs a scoped target: a limit key applies within a scope (Target::scoped(), Context::scope()).',
                $limitKey,
            ));
        }
    }
}
