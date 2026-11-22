<?php

declare(strict_types=1);

use Restate\Reflection\ServiceReflector;
use Restate\Tests\Integration\Services\Payloads\PayloadCheckout;
use Restate\Tests\Integration\Services\Payloads\Payloads;

// PayloadMappingTest: attributed services whose handlers take and return DTOs, backed enums, binary and custom payloads.

return [
    ServiceReflector::fromObject(new Payloads()),
    ServiceReflector::fromObject(new PayloadCheckout()),
];
