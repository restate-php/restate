<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Services;

use Restate\Attributes as Restate;
use Restate\Context;

/** Registered as an object (worker_isolation.php): one instance for every invocation the endpoint serves. */
#[Restate\Service('InstanceBoundProbe')]
final class InstanceBoundProbe extends IsolationProbe
{
    /** @return array{id: string, construction: int, worker: ?string, pid: int} */
    #[Restate\Handler]
    public function which(Context $ctx): array
    {
        return $this->describe($ctx);
    }
}
