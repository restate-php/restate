<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Services\Payloads;

use Restate\JsonSchema\Attributes\JsonName;

/** A property DTO (no constructor) nested in {@see Order} and {@see Receipt}, with a field renamed on the wire. */
final class Address
{
    public string $street;

    #[JsonName('postal_code')]
    public string $postalCode;

    public ?string $unit = null;

    public static function of(string $street, string $postalCode, ?string $unit = null): self
    {
        $address = new self();
        $address->street = $street;
        $address->postalCode = $postalCode;
        $address->unit = $unit;

        return $address;
    }
}
