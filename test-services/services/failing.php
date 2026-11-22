<?php

declare(strict_types=1);

use Restate\ObjectContext;
use Restate\Payload;
use Restate\RunRetryPolicy;
use Restate\RetryableError;
use Restate\Target;
use Restate\TerminalError;
use Restate\VirtualObject;

/** @return array<array-key, string> */
$metadata = static function (array $failure): array {
    $metadata = $failure['metadata'] ?? [];
    if (!is_array($metadata)) {
        return [];
    }

    $normalized = [];
    foreach ($metadata as $key => $value) {
        if (is_string($value)) {
            $normalized[$key] = $value;
        }
    }

    return $normalized;
};

$attemptFile = static function (ObjectContext $ctx, string $name): string {
    $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR') ?: dirname(__DIR__, 2) . '/var';

    return $stateDir . '/' . $name . '-' . hash('sha256', $ctx->key()) . '.txt';
};

$withAttemptFile = static function (string $file, callable $callback): mixed {
    $directory = dirname($file);
    if (!is_dir($directory)) {
        mkdir($directory, 0o777, true);
    }

    $handle = fopen($file, 'c+');
    if ($handle === false) {
        throw new \RuntimeException('Unable to open retry attempt file.');
    }

    try {
        flock($handle, LOCK_EX);
        $contents = stream_get_contents($handle);
        $attempts = (int) ($contents === false ? 0 : trim($contents));
        rewind($handle);

        return $callback($handle, $attempts);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
};

$nextAttempt = static function (ObjectContext $ctx, string $name) use ($attemptFile, $withAttemptFile): int {
    return $withAttemptFile($attemptFile($ctx, $name), static function ($handle, int $attempts): int {
        $attempt = $attempts + 1;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) $attempt);

        return $attempt;
    });
};

$readAttempts = static function (ObjectContext $ctx, string $name) use ($attemptFile, $withAttemptFile): int {
    return $withAttemptFile($attemptFile($ctx, $name), static fn ($handle, int $attempts): int => $attempts);
};

$clearAttempts = static function (ObjectContext $ctx, string $name) use ($attemptFile): void {
    $file = $attemptFile($ctx, $name);
    if (is_file($file)) {
        unlink($file);
    }
};

$attemptFromMessage = static function (string $message): int {
    if (preg_match('/Failed at attempt: (\d+)/', $message, $matches) === 1) {
        return (int) $matches[1];
    }

    return 0;
};

return VirtualObject::new('Failing')
    ->handler('terminallyFailingCall', static function (ObjectContext $ctx, array $failure) use ($metadata): never {
        throw new TerminalError((string) $failure['errorMessage'], 500, $metadata($failure));
    })
    ->handler('callTerminallyFailingCall', static function (ObjectContext $ctx, array $failure): string {
        return $ctx->call(Target::object('Failing', $ctx->uuid(), 'terminallyFailingCall'), $failure);
    })
    ->handler('failingCallWithEventualSuccess', static function (ObjectContext $ctx, mixed $_ = null) use ($nextAttempt, $clearAttempts): int {
        $attempt = $nextAttempt($ctx, 'failing-eventual-success');
        if ($attempt < 4) {
            throw new \RuntimeException("Failed at attempt: {$attempt}");
        }

        $clearAttempts($ctx, 'failing-eventual-success');
        return $attempt;
    }, Payload::empty())
    ->handler('terminallyFailingSideEffect', static function (ObjectContext $ctx, array $failure) use ($metadata): never {
        $ctx->run('terminally-failing-side-effect', static function () use ($failure, $metadata): never {
            throw new TerminalError((string) $failure['errorMessage'], 500, $metadata($failure));
        });

        throw new TerminalError((string) $failure['errorMessage'], 500, $metadata($failure));
    })
    ->handler('sideEffectSucceedsAfterGivenAttempts', static function (ObjectContext $ctx, int $minimumAttempts) use ($nextAttempt, $clearAttempts): int {
        return $ctx->run('side-effect-succeeds-after-attempts', static function () use ($ctx, $minimumAttempts, $nextAttempt, $clearAttempts): int {
            $attempt = $nextAttempt($ctx, 'side-effect-success');
            if ($attempt < $minimumAttempts) {
                throw new RetryableError('try again');
            }

            $clearAttempts($ctx, 'side-effect-success');
            return $attempt;
        }, RunRetryPolicy::infinite());
    })
    ->handler('sideEffectFailsAfterGivenAttempts', static function (ObjectContext $ctx, int $maxRetries) use ($nextAttempt, $readAttempts, $clearAttempts, $attemptFromMessage): int {
        try {
            return $ctx->run('side-effect-fails-after-attempts', static function () use ($ctx, $nextAttempt): int {
                $attempt = $nextAttempt($ctx, 'side-effect-failure');
                throw new RetryableError("Failed at attempt: {$attempt}");
            }, RunRetryPolicy::fixedDelay(maxAttempts: $maxRetries));
        } catch (TerminalError $e) {
            $attempts = max($readAttempts($ctx, 'side-effect-failure'), $attemptFromMessage($e->getMessage()));
            $clearAttempts($ctx, 'side-effect-failure');

            return $attempts;
        }
    })
    ->definition();
