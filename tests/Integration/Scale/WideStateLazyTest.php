<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Scale;

use PHPUnit\Framework\Attributes\Group;
use Restate\Testing\RestateContainer;
use Restate\Testing\RestateProcess;

/**
 * Wide state, lazy: a Restate server of its own with eager state disabled (`RESTATE_WORKER__INVOKER__DISABLE_EAGER_STATE`,
 * the switch the conformance suite uses). stateKeys() and every get() ask Restate, one attempt each, so reading K keys
 * takes K + 2 attempts with stateKeys() and K + 1 without. clearAll() makes the state known: reads after it are local.
 */
#[Group('managed')]
final class WideStateLazyTest extends WideStateTestCase
{
    #[\Override]
    protected static function restateContainer(): RestateContainer|RestateProcess
    {
        return self::restateWith(['RESTATE_WORKER__INVOKER__DISABLE_EAGER_STATE' => 'true']);
    }

    #[\Override]
    protected static function mode(): string
    {
        return 'lazy';
    }

    #[\Override]
    protected static function readAttempts(int $keys, int $reads, bool $listsKeys): array
    {
        $attempts = $reads + 1 + ($listsKeys ? 1 : 0);

        return [$attempts, $attempts];
    }

    #[\Override]
    protected static function readAllClearedAttempts(): int
    {
        // The object's keys (none) come from Restate.
        return 2;
    }
}
