<?php

declare(strict_types=1);

use Restate\Context;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Payload;
use Restate\Service;
use Restate\Target;
use Restate\TerminalError;
use Restate\VirtualObject;

// Targets for the ingress client (IngressClientTest).

return [
    Service::new('IngressTargets')
        ->handler('echo', static fn (Context $ctx, mixed $value): mixed => $value)
        ->handler('noInput', static fn (Context $ctx): string => 'no input', Payload::empty())
        ->handler('headers', static fn (Context $ctx, mixed $_ = null): array => [
            'connection' => $ctx->request()->header('x-ingress-connection'),
            'request' => $ctx->request()->header('x-ingress-request'),
        ], Payload::empty())
        ->handler('fail', static function (Context $ctx, array $failure): never {
            throw new TerminalError($failure['message'], $failure['code'], $failure['metadata'] ?? []);
        })
        ->handler('sleepThenEcho', static function (Context $ctx, array $request): mixed {
            $ctx->sleep($request['sleepMillis']);

            return $request['value'];
        })
        // Creates an awakeable, hands its id to IngressAwakeables/{key} for the test to read, and returns how it
        // completed.
        ->handler('awaitAwakeable', static function (Context $ctx, string $key): array {
            $awakeable = $ctx->awakeable();
            $ctx->send(Target::object('IngressAwakeables', $key, 'hold'), $awakeable->id());

            try {
                return ['value' => $awakeable->value()];
            } catch (TerminalError $e) {
                return ['rejected' => $e->getMessage(), 'code' => $e->statusCode()];
            }
        })
        ->definition(),
    VirtualObject::new('IngressAwakeables')
        ->handler('hold', static function (ObjectContext $ctx, string $id): null {
            $ctx->set('id', $id);

            return null;
        })
        ->sharedHandler('id', static fn (ObjectSharedContext $ctx): ?string => $ctx->get('id'), Payload::empty())
        ->definition(),
];
