<?php

declare(strict_types=1);

use Restate\Context;
use Restate\Service;
use Restate\Target;
use Restate\TerminalError;

return Service::new('Payments')
    ->handler('charge', static function (Context $ctx, array $request): string {
        throw new TerminalError('Payment declined', 400);
    })
    // Records each attempt under RESTATE_PHP_TEST_STATE_DIR, then fails terminally with the metadata from the request.
    ->handler('declineWithMetadata', static function (Context $ctx, array $request): never {
        $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR') ?: dirname(__DIR__, 2) . '/var';
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0o777, true);
        }
        file_put_contents($stateDir . '/payments-decline-' . hash('sha256', (string) $request['id']) . '.txt', "attempt\n", FILE_APPEND | LOCK_EX);

        throw new TerminalError('Card reported lost', 402, $request['metadata']);
    })
    // Calls declineWithMetadata and reports the terminal failure it observed.
    ->handler('reportDecline', static function (Context $ctx, array $request): array {
        try {
            $ctx->call(Target::service('Payments', 'declineWithMetadata'), $request);
        } catch (TerminalError $e) {
            return ['code' => $e->statusCode(), 'message' => $e->getMessage(), 'metadata' => (object) $e->metadata()];
        }

        throw new TerminalError('declineWithMetadata did not fail.', 500);
    })
    // Records each attempt, then fails terminally with a Latin-1 (not UTF-8) message and metadata value.
    ->handler('declineInLatin1', static function (Context $ctx, array $request): never {
        $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR') ?: dirname(__DIR__, 2) . '/var';
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0o777, true);
        }
        file_put_contents($stateDir . '/payments-latin1-' . hash('sha256', (string) $request['id']) . '.txt', "attempt\n", FILE_APPEND | LOCK_EX);

        throw new TerminalError("Carte refus\xE9e", 402, ['raison' => "carte vol\xE9e"]);
    })
    // Calls declineInLatin1 and reports the terminal failure it observed.
    ->handler('reportLatin1Decline', static function (Context $ctx, array $request): array {
        try {
            $ctx->call(Target::service('Payments', 'declineInLatin1'), $request);
        } catch (TerminalError $e) {
            return ['code' => $e->statusCode(), 'message' => $e->getMessage(), 'metadata' => (object) $e->metadata()];
        }

        throw new TerminalError('declineInLatin1 did not fail.', 500);
    })
    ->definition();
