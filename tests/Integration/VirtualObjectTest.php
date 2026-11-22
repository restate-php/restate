<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\IntegrationTestCase;

final class VirtualObjectTest extends IntegrationTestCase
{
    /**
     * One ordered sequence: the final `alice` read proves the `state-api` key and clear
     * operations did not touch another object's state.
     *
     * `add` returns `{oldValue, newValue}`; `get`, `keys` and `clearAll` take no input
     * (null payload = empty request body). `clearKey` returns the keys it reads right after the
     * clear, in the same attempt: Restate sends this small state eagerly, and shared-core (0.10.0 to 8.7.2)
     * would still list the cleared key there.
     */
    public function testStateIsIsolatedPerKeyAndSupportsKeysAndClear(): void
    {
        $steps = [
            ['alice', 'add', '2', '{"oldValue":0,"newValue":2}'],
            ['alice', 'add', '3', '{"oldValue":2,"newValue":5}'],
            ['alice', 'get', null, '5'],
            ['bob', 'get', null, '0'],
            ['state-api', 'add', '2', '{"oldValue":0,"newValue":2}'],
            ['state-api', 'add', '3', '{"oldValue":2,"newValue":5}'],
            ['state-api', 'keys', null, '["value"]'],
            ['state-api', 'clearKey', '"value"', '[]'],
            ['state-api', 'keys', null, '[]'],
            ['state-api', 'add', '7', '{"oldValue":0,"newValue":7}'],
            ['state-api', 'keys', null, '["value"]'],
            ['state-api', 'clearAll', null, '"cleared-all"'],
            ['state-api', 'keys', null, '[]'],
            ['state-api', 'get', null, '0'],
            ['alice', 'get', null, '5'],
        ];

        foreach ($steps as [$key, $handler, $payload, $expected]) {
            self::assertInvocationReturns($expected, "Counter/{$key}/{$handler}", $payload);
        }
    }

    /**
     * With lazy state, reading the state keys needs a round trip to Restate: the first attempt must suspend (not fail
     * with an exception the handler can catch), and the next one gets the keys.
     */
    public function testStateKeysSuspendAndResumeWithLazyState(): void
    {
        $id = bin2hex(random_bytes(8));
        $caughtFile = self::stateDir() . '/lazy-counter-caught-' . hash('sha256', $id) . '.txt';

        self::assertInvocationReturns('2', "LazyCounter/{$id}/add", '2');
        self::assertInvocationReturns('["value"]', "LazyCounter/{$id}/keysCatchingException", json_encode($id, JSON_THROW_ON_ERROR));
        self::assertSame('', (string) @file_get_contents($caughtFile), 'The handler caught the suspension.');
    }
}
