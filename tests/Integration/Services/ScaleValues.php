<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Services;

/**
 * The deterministic values of the scale services (scale.php), shared with the tests that check them, so a test can
 * compare a hash instead of shipping every value back through the ingress.
 */
final class ScaleValues
{
    /** `$bytes` printable bytes for step or key `$index`: its xxh128 hash, repeated. */
    public static function value(int $index, int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        $seed = hash('xxh128', (string) $index);

        return substr(str_repeat($seed, intdiv($bytes, strlen($seed)) + 1), 0, $bytes);
    }

    /** The xxh128 hash of steps 1…n's values, in order, as LongJournal returns it. */
    public static function stepsHash(int $n, int $bytes): string
    {
        $hash = hash_init('xxh128');
        for ($step = 1; $step <= $n; $step++) {
            hash_update($hash, self::value($step, $bytes));
        }

        return hash_final($hash);
    }

    public static function stateKey(int $index): string
    {
        return "k{$index}";
    }

    /**
     * The xxh128 hash of state keys and values in key order (`key=value\n`), as WideState/readAll returns it.
     *
     * @param array<string, mixed> $values
     */
    public static function stateHash(array $values): string
    {
        ksort($values, SORT_STRING);
        $hash = hash_init('xxh128');
        foreach ($values as $key => $value) {
            hash_update($hash, $key . '=' . (is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR)) . "\n");
        }

        return hash_final($hash);
    }

    /**
     * The hash WideState/readAll returns after fill {count, bytes}.
     */
    public static function filledStateHash(int $count, int $bytes): string
    {
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $values[self::stateKey($i)] = self::value($i, $bytes);
        }

        return self::stateHash($values);
    }

    /** Where FanOut's awakeable handlers write the ids of an invocation's awakeables (a JSON list). */
    public static function awakeablesFile(string $stateDir, string $invocationId): string
    {
        return $stateDir . '/scale-awakeables-' . hash('sha256', $invocationId) . '.json';
    }
}
