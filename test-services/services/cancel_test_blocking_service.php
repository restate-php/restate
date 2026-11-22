<?php

declare(strict_types=1);

use Restate\ObjectContext;
use Restate\Payload;
use Restate\Target;
use Restate\VirtualObject;

return VirtualObject::new('CancelTestBlockingService')
    ->handler('block', static function (ObjectContext $ctx, string $operation): null {
        $first = $ctx->awakeable();
        $ctx->send(Target::object('AwakeableHolder', $ctx->key(), 'hold'), $first->id());
        $first->value();

        match ($operation) {
            'CALL' => $ctx->call(Target::object('CancelTestBlockingService', $ctx->key(), 'block'), 'CALL'),
            'SLEEP' => $ctx->sleep(88_473_600_000),
            'AWAKEABLE' => $ctx->awakeable()->value(),
            default => null,
        };

        return null;
    }, output: Payload::empty())
    ->handler('isUnlocked', static fn (ObjectContext $ctx, mixed $_ = null): null => null, Payload::empty(), Payload::empty())
    ->definition();
