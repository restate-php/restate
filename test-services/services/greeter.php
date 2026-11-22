<?php

declare(strict_types=1);

use Restate\Context;
use Restate\Payload;
use Restate\RetryableError;
use Restate\RunRetryPolicy;
use Restate\Service;

return Service::new('Greeter')
    ->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}")
    ->handler(
        'greetWithRun',
        static fn (Context $ctx, string $name): string => $ctx->run(
            'format-message',
            static fn (): string => "Hello {$name} from run",
        ),
    )
    ->handler('greetWithRetryRun', static function (Context $ctx, string $name): array {
        $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR') ?: dirname(__DIR__, 2) . '/var';
        $attemptFile = $stateDir . '/greet-with-retry-run-attempts.txt';
        $message = $ctx->run(
            'format-message-retry',
            static function () use ($name, $attemptFile): string {
                $attempts = is_file($attemptFile) ? (int) file_get_contents($attemptFile) : 0;
                file_put_contents($attemptFile, (string) ($attempts + 1));
                if ($attempts === 0) {
                    throw new RetryableError('try again');
                }

                return "Hello {$name} from retry run";
            },
            RunRetryPolicy::fixedDelay(100, 3, 2000),
        );

        return ['message' => $message, 'attempts' => (int) file_get_contents($attemptFile)];
    })
    ->handler(
        'schemaEcho',
        static fn (Context $ctx, array $payload): array => $payload,
        Payload::json([
            'type' => 'object',
            'required' => ['name'],
            'properties' => ['name' => ['type' => 'string']],
        ], required: true),
        Payload::json([
            'type' => 'object',
            'required' => ['name'],
            'properties' => ['name' => ['type' => 'string']],
        ]),
    )
    ->definition();
