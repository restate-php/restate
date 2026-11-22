<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Common\Fixtures;

/** A DTO handler input: hydrated by the endpoint, normalized by the caller. */
final readonly class Order
{
    public function __construct(
        public string $id,
        public int $quantity,
        public Priority $priority = Priority::Normal,
    ) {
    }
}
