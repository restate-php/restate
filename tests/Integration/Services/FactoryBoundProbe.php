<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Services;

use Restate\Attributes as Restate;
use Restate\Context;

/** Bound with a factory (IntegrationEndpoints): a new instance for every invocation attempt. */
#[Restate\Service('FactoryBoundProbe')]
final class FactoryBoundProbe extends IsolationProbe
{
    /** @return array{id: string, construction: int, worker: ?string, pid: int} */
    #[Restate\Handler]
    public function which(Context $ctx): array
    {
        return $this->describe($ctx);
    }
}
