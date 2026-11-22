<?php

declare(strict_types=1);

use Restate\Context;
use Restate\InvocationRetryPolicy;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Payload;
use Restate\Service;
use Restate\Tests\Integration\Services\ChaosRecorder;
use Restate\Tests\Integration\Services\MemoryProbe;
use Restate\VirtualObject;

/*
 * Large request and response bodies (LargeBodyTest). Every handler records its
 * attempts (ChaosRecorder) and its memory (MemoryProbe), and gives up after three attempts (KILL), so a body the
 * endpoint or Restate cannot take ends in a bounded failure instead of retrying for ever.
 *
 * `LargeBodies`:
 * - ingest (binary input): returns the input's length and SHA-1. The `x-large-bodies-trigger` header (comma-separated)
 *   makes it misbehave first: `echo` prints, `deprecated` raises a real E_DEPRECATED, `warning` a real E_WARNING.
 *   Whether those reach the response depends on the endpoint's display_errors.
 * - settings: the endpoint's display_errors (as a boolean: does PHP print into responses), enable_post_data_reading,
 *   post_max_size, memory_limit and SAPI, whether OPcache is on, and this request's protocol and CONTENT_LENGTH.
 * - output (int): returns that many bytes as a binary body.
 * - runResult (int): a run whose result is that many bytes (journaled, then replayed by the next attempt); returns its
 *   length.
 * `LargeBodiesObject` (virtual object):
 * - setState (int): sets state `blob` to that many bytes; returns the length.
 * - stateLength (shared): the length of state `blob` (eager state in the start message).
 */

$recorder = ChaosRecorder::fromEnvironment();
$memory = MemoryProbe::fromEnvironment();
$retries = new InvocationRetryPolicy(initialIntervalMillis: 100, exponentiationFactor: 1.0, maxAttempts: 3, onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_KILL);
$binary = Payload::binary('application/octet-stream');

$probe = static function (Context $ctx, int $bytes) use ($recorder, $memory): int {
    $id = ChaosRecorder::invocationId($ctx);
    $memory->recordAtShutdown($id, $bytes);

    return $recorder->attempt($id);
};

$largeBodies = Service::new('LargeBodies', invocationRetryPolicy: $retries)
    ->handler('ingest', static function (Context $ctx, string $input) use ($probe): array {
        $attempt = $probe($ctx, strlen($input));
        $triggers = array_filter(explode(',', $ctx->request()->headers['x-large-bodies-trigger'] ?? ''), static fn (string $trigger): bool => $trigger !== '');
        foreach ($triggers as $trigger) {
            match ($trigger) {
                'echo' => print "stray output from LargeBodies/ingest\n",
                // Calling current() on an object is deprecated (8.1+): a real E_DEPRECATED, not E_USER_DEPRECATED.
                'deprecated' => current(new ArrayObject([1])),
                // An odd-length hex string: a real E_WARNING from PHP itself.
                'warning' => hex2bin('abc'),
                default => throw new InvalidArgumentException("Unknown trigger {$trigger}."),
            };
        }

        return ['bytes' => strlen($input), 'sha1' => sha1($input), 'attempt' => $attempt];
    }, $binary)
    ->handler('settings', static function (Context $ctx, mixed $_ = null): array {
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

        return [
            // Whether PHP prints its messages into the response: on, stdout and stderr (php -S writes stderr into it too).
            'displayErrors' => in_array(strtolower((string) ini_get('display_errors')), ['1', 'on', 'yes', 'true', 'stdout', 'stderr'], true),
            'enablePostDataReading' => filter_var(ini_get('enable_post_data_reading'), FILTER_VALIDATE_BOOLEAN),
            'postMaxSize' => ini_get('post_max_size'),
            'memoryLimit' => ini_get('memory_limit'),
            'sapi' => PHP_SAPI,
            // How this request reached PHP: Restate sends no Content-Length; a FastCGI front door must supply one.
            'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? null,
            'contentLength' => $_SERVER['CONTENT_LENGTH'] ?? null,
            'opcache' => is_array($opcache) && ($opcache['opcache_enabled'] ?? false) === true,
        ];
    }, Payload::empty())
    ->handler('output', static function (Context $ctx, int $bytes) use ($probe): string {
        $probe($ctx, $bytes);

        return str_repeat('o', $bytes);
    }, Payload::json(), $binary)
    ->handler('runResult', static function (Context $ctx, int $bytes) use ($probe): int {
        $probe($ctx, $bytes);

        return strlen($ctx->run('blob', static fn (): string => str_repeat('r', $bytes)));
    })
    ->definition();

$largeBodiesObject = VirtualObject::new('LargeBodiesObject', invocationRetryPolicy: $retries)
    ->handler('setState', static function (ObjectContext $ctx, int $bytes) use ($probe): int {
        $probe($ctx, $bytes);
        $ctx->set('blob', str_repeat('s', $bytes));

        return $bytes;
    })
    ->sharedHandler('stateLength', static function (ObjectSharedContext $ctx, mixed $_ = null) use ($probe): int {
        $probe($ctx, 0);
        $blob = $ctx->get('blob');

        return is_string($blob) ? strlen($blob) : -1;
    }, Payload::empty())
    ->definition();

return [$largeBodies, $largeBodiesObject];
