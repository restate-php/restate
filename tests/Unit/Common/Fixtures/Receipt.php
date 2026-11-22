<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Common\Fixtures;

/** A DTO handler output: normalized by the endpoint, hydrated by the caller. */
final readonly class Receipt
{
    public function __construct(
        public string $orderId,
        public int $itemCount,
        public Priority $priority,
        public ?string $approvedBy = null,
    ) {
    }
}
