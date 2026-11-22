<?php

declare(strict_types=1);

use Restate\ObjectContext;
use Restate\Payload;
use Restate\TerminalError;
use Restate\VirtualObject;

return VirtualObject::new('AwakeableHolder')
    ->handler('hold', static function (ObjectContext $ctx, string $id): null {
        $ctx->set('id', $id);

        return null;
    })
    ->handler('hasAwakeable', static fn (ObjectContext $ctx, mixed $_ = null): bool => $ctx->get('id') !== null, Payload::empty())
    ->handler('unlock', static function (ObjectContext $ctx, string $payload): null {
        $id = $ctx->get('id');
        if (!is_string($id)) {
            throw new TerminalError('No awakeable registered', 500);
        }

        $ctx->resolveAwakeable($id, $payload);
        $ctx->clear('id');

        return null;
    })
    ->definition();
