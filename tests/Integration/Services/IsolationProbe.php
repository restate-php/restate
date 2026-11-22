<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Services;

use Restate\Context;
use Restate\Tests\Integration\Support\WorkerProbe;

/**
 * A service class that says which instance of it ran a handler (WorkerIsolationTest): an id random per instance and the
 * number of instances of its class constructed before it in this worker script run. {@see InstanceBoundProbe} is
 * registered as an object, so every invocation on a worker shares one instance; {@see FactoryBoundProbe} is bound with
 * a factory, so every attempt gets a new one.
 */
abstract class IsolationProbe
{
    /** @var array<class-string, int> instances constructed per class in this worker script run */
    private static array $constructed = [];

    private readonly string $id;

    private readonly int $construction;

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(6));
        $this->construction = self::$constructed[static::class] = (self::$constructed[static::class] ?? 0) + 1;
    }

    /** @return array{id: string, construction: int, worker: ?string, pid: int} */
    protected function describe(Context $ctx): array
    {
        return ['id' => $this->id, 'construction' => $this->construction, 'worker' => WorkerProbe::current()?->instance, 'pid' => (int) getmypid()];
    }
}
