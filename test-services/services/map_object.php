<?php

declare(strict_types=1);

use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Payload;
use Restate\VirtualObject;

$entryKey = static fn (string $key): string => "entry:{$key}";
$externalKey = static fn (string $stateKey): string => substr($stateKey, strlen('entry:'));

return VirtualObject::new('MapObject')
    ->handler('set', static function (ObjectContext $ctx, array $entry) use ($entryKey): null {
        $ctx->set($entryKey((string) $entry['key']), (string) $entry['value']);

        return null;
    })
    ->sharedHandler('get', static fn (ObjectSharedContext $ctx, string $key): string => (string) ($ctx->get($entryKey($key)) ?? ''))
    ->handler('clearAll', static function (ObjectContext $ctx, mixed $_ = null) use ($externalKey): array {
        $entries = [];
        foreach ($ctx->stateKeys() as $stateKey) {
            if (!str_starts_with($stateKey, 'entry:')) {
                continue;
            }

            $value = $ctx->get($stateKey);
            $entries[] = ['key' => $externalKey($stateKey), 'value' => is_string($value) ? $value : ''];
            $ctx->clear($stateKey);
        }

        return $entries;
    }, Payload::empty())
    ->definition();
