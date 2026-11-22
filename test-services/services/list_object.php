<?php

declare(strict_types=1);

use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Payload;
use Restate\VirtualObject;

$items = static fn (ObjectSharedContext $ctx): array => is_array($ctx->get('items')) ? $ctx->get('items') : [];

return VirtualObject::new('ListObject')
    ->handler('append', static function (ObjectContext $ctx, string $value) use ($items): null {
        $current = $items($ctx);
        $current[] = $value;
        $ctx->set('items', $current);

        return null;
    })
    ->sharedHandler('get', static fn (ObjectSharedContext $ctx, mixed $_ = null): array => $items($ctx), Payload::empty())
    ->handler('clear', static function (ObjectContext $ctx, mixed $_ = null) use ($items): array {
        $current = $items($ctx);
        $ctx->clear('items');

        return $current;
    }, Payload::empty())
    ->definition();
