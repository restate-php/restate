<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Services\Payloads;

/** A constructor DTO handler output, built from an {@see Order}. */
final readonly class Receipt
{
    public function __construct(
        public string $orderId,
        public int $itemCount,
        public Priority $priority,
        public Address $deliverTo,
        public float $totalWeightKg,
        public bool $fragile,
        public ?string $giftMessage,
        public ?string $approvedBy = null,
    ) {
    }

    public static function for(Order $order, ?string $approvedBy = null): self
    {
        return new self(
            $order->id,
            $order->quantity,
            $order->priority,
            $order->shipTo,
            $order->weightKg * $order->quantity,
            $order->fragile,
            $order->giftMessage,
            $approvedBy,
        );
    }
}
