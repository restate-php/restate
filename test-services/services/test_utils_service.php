<?php

declare(strict_types=1);

use Restate\Context;
use Restate\Payload;
use Restate\Service;
use Restate\TerminalError;

// TestUtilsService from the SDK conformance suite (restatedev/e2e, sdk-tests v2.3, contracts/TestUtilsService.kt).

return Service::new('TestUtilsService')
    ->handler('echo', static fn (Context $ctx, string $input): string => $input)
    ->handler('uppercaseEcho', static fn (Context $ctx, string $input): string => strtoupper($input))
    ->handler('echoHeaders', static fn (Context $ctx, mixed $_ = null): array => $ctx->request()->headers, Payload::empty())
    ->handler(
        'rawEcho',
        static fn (Context $ctx, string $input): string => $input,
        Payload::binary('application/octet-stream'),
        Payload::binary('application/octet-stream'),
    )
    ->handler('countExecutedSideEffects', static function (Context $ctx, int $increments): int {
        $count = 0;
        for ($i = 0; $i < $increments; $i++) {
            $ctx->run('count', static function () use (&$count): null {
                ++$count;

                return null;
            });
        }

        return $count;
    })
    ->handler('cancelInvocation', static function (Context $ctx, string $invocationId): null {
        $ctx->invocation($invocationId)->cancel();

        return null;
    })
    ->handler('resolveSignal', static function (Context $ctx, array $request): null {
        $ctx->invocation((string) $request['invocationId'])->signal((string) $request['signalName'])->resolve((string) $request['value']);

        return null;
    })
    ->handler('rejectSignal', static function (Context $ctx, array $request): null {
        $ctx->invocation((string) $request['invocationId'])->signal((string) $request['signalName'])->reject(new TerminalError((string) $request['reason']));

        return null;
    })
    ->definition();
