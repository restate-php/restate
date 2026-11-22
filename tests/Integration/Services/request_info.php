<?php

declare(strict_types=1);

use Restate\ObjectContext;
use Restate\VirtualObject;

// Returns the request the handler's context exposes (RequestTest).

return VirtualObject::new('RequestInfo')
    ->handler('describe', static function (ObjectContext $ctx, mixed $_): array {
        $request = $ctx->request();

        return [
            'id' => $request->id,
            'target' => [
                'service' => $request->target->serviceName(),
                'handler' => $request->target->handlerName(),
                'key' => $request->target->key(),
            ],
            'header' => $request->header('Restate-Php-Test'),
            'contentType' => $request->attemptHeaders['content-type'] ?? null,
            'body' => $request->body,
            'idempotencyKey' => $request->idempotencyKey,
            'scope' => $request->scope,
            'limitKey' => $request->limitKey,
        ];
    })
    ->definition();
