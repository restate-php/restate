<?php

declare(strict_types=1);

use Restate\Context;
use Restate\Service;
use Restate\Target;

// Waiting for another invocation's output versus peeking at it (InvocationOutputTest).

return Service::new('InvocationOutputs')
    ->handler('slow', static function (Context $ctx, int $millis): string {
        $ctx->sleep($millis);

        return "slept {$millis}";
    })
    // Sends `slow`, peeks at its output while it runs, waits for it through the send handle, then peeks again.
    ->handler('sendAndWait', static function (Context $ctx, int $millis): array {
        $handle = $ctx->send(Target::service('InvocationOutputs', 'slow'), $millis);

        return ['running' => $handle->peekOutput(), 'output' => $handle->output(), 'finished' => $handle->peekOutput()];
    })
    // The same through a handle by invocation id.
    ->handler('waitById', static function (Context $ctx, int $millis): array {
        $handle = $ctx->invocation($ctx->send(Target::service('InvocationOutputs', 'slow'), $millis)->id());

        return ['running' => $handle->peekOutput(), 'output' => $handle->output(), 'finished' => $handle->peekOutput()];
    })
    ->definition();
