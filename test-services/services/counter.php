<?php

declare(strict_types=1);

use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Payload;
use Restate\TerminalError;
use Restate\VirtualObject;

return VirtualObject::new('Counter')
    ->handler('add', static function (ObjectContext $ctx, int $amount): array {
        $old = (int) ($ctx->get('value') ?? 0);
        $new = $old + $amount;
        $ctx->set('value', $new);

        return ['oldValue' => $old, 'newValue' => $new];
    })
    ->handler('addThenFail', static function (ObjectContext $ctx, int $amount): never {
        $old = (int) ($ctx->get('value') ?? 0);
        $new = $old + $amount;
        $ctx->set('value', $new);

        throw new TerminalError("Counter {$ctx->key()} failed after add", 500);
    }, output: Payload::empty())
    ->sharedHandler('get', static fn (ObjectSharedContext $ctx, mixed $_ = null): int => (int) ($ctx->get('value') ?? 0), Payload::empty())
    ->handler('reset', static function (ObjectContext $ctx, mixed $_ = null): null {
        $ctx->clear('value');

        return null;
    }, Payload::empty(), Payload::empty())
    ->handler('keys', static fn (ObjectContext $ctx): array => $ctx->stateKeys())
    // Returns the keys left, read in the same attempt as the clear.
    ->handler('clearKey', static function (ObjectContext $ctx, string $key): array {
        $ctx->clear($key);

        return $ctx->stateKeys();
    })
    ->handler('clearAll', static function (ObjectContext $ctx): string {
        $ctx->clearAll();
        return 'cleared-all';
    })
    ->definition();
