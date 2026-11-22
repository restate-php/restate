<?php

declare(strict_types=1);

namespace Restate\Tests\Support;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackendShapesTest extends TestCase
{
    public function testAcceptsDefinedShapes(): void
    {
        self::assertSame('execute_run', BackendShapes::variant('doAwait', ['type' => 'execute_run', 'handle' => 7]));
        self::assertSame('failure', BackendShapes::variant('takeNotificationResult', ['type' => 'failure', 'code' => 404, 'message' => 'x', 'metadata' => [404 => 'y']]));
        self::assertSame('input', BackendShapes::variant('input', self::input()));
        self::assertSame('input', BackendShapes::variant('input', self::input(['scope' => 's', 'limit_key' => 'l', 'idempotency_key' => 'i'])));
    }

    /**
     * An input result, v5/v6 style (no scope, limit key or idempotency key), with $fields replaced.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function input(array $fields = []): array
    {
        return [
            'invocation_id' => 'i',
            'random_seed' => -1,
            'key' => '',
            'headers' => [['a', 'b']],
            'input' => "\xff",
            'scope' => null,
            'limit_key' => null,
            'idempotency_key' => null,
            ...$fields,
        ];
    }

    /** @return iterable<string, array{0: string, 1: array<array-key, mixed>, 2: string}> */
    public static function drifted(): iterable
    {
        yield 'missing field' => ['doAwait', ['type' => 'execute_run'], 'must have exactly the fields type, handle'];
        yield 'extra field' => ['doAwait', ['type' => 'suspended', 'handle' => 1], 'must have exactly the fields type'];
        yield 'unknown type' => ['takeNotificationResult', ['type' => 'pending'], 'must have a type of: success, void, failure, invocation_id, state_keys'];
        yield 'wrong field type' => ['takeNotificationResult', ['type' => 'failure', 'code' => '409', 'message' => 'x', 'metadata' => []], "'code' must be int"];
        yield 'bytes not a string' => ['takeNotificationResult', ['type' => 'success', 'value' => 1], "'value' must be string"];
        yield 'string seed' => ['input', self::input(['random_seed' => '1']), "'random_seed' must be int"];
        yield 'int scope' => ['input', self::input(['scope' => 1]), "'scope' must be ?string"];
        yield 'input without the v7 fields' => [
            'input',
            ['invocation_id' => 'i', 'random_seed' => 1, 'key' => '', 'headers' => [], 'input' => ''],
            'must have exactly the fields invocation_id, random_seed, key, headers, input, scope, limit_key, idempotency_key',
        ];
        yield 'headers not pairs' => ['responseHead', ['status_code' => 200, 'headers' => [['a']]], "'headers' must be headers"];
        yield 'not a contract method' => ['takeOutput', [], 'takeOutput() returns no array the backend contract defines'];
    }

    /** @param array<array-key, mixed> $result */
    #[DataProvider('drifted')]
    public function testRejectsShapesTheExtensionDoesNotReturn(string $method, array $result, string $message): void
    {
        try {
            BackendShapes::check($method, $result);
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString($message, $e->getMessage());

            return;
        }

        self::fail('Expected the shape to be rejected.');
    }
}
