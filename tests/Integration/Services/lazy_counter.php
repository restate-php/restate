<?php

declare(strict_types=1);

use Restate\InvocationConfiguration;
use Restate\ObjectContext;
use Restate\VirtualObject;

// Lazy state (nothing preloaded): Restate sends no state with the invocation, so every state read is a round trip that
// suspends the attempt in request/response mode, and the read completes on the next attempt.
return VirtualObject::new('LazyCounter', invocationConfiguration: new InvocationConfiguration(statePreload: false))
    ->handler('add', static function (ObjectContext $ctx, int $amount): int {
        $new = (int) ($ctx->get('value') ?? 0) + $amount;
        $ctx->set('value', $new);

        return $new;
    })
    // Records in a state file when the `catch (\Exception)` block runs: a suspension must never reach it.
    ->handler('keysCatchingException', static function (ObjectContext $ctx, string $id): array|string {
        try {
            return $ctx->stateKeys();
        } catch (\Exception $e) {
            $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR');
            if (!is_string($stateDir) || $stateDir === '') {
                $stateDir = dirname(__DIR__, 3) . '/var';
            }
            if (!is_dir($stateDir)) {
                mkdir($stateDir, 0o777, true);
            }
            file_put_contents($stateDir . '/lazy-counter-caught-' . hash('sha256', $id) . '.txt', $e::class . ': ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);

            return 'caught ' . $e::class;
        }
    })
    ->definition();
