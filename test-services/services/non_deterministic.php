<?php

declare(strict_types=1);

use Restate\ObjectContext;
use Restate\Payload;
use Restate\Target;
use Restate\VirtualObject;

$nextInvocationIsOdd = static function (ObjectContext $ctx): bool {
    $path = getenv('RESTATE_PHP_NONDETERMINISTIC_COUNTS_PATH');
    if (!is_string($path) || $path === '') {
        $path = dirname(__DIR__, 2) . '/var/non-deterministic-counts.json';
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create NonDeterministic count directory: {$directory}");
    }

    $file = fopen($path, 'c+');
    if ($file === false) {
        throw new RuntimeException("Unable to open non-deterministic counts file: {$path}");
    }

    try {
        if (!flock($file, LOCK_EX)) {
            throw new RuntimeException("Unable to lock non-deterministic counts file: {$path}");
        }

        $contents = stream_get_contents($file);
        $counts = [];
        if (is_string($contents) && $contents !== '') {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $count) {
                    if (is_string($key) && is_int($count)) {
                        $counts[$key] = $count;
                    }
                }
            }
        }

        $key = $ctx->key();
        $count = ($counts[$key] ?? 0) + 1;
        $counts[$key] = $count;

        rewind($file);
        ftruncate($file, 0);
        fwrite($file, json_encode($counts, JSON_THROW_ON_ERROR));
        fflush($file);

        return $count % 2 === 1;
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
};

return VirtualObject::new('NonDeterministic')
    ->handler('eitherSleepOrCall', static function (ObjectContext $ctx) use ($nextInvocationIsOdd): null {
        if ($nextInvocationIsOdd($ctx)) {
            $ctx->sleep(100);
        } else {
            $ctx->call(Target::object('Counter', 'abc', 'get'), null);
        }

        $ctx->sleep(100);
        $ctx->send(Target::object('Counter', $ctx->key(), 'add'), 1);

        return null;
    }, Payload::empty(), Payload::empty())
    ->handler('callDifferentMethod', static function (ObjectContext $ctx) use ($nextInvocationIsOdd): null {
        if ($nextInvocationIsOdd($ctx)) {
            $ctx->call(Target::object('Counter', 'abc', 'get'), null);
        } else {
            $ctx->call(Target::object('Counter', 'abc', 'reset'), null);
        }

        $ctx->sleep(100);
        $ctx->send(Target::object('Counter', $ctx->key(), 'add'), 1);

        return null;
    }, Payload::empty(), Payload::empty())
    ->handler('backgroundInvokeWithDifferentTargets', static function (ObjectContext $ctx) use ($nextInvocationIsOdd): null {
        if ($nextInvocationIsOdd($ctx)) {
            $ctx->send(Target::object('Counter', 'abc', 'get'), null);
        } else {
            $ctx->send(Target::object('Counter', 'abc', 'reset'), null);
        }

        $ctx->sleep(100);
        $ctx->send(Target::object('Counter', $ctx->key(), 'add'), 1);

        return null;
    }, Payload::empty(), Payload::empty())
    ->handler('setDifferentKey', static function (ObjectContext $ctx) use ($nextInvocationIsOdd): null {
        if ($nextInvocationIsOdd($ctx)) {
            $ctx->set('a', 'my-state');
        } else {
            $ctx->set('b', 'my-state');
        }

        $ctx->sleep(100);
        $ctx->send(Target::object('Counter', $ctx->key(), 'add'), 1);

        return null;
    }, Payload::empty(), Payload::empty())
    ->definition();
