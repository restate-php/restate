<?php

declare(strict_types=1);

use Restate\Context;
use Restate\InvocationHandle;
use Restate\Service;
use Restate\Target;

// Proxy from the upstream SDK test suite (restatedev/e2e, sdk-tests v2.3, contracts/Proxy.kt).

// ProxyRequest.scope scopes the target (Target::scoped()); ProxyRequest.limitKey is the call's or send's limit key
// within it (ServiceToServiceScopeConcurrency).
$targetFor = static function (array $request): Target {
    $target = ($request['virtualObjectKey'] ?? null) !== null
        ? Target::object((string) $request['serviceName'], (string) $request['virtualObjectKey'], (string) $request['handlerName'])
        : Target::service((string) $request['serviceName'], (string) $request['handlerName']);

    return ($request['scope'] ?? null) !== null ? $target->scoped((string) $request['scope']) : $target;
};

$payloadFor = static function (array $request): mixed {
    $message = $request['message'] ?? [];
    $payload = is_array($message) ? implode('', array_map(static fn (mixed $byte): string => chr((int) $byte), $message)) : $message;

    return json_decode((string) $payload, true, flags: JSON_THROW_ON_ERROR);
};

$idempotencyKeyFor = static fn (array $request): ?string => ($request['idempotencyKey'] ?? null) !== null
    ? (string) $request['idempotencyKey']
    : null;

$limitKeyFor = static fn (array $request): ?string => ($request['limitKey'] ?? null) !== null
    ? (string) $request['limitKey']
    : null;

$send = static function (Context $ctx, array $request) use ($targetFor, $payloadFor, $idempotencyKeyFor, $limitKeyFor): InvocationHandle {
    $delay = $request['delayMillis'] ?? null;

    return $delay === null
        ? $ctx->send($targetFor($request), $payloadFor($request), $idempotencyKeyFor($request), limitKey: $limitKeyFor($request))
        : $ctx->sendAfter($targetFor($request), $payloadFor($request), (int) $delay, $idempotencyKeyFor($request), limitKey: $limitKeyFor($request));
};

return Service::new('Proxy')
    ->handler('call', static function (Context $ctx, array $request) use ($targetFor, $payloadFor, $idempotencyKeyFor, $limitKeyFor): array {
        $result = $ctx->call($targetFor($request), $payloadFor($request), $idempotencyKeyFor($request), limitKey: $limitKeyFor($request));
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        return array_values(unpack('C*', $encoded));
    })
    ->handler('oneWayCall', static fn (Context $ctx, array $request): string => $send($ctx, $request)->id())
    ->handler('manyCalls', static function (Context $ctx, array $requests) use ($targetFor, $payloadFor, $idempotencyKeyFor, $limitKeyFor, $send): null {
        $deferred = [];
        foreach ($requests as $request) {
            $proxyRequest = $request['proxyRequest'];
            if ((bool) $request['oneWayCall']) {
                $send($ctx, $proxyRequest);
                continue;
            }
            if ((bool) $request['awaitAtTheEnd']) {
                $deferred[] = $ctx->callFuture($targetFor($proxyRequest), $payloadFor($proxyRequest), $idempotencyKeyFor($proxyRequest), limitKey: $limitKeyFor($proxyRequest));
                continue;
            }
            $ctx->call($targetFor($proxyRequest), $payloadFor($proxyRequest), $idempotencyKeyFor($proxyRequest), limitKey: $limitKeyFor($proxyRequest));
        }
        foreach ($deferred as $future) {
            $future->awaitResult();
        }

        return null;
    })
    ->definition();
