<?php

declare(strict_types=1);

namespace App;

use Restate\Attributes as Restate;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;

/**
 * A Virtual Object: handlers addressed by a key (here, a counter name), each key with its own durable state.
 */
#[Restate\VirtualObject('Counter')]
final class Counter
{
    /**
     * Exclusive (the default): Restate runs one exclusive invocation per key at a time, so this read-modify-write
     * needs no locks.
     */
    #[Restate\Handler]
    public function add(ObjectContext $ctx, int $amount): int
    {
        $count = ($ctx->get('count') ?? 0) + $amount;
        $ctx->set('count', $count);

        return $count;
    }

    /**
     * Shared: reads state and runs concurrently with other invocations of the same key.
     */
    #[Restate\Handler]
    #[Restate\Shared]
    public function get(ObjectSharedContext $ctx): int
    {
        return $ctx->get('count') ?? 0;
    }
}
