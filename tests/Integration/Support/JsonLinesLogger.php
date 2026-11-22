<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * The integration endpoint's PSR-3 logger: appends one JSON object per record (`level`, `message`, `context`) to a
 * file the tests read back. An exception in the context is written as its class and message.
 */
final class JsonLinesLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(private readonly string $file)
    {
    }

    /** @param array<mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $context = array_map(
            static fn (mixed $value): mixed => $value instanceof \Throwable ? ['class' => $value::class, 'message' => $value->getMessage()] : $value,
            $context,
        );

        file_put_contents(
            $this->file,
            json_encode(['level' => $level, 'message' => (string) $message, 'context' => $context], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * The records in $file, oldest first.
     *
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public static function read(string $file): array
    {
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
        return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines === false ? [] : $lines);
    }
}
