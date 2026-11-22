<?php

declare(strict_types=1);

use Restate\ObjectContext;
use Restate\Payload;
use Restate\Target;
use Restate\VirtualObject;

return VirtualObject::new('KillTestRunner')
    ->handler('startCallTree', static function (ObjectContext $ctx, mixed $_ = null): null {
        $ctx->call(Target::object('KillTestSingleton', $ctx->key(), 'recursiveCall'), null);

        return null;
    }, Payload::empty())
    ->definition();
