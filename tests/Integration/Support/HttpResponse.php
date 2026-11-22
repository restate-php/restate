<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Support;

final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
