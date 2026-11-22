<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use Psr\Http\Message\StreamInterface;

final class PsrTestStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private string $contents = '')
    {
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->contents;
    }

    #[\Override]
    public function close(): void
    {
    }

    #[\Override]
    public function detach(): mixed
    {
        return null;
    }

    #[\Override]
    public function getSize(): int
    {
        return strlen($this->contents);
    }

    #[\Override]
    public function tell(): int
    {
        return $this->position;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->position >= strlen($this->contents);
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return true;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->contents) + $offset,
            default => $offset,
        };
    }

    #[\Override]
    public function rewind(): void
    {
        $this->position = 0;
    }

    #[\Override]
    public function isWritable(): bool
    {
        return true;
    }

    #[\Override]
    public function write(string $string): int
    {
        $this->contents .= $string;
        $this->position = strlen($this->contents);

        return strlen($string);
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function read(int $length): string
    {
        $chunk = substr($this->contents, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    #[\Override]
    public function getContents(): string
    {
        $contents = substr($this->contents, $this->position);
        $this->position = strlen($this->contents);

        return $contents;
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
