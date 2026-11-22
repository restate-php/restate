<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\IntegrationTestCase;
use Restate\Tests\Support\Workload\HttpDriver;
use Restate\Tests\Support\Workload\Workload;

/**
 * The workload library's HTTP driver against the integration endpoint as it is served (php -S, or FrankenPHP): every
 * request class, sent as Restate sends it (chunked, no Content-Length) straight to the endpoint, gets its expected
 * frames. Soak runs replay these bodies by the million.
 */
final class WorkloadTest extends IntegrationTestCase
{
    public function testEveryRequestClassGetsItsExpectedResponseOverHttp(): void
    {
        $driver = new HttpDriver(self::restate()->endpointUrl());

        foreach (Workload::classes() as $class => $request) {
            $request = $request('inv_1workload' . bin2hex(random_bytes(8)));
            $response = $driver->send($request);
            self::assertTrue($response->isExpectedFor($request), "{$class}: {$response->describe()}");
        }
    }
}
