<?php

declare(strict_types=1);

use Restate\ObjectContext;
use Restate\Payload;
use Restate\Target;
use Restate\TerminalError;
use Restate\VirtualObject;

return VirtualObject::new('CancelTestRunner')
    ->handler('startTest', static function (ObjectContext $ctx, string $operation): null {
        try {
            $ctx->call(Target::object('CancelTestBlockingService', $ctx->key(), 'block'), $operation);
        } catch (TerminalError $e) {
            if ($e->statusCode() !== 409) {
                throw $e;
            }

            $ctx->set('canceled', true);

            throw $e;
        }

        return null;
    }, output: Payload::empty())
    ->handler('verifyTest', static function (ObjectContext $ctx, mixed $_ = null): bool {
        return (bool) ($ctx->get('canceled') ?? false);
    }, Payload::empty())
    ->definition();
