<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Services\Payloads;

use Restate\JsonSchema\Attributes\JsonName;

/** A constructor DTO handler input: a nested DTO, a backed enum with a default, nullable and renamed fields. */
final readonly class Order
{
    public function __construct(
        public string $id,
        public int $quantity,
        public Address $shipTo,
        #[JsonName('weight_kg')]
        public float $weightKg,
        public bool $fragile = false,
        public Priority $priority = Priority::Normal,
        public ?string $giftMessage = null,
    ) {
    }
}
