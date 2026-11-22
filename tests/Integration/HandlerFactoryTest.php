<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\IntegrationTestCase;

/**
 * A service class bound with a factory (tests/Integration/Support/endpoint.php, `TenantGreeter`): instantiated per
 * invocation attempt from the invocation's request.
 */
final class HandlerFactoryTest extends IntegrationTestCase
{
    public function testFactoryBuiltServiceGetsAnInstancePerAttemptScopedToTheRequest(): void
    {
        $acme = self::greet('Mary', 'acme');
        $globex = self::greet('Bob', 'globex');

        self::assertSame(['acme', 'Hello Mary'], [$acme['tenant'], $acme['greeting']]);
        self::assertSame(['globex', 'Hello Bob'], [$globex['tenant'], $globex['greeting']]);
        self::assertNotSame($acme['runInstance'], $acme['returnInstance'], 'The attempt that resumed after the run must have its own instance.');
        self::assertNotSame($acme['returnInstance'], $globex['returnInstance']);
    }

    /** @return array<string, string> */
    private static function greet(string $name, string $tenant): array
    {
        $result = json_decode(self::invoke('TenantGreeter/greet', json_encode($name, JSON_THROW_ON_ERROR), ['x-tenant' => $tenant], timeout: 30.0), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return array_map(strval(...), $result);
    }
}
