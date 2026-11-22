<?php

declare(strict_types=1);

namespace Restate\Internal;

/**
 * Validates terminal failure metadata and prepares it for a backend.
 *
 * Metadata is a string-to-string map on the wire. PHP stores numeric-string array keys as ints (`'404'` becomes `404`,
 * and so does `json_decode('{"404":"x"}', true)`), so an int key is accepted as the string it came from.
 *
 * @internal
 */
final class TerminalMetadata
{
    /**
     * @param array<array-key, mixed> $metadata
     *
     * @phpstan-assert-if-true array<array-key, string> $metadata
     */
    public static function isValid(array $metadata): bool
    {
        foreach ($metadata as $value) {
            if (!is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validates metadata decoded from a backend result.
     *
     * @return array<array-key, string>
     *
     * @throws BackendException when the metadata is not a string-to-string map
     */
    public static function fromDecoded(mixed $metadata): array
    {
        if (!is_array($metadata) || !self::isValid($metadata)) {
            throw new BackendException('Terminal failure returned invalid metadata.');
        }

        return $metadata;
    }

    /**
     * The metadata as handed to a backend: keys and values that are not UTF-8 have their invalid bytes replaced by
     * U+FFFD (the protocol requires UTF-8, PHP strings need not be), instead of failing the error path.
     *
     * @param array<array-key, string> $metadata
     *
     * @return array<array-key, string>
     */
    public static function forBackend(array $metadata): array
    {
        $valid = [];
        foreach ($metadata as $key => $value) {
            $valid[is_string($key) ? Utf8::substitute($key) : $key] = Utf8::substitute($value);
        }

        return $valid;
    }
}
