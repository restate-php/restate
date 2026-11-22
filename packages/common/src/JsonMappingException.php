<?php

declare(strict_types=1);

namespace Restate;

final class JsonMappingException extends \RuntimeException implements Exception
{
    public static function at(string $path, string $message): self
    {
        return new self("Invalid JSON value at {$path}: {$message}.");
    }
}
