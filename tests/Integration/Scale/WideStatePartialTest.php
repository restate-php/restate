<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Scale;

use Restate\Testing\RestateContainer;
use Restate\Testing\RestateProcess;

/**
 * Wide state, partially eager: a Restate server of its own whose eager state size limit is 1 KiB
 * (`RESTATE_WORKER__INVOKER__EAGER_STATE_SIZE_LIMIT=1KiB`), so it sends only some keys of a larger state and the SDK
 * fetches the rest, and the key list, on first use. Which keys it sends is Restate's choice; the tests assume none.
 * An empty state fits, so it is sent whole.
 */
final class WideStatePartialTest extends WideStateTestCase
{
    #[\Override]
    protected static function restateContainer(): RestateContainer|RestateProcess
    {
        return self::restateWith(['RESTATE_WORKER__INVOKER__EAGER_STATE_SIZE_LIMIT' => '1KiB']);
    }

    #[\Override]
    protected static function mode(): string
    {
        return 'partial';
    }

    #[\Override]
    protected static function readAttempts(int $keys, int $reads, bool $listsKeys): array
    {
        // At least the key list or the missing key is fetched; at most every key is.
        return [2, $reads + 1 + ($listsKeys ? 1 : 0)];
    }

    #[\Override]
    protected static function readAllClearedAttempts(): int
    {
        return 1;
    }
}
