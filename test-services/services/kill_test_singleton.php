<?php

declare(strict_types=1);

use Restate\ObjectContext;
use Restate\Payload;
use Restate\Target;
use Restate\VirtualObject;

return VirtualObject::new('KillTestSingleton')
    ->handler('recursiveCall', static function (ObjectContext $ctx, mixed $_ = null): null {
        $awakeable = $ctx->awakeable();
        $ctx->send(Target::object('AwakeableHolder', $ctx->key(), 'hold'), $awakeable->id());
        $awakeable->value();

        $ctx->call(Target::object('KillTestSingleton', $ctx->key(), 'recursiveCall'), null);

        return null;
    }, Payload::empty())
    ->handler('isUnlocked', static fn (ObjectContext $ctx, mixed $_ = null): null => null, Payload::empty())
    ->definition();
