<?php

declare(strict_types=1);

use Restate\Context;
use Restate\Reflection\ServiceReflector;
use Restate\Service;
use Restate\Tests\Integration\Services\InstanceBoundProbe;
use Restate\Tests\Integration\Support\WorkerProbe;

/*
 * Probes of what one invocation can see of another in a long-lived worker (WorkerIsolationTest), where this file runs
 * once per worker script run instead of once per request:
 *
 * - WorkerIsolation/stash: keeps the handler's context in a variable this file's closures share, so it outlives the
 *   invocation. Returns the worker instance.
 * - WorkerIsolation/useStale: uses the stashed context of an earlier invocation for a run, and returns what that threw
 *   (class and message) instead of throwing it, so the probe itself completes.
 * - WorkerIsolation/log {marker}: logs a record carrying the marker through the handler's logger; returns the
 *   invocation id the handler saw.
 * - InstanceBoundProbe/which (here, as an object) and FactoryBoundProbe/which (bound with a factory in
 *   IntegrationEndpoints): which instance ran the handler.
 */

/** @var ?Context $stashed */
$stashed = null;

return [
    Service::new('WorkerIsolation')
        ->handler('stash', static function (Context $ctx) use (&$stashed): ?string {
            $stashed = $ctx;

            return WorkerProbe::current()?->instance;
        })
        ->handler('useStale', static function (Context $ctx) use (&$stashed): array {
            if (!$stashed instanceof Context) {
                return ['stashed' => false, 'worker' => WorkerProbe::current()?->instance];
            }

            try {
                $stashed->run('stale', static fn (): int => 1);

                return ['stashed' => true, 'threw' => false, 'worker' => WorkerProbe::current()?->instance];
            } catch (\Throwable $e) {
                return ['stashed' => true, 'threw' => true, 'class' => $e::class, 'message' => $e->getMessage(), 'worker' => WorkerProbe::current()?->instance];
            }
        })
        ->handler('log', static function (Context $ctx, string $marker): string {
            $ctx->logger()->info('worker isolation marker', ['marker' => $marker]);

            return $ctx->request()->id;
        })
        ->definition(),
    ServiceReflector::fromObject(new InstanceBoundProbe()),
];
