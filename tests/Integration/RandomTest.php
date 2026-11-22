<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Internal\DeterministicRandom;
use Restate\Tests\Integration\Support\IntegrationTestCase;

final class RandomTest extends IntegrationTestCase
{
    public function testRandomValuesAreDerivedFromInvocationSeed(): void
    {
        $payload = json_decode(self::invoke('Communicator/randomValues', 'null', timeout: 20.0), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        $seed = $payload['seed'] ?? null;
        self::assertIsInt($seed);
        self::assertNotSame(0, $seed);

        self::assertIsArray($payload['ints'] ?? null);
        self::assertCount(2, $payload['ints']);
        foreach ($payload['ints'] as $int) {
            self::assertIsInt($int);
            self::assertGreaterThanOrEqual(1, $int);
            self::assertLessThanOrEqual(100, $int);
        }

        self::assertIsString($payload['bytes_hex'] ?? null);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $payload['bytes_hex']);

        self::assertIsArray($payload['uuids'] ?? null);
        self::assertCount(2, $payload['uuids']);
        foreach ($payload['uuids'] as $uuid) {
            self::assertIsString($uuid);
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
        }
        self::assertNotSame($payload['uuids'][0], $payload['uuids'][1]);

        $random = new DeterministicRandom($seed);
        self::assertSame([$random->nextInt(1, 100), $random->nextInt(1, 100)], $payload['ints']);
        self::assertSame(bin2hex($random->nextBytes(8)), $payload['bytes_hex']);
        self::assertSame([self::nextUuid($random), self::nextUuid($random)], $payload['uuids']);
    }

    private static function nextUuid(DeterministicRandom $random): string
    {
        $parts = array_values((array) unpack('C16', $random->nextBytes(16)));
        $parts[6] = ($parts[6] & 0x0f) | 0x40;
        $parts[8] = ($parts[8] & 0x3f) | 0x80;
        $hex = implode('', array_map(static fn (int $byte): string => sprintf('%02x', $byte), $parts));

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
