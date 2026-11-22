<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Scale;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Contention: many concurrent invocations of Ledger/add (tests/Integration/Services/scale.php) on one virtual
 * object key and across many keys, sent through the ingress by many clients at once.
 *
 * Ledger/add reads `v`, runs a step (which ends the attempt in request/response mode), then sets `v` to the value it
 * read plus the amount. Its second attempt must take the read from the journal: Restate holds the key's lock across
 * both attempts, but a replay that read fresh state, or a lock released between them, would lose or repeat updates.
 * So the old values the adds return are exactly 0…N-1 on each key, and `v` ends at N.
 */
final class ContentionTest extends ScaleTestCase
{
    public function testConcurrentAddsOnOneKey(): void
    {
        $this->assertContendedKey(40, 16, 10);
    }

    public function testConcurrentAddsAcrossKeys(): void
    {
        $this->assertContendedKeys(5, 8, 16);
    }

    public function testOneIdempotencyKeyFromManyClientsExecutesOnce(): void
    {
        $this->assertIdempotentAdds(10, 3);
    }

    /** @return iterable<string, array{string, list<int>}> */
    public static function contention(): iterable
    {
        yield '1000 adds on one key, 64 clients, 200 peeks' => ['assertContendedKey', [1000, 64, 200]];
        yield '100 keys x 20 adds, 64 clients' => ['assertContendedKeys', [100, 20, 64]];
        yield '100 clients x 10 idempotency keys' => ['assertIdempotentAdds', [100, 10]];
    }

    /** @param list<int> $arguments */
    #[Group('scale')]
    #[DataProvider('contention')]
    public function testContention(string $assertion, array $arguments): void
    {
        match ($assertion) {
            'assertContendedKey' => $this->assertContendedKey(...$arguments),
            'assertContendedKeys' => $this->assertContendedKeys(...$arguments),
            'assertIdempotentAdds' => $this->assertIdempotentAdds(...$arguments),
            default => throw new \InvalidArgumentException("Unknown assertion {$assertion}."),
        };
    }

    /** `$adds` adds and `$peeks` shared reads of one key, interleaved, `$clients` in flight. */
    private function assertContendedKey(int $adds, int $clients, int $peeks): void
    {
        $key = 'one-' . bin2hex(random_bytes(4));
        $requests = [];
        $peekEvery = max(1, intdiv($adds, max(1, $peeks)));
        for ($i = 0, $peeked = 0; $i < $adds; $i++) {
            $requests["add-{$i}"] = ['path' => "Ledger/{$key}/add", 'body' => '{"amount":1}'];
            if ($i % $peekEvery === 0 && $peeked++ < $peeks) {
                $requests["peek-{$i}"] = ['path' => "Ledger/{$key}/peek", 'body' => null];
            }
        }

        $started = microtime(true);
        $responses = self::postConcurrently($requests, $clients, 300.0 + $adds);
        $seconds = microtime(true) - $started;

        $old = [];
        foreach ($responses as $name => $response) {
            self::assertSame(200, $response->status, "{$name}: {$response->body}");
            $value = json_decode($response->body, flags: JSON_THROW_ON_ERROR);
            self::assertIsInt($value, "{$name}: {$response->body}");
            if (str_starts_with((string) $name, 'peek-')) {
                self::assertGreaterThanOrEqual(0, $value);
                self::assertLessThanOrEqual($adds, $value, 'A peek sees a value some add wrote.');
            } else {
                $old[] = $value;
            }
        }
        sort($old);
        self::assertSame(range(0, $adds - 1), $old, 'Each add saw the value the one before it wrote: no lost or repeated update.');
        self::assertSame((string) $adds, self::invoke("Ledger/{$key}/peek", null));

        self::report('contention-one-key', ['adds' => $adds, 'clients' => $clients, 'peeks' => count($requests) - $adds, 'wallSeconds' => round($seconds, 2), 'addsPerSecond' => round($adds / $seconds, 1)]);
    }

    /** `$adds` adds on each of `$keys` keys, sent interleaved, `$clients` in flight. */
    private function assertContendedKeys(int $keys, int $adds, int $clients): void
    {
        $prefix = 'many-' . bin2hex(random_bytes(4));
        $requests = [];
        for ($i = 0; $i < $adds; $i++) {
            for ($k = 0; $k < $keys; $k++) {
                $requests["{$k}/{$i}"] = ['path' => "Ledger/{$prefix}-{$k}/add", 'body' => '{"amount":1}'];
            }
        }

        $started = microtime(true);
        $responses = self::postConcurrently($requests, $clients, 300.0 + $keys * $adds / 10);
        $seconds = microtime(true) - $started;

        $old = array_fill(0, $keys, []);
        foreach ($responses as $name => $response) {
            self::assertSame(200, $response->status, "{$name}: {$response->body}");
            $value = json_decode($response->body, flags: JSON_THROW_ON_ERROR);
            self::assertIsInt($value, "{$name}: {$response->body}");
            $old[(int) explode('/', (string) $name)[0]][] = $value;
        }
        for ($k = 0; $k < $keys; $k++) {
            sort($old[$k]);
            self::assertSame(range(0, $adds - 1), $old[$k], "Key {$k}: no lost or repeated update.");
            self::assertSame((string) $adds, self::invoke("Ledger/{$prefix}-{$k}/peek", null));
        }

        self::report('contention-many-keys', ['keys' => $keys, 'addsPerKey' => $adds, 'clients' => $clients, 'wallSeconds' => round($seconds, 2), 'addsPerSecond' => round($keys * $adds / $seconds, 1)]);
    }

    /**
     * `$clients` clients each send Ledger/add for every one of `$idempotencyKeys` idempotency keys, all at once, on one
     * object: each idempotency key executes once, so `v` ends at the number of keys, and every client gets the same
     * answer for the same idempotency key.
     */
    private function assertIdempotentAdds(int $clients, int $idempotencyKeys): void
    {
        $key = 'idem-' . bin2hex(random_bytes(4));
        $requests = [];
        for ($client = 0; $client < $clients; $client++) {
            for ($i = 0; $i < $idempotencyKeys; $i++) {
                $requests["{$i}/{$client}"] = ['path' => "Ledger/{$key}/add", 'body' => '{"amount":1}', 'headers' => ['idempotency-key' => "{$key}-{$i}"]];
            }
        }

        $responses = self::postConcurrently($requests, $clients, 300.0);

        $answers = [];
        foreach ($responses as $name => $response) {
            self::assertSame(200, $response->status, "{$name}: {$response->body}");
            $answers[(int) explode('/', (string) $name)[0]][$response->body] = true;
        }
        $old = [];
        foreach ($answers as $i => $bodies) {
            self::assertCount(1, $bodies, "Every client got the same answer for idempotency key {$i}.");
            $old[] = (int) array_key_first($bodies);
        }
        sort($old);
        self::assertSame(range(0, $idempotencyKeys - 1), $old, 'Each idempotency key executed once.');
        self::assertSame((string) $idempotencyKeys, self::invoke("Ledger/{$key}/peek", null));
        self::assertCount(
            $idempotencyKeys,
            self::restate()->query("SELECT id FROM sys_invocation WHERE target = 'Ledger/{$key}/add'"),
            'Restate made one invocation per idempotency key.',
        );
    }
}
