<?php

declare(strict_types=1);

use Restate\InvocationConfiguration;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\VirtualObject;

// Selective state preloading (StatePreloadTest): Restate sends only `hot` with each invocation; any other key is fetched
// when read, which in request/response mode suspends the attempt, so the read completes on the next attempt. The read
// handlers count their attempts in a state file to tell the two apart. Served by its own endpoint under
// /state-preload (tests/Integration/Support/endpoint.php): a key list needs discovery manifest v5 (Restate 1.8), and
// Restate 1.7 must still register the main endpoint.

$attempts = static function (ObjectSharedContext $ctx, string $handler): int {
    $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR');
    if ($stateDir === false || $stateDir === '') {
        $stateDir = dirname(__DIR__, 3) . '/var';
    }
    if (!is_dir($stateDir)) {
        mkdir($stateDir, 0o777, true);
    }
    $file = $stateDir . '/state-preload-attempts-' . hash('sha256', $ctx->request()->id . '/' . $handler) . '.txt';
    file_put_contents($file, "attempt\n", FILE_APPEND | LOCK_EX);

    $lines = file($file);

    return $lines === false ? 0 : count($lines);
};

return VirtualObject::new('PreloadedProfile', invocationConfiguration: new InvocationConfiguration(statePreload: ['hot']))
    ->handler('seed', static function (ObjectContext $ctx): string {
        $ctx->set('hot', 'preloaded');
        $ctx->set('cold', 'fetched');

        return 'seeded';
    })
    // Reads one key; returns its value and the attempt that returned it.
    ->sharedHandler('read', static function (ObjectSharedContext $ctx, string $key) use ($attempts): array {
        $attempt = $attempts($ctx, 'read');

        return ['value' => $ctx->get($key), 'attempt' => $attempt];
    })
    // The same, overriding the service's key list: this handler gets all state preloaded.
    ->sharedHandler('readEager', static function (ObjectSharedContext $ctx, string $key) use ($attempts): array {
        $attempt = $attempts($ctx, 'readEager');

        return ['value' => $ctx->get($key), 'attempt' => $attempt];
    }, invocationConfiguration: new InvocationConfiguration(statePreload: true))
    ->definition();
