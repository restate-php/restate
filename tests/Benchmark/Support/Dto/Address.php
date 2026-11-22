<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark\Support\Dto;

/** The innermost level of {@see Order}: 4 fields. */
final readonly class Address
{
    public function __construct(
        public string $street,
        public string $city,
        public string $postalCode,
        public string $country,
    ) {
    }
}
