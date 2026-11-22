<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark\Support\Dto;

/**
 * The DTO the mapper and schema benchmarks hydrate, normalize and describe: three levels (Order, {@see Customer},
 * {@see Address}) and 20 scalar fields, a shape the built-in mapper, Symfony's serializer and Valinor all map. (The
 * built-in mapper maps no lists of DTOs, so there is none.)
 */
final readonly class Order
{
    public function __construct(
        public string $id,
        public string $currency,
        public float $total,
        public int $quantity,
        public string $status,
        public ?string $note,
        public string $createdAt,
        public bool $paid,
        public int $priority,
        public string $channel,
        public string $reference,
        public Customer $customer,
    ) {
    }

    public static function example(): self
    {
        return new self(
            'order-1',
            'EUR',
            129.95,
            3,
            'placed',
            null,
            '2026-11-19T10:00:00Z',
            true,
            2,
            'web',
            'ref-0001',
            new Customer(42, 'Mary', 'mary@example.com', '+36 1 234 5678', false, new Address('Main street 1', 'Budapest', '1011', 'HU')),
        );
    }

    /** {@see example()} as the JSON a caller sends. */
    public static function exampleJson(): string
    {
        return json_encode(self::example(), JSON_THROW_ON_ERROR);
    }
}
