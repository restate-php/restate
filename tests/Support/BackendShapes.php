<?php

declare(strict_types=1);

namespace Restate\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The array shapes the Extension Surface returns (`Restate\Extension\Vm`, `Restate\Extension\protocol_versions()`; see
 * extension/restate.stub.php), in one place, keyed by the Backend Contract method that returns them as typed values
 * (`Restate\Internal\Backend`, `Restate\Internal\InvocationOperations`, `Restate\Internal\Backend\Invocation`).
 *
 * `tests/Extension/BackendContractTest.php` drives the real extension into every variant listed here, checks each with
 * check() and converts it with `Extension\SurfaceValues`, and fails when the extension produces a variant this class does
 * not list, cannot be made to produce one it does, or when a typed variant (an `AwaitState`, `NotificationKind` or
 * `RunCompletionStatus` case) is not listed. The unit tests script typed values ({@see ScriptedInvocation}), so this
 * is what keeps them to what the extension produces. A new variant or field is added here first.
 *
 * Field types: `int`, `string` (any bytes), `?string` (a string or null), `list<string>`, `list<int>`, `metadata` (string values;
 * keys may be ints, as PHP stores numeric-string keys), `headers` (`[name, value]` pairs).
 */
final class BackendShapes
{
    private const array INTERRUPTIONS = [
        'suspended' => [],
        'read_from_input' => [],
        'waiting_pending_run' => [],
        'cancel_signal_received' => [],
        'execute_run' => ['handle' => 'int'],
    ];

    /** Results tagged by `type`: method => [type => [field => field type]]. */
    public const array TAGGED = [
        'doAwait' => ['any_completed' => []] + self::INTERRUPTIONS,
        'doAwaitTree' => ['any_completed' => ['completed' => 'list<int>']] + self::INTERRUPTIONS,
        'takeNotificationResult' => [
            'success' => ['value' => 'string'],
            'void' => [],
            'failure' => ['code' => 'int', 'message' => 'string', 'metadata' => 'metadata'],
            'invocation_id' => ['invocation_id' => 'string'],
            'state_keys' => ['keys' => 'list<string>'],
        ],
        'proposeRunCompletionRetryable' => [
            'attempt_ended' => [],
            'completed' => [],
        ],
    ];

    /** Results with one shape: method => [field => field type]. */
    public const array FIXED = [
        'protocolVersions' => ['min' => 'int', 'max' => 'int'],
        'sysAwakeable' => ['id' => 'string', 'handle' => 'int'],
        'responseHead' => ['status_code' => 'int', 'headers' => 'headers'],
        'input' => [
            'invocation_id' => 'string',
            'random_seed' => 'int',
            'key' => 'string',
            'headers' => 'headers',
            'input' => 'string',
            'scope' => '?string',
            'limit_key' => '?string',
            'idempotency_key' => '?string',
        ],
        // One element of what `Vm::takeLogs()` returns; the extension invocation forwards the typed records to the endpoint's
        // logger (`CoreLogForwarder`) instead of returning them from a contract method.
        'coreLogRecord' => ['level' => 'string', 'target' => 'string', 'message' => 'string', 'fields' => 'metadata'],
    ];

    /**
     * Fails the test unless $result is a shape $method returns; returns $result unchanged.
     *
     * @template T of array
     *
     * @param T $result
     *
     * @return T
     */
    public static function check(string $method, array $result): array
    {
        $fields = self::fields($method, $result);
        $context = "{$method}() result " . self::describe($result);

        Assert::assertSame(
            array_keys($fields),
            array_keys($result),
            "{$context} must have exactly the fields " . implode(', ', array_keys($fields)) . '.',
        );
        foreach ($fields as $field => $type) {
            Assert::assertTrue(self::isOfType($result[$field], $type), "{$context}: '{$field}' must be {$type}.");
        }

        return $result;
    }

    /**
     * The variant of a checked result: its `type`, or the method name for a result with one shape.
     *
     * @param array<array-key, mixed> $result
     */
    public static function variant(string $method, array $result): string
    {
        self::check($method, $result);

        return array_key_exists($method, self::TAGGED) ? (string) $result['type'] : $method;
    }

    /**
     * @param array<array-key, mixed> $result
     *
     * @return array<string, string> the expected fields in order, `type` first for tagged results
     */
    private static function fields(string $method, array $result): array
    {
        if (array_key_exists($method, self::FIXED)) {
            return self::FIXED[$method];
        }

        Assert::assertArrayHasKey($method, self::TAGGED, "{$method}() returns no array the backend contract defines.");
        $variants = self::TAGGED[$method];
        $type = $result['type'] ?? null;
        Assert::assertTrue(
            is_string($type) && array_key_exists($type, $variants),
            "{$method}() result " . self::describe($result) . ' must have a type of: ' . implode(', ', array_keys($variants)) . '.',
        );

        return ['type' => 'string'] + $variants[$type];
    }

    private static function isOfType(mixed $value, string $type): bool
    {
        return match ($type) {
            'int' => is_int($value),
            'string' => is_string($value),
            '?string' => $value === null || is_string($value),
            'list<string>' => is_array($value) && array_is_list($value) && self::allStrings($value),
            'list<int>' => is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $item): bool => is_int($item)),
            'metadata' => is_array($value) && self::allStrings($value),
            'headers' => is_array($value) && array_is_list($value) && array_all(
                $value,
                static fn (mixed $pair): bool => is_array($pair) && array_keys($pair) === [0, 1] && self::allStrings($pair),
            ),
            default => throw new \LogicException("Unknown field type {$type}."),
        };
    }

    /** @param array<array-key, mixed> $values */
    private static function allStrings(array $values): bool
    {
        return array_all($values, static fn (mixed $value): bool => is_string($value));
    }

    /** @param array<array-key, mixed> $result */
    private static function describe(array $result): string
    {
        $json = json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '(unprintable)' : $json;
    }
}
