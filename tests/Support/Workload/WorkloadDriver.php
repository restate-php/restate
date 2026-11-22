<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Workload;

/** Sends {@see WorkloadRequest}s to an endpoint: in process ({@see InProcessDriver}) or over HTTP ({@see HttpDriver}). */
interface WorkloadDriver
{
    public function send(WorkloadRequest $request): WorkloadResponse;
}
