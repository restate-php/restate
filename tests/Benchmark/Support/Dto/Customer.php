<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark\Support\Dto;

/** The middle level of {@see Order}: 5 fields and an {@see Address}. */
final readonly class Customer
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?string $phone,
        public bool $vip,
        public Address $address,
    ) {
    }
}
