<?php

declare(strict_types=1);

namespace Restate\Tests\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/** A PSR-3 logger that keeps every record: level, message and context, in order. */
final class RecordingLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<array{0: mixed, 1: string|\Stringable, 2: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, $message, $context];
    }

    /** @return list<array{0: mixed, 1: string}> level and message of every record */
    public function messages(): array
    {
        return array_map(static fn (array $record): array => [$record[0], (string) $record[1]], $this->records);
    }

    /**
     * The one record with this message.
     *
     * @return array{0: mixed, 1: string|\Stringable, 2: array<mixed>}
     */
    public function record(string $message): array
    {
        $records = array_values(array_filter($this->records, static fn (array $record): bool => (string) $record[1] === $message));
        if (count($records) !== 1) {
            throw new \LogicException(sprintf("Expected one record '%s', got %d: %s", $message, count($records), json_encode($this->messages())));
        }

        return $records[0];
    }
}
