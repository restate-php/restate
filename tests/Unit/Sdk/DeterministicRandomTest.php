<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\Internal\DeterministicRandom;

final class DeterministicRandomTest extends TestCase
{
    public function testSameSeedProducesSameSequence(): void
    {
        $first = new DeterministicRandom(123);
        $second = new DeterministicRandom(123);

        self::assertSame($first->nextBytes(8), $second->nextBytes(8));
        self::assertSame($first->nextInt(1, 1000), $second->nextInt(1, 1000));
        self::assertSame($first->nextBytes(8), $second->nextBytes(8));
    }

    public function testDifferentSeedsProduceDifferentSequences(): void
    {
        $first = new DeterministicRandom(123);
        $second = new DeterministicRandom(456);

        self::assertNotSame($first->nextBytes(16), $second->nextBytes(16));
    }

    public function testKnownSeedOneProducesExactSequence(): void
    {
        $random = new DeterministicRandom(1);

        self::assertSame('910a2decbeeb8da1f893a2ee71c18690', bin2hex($random->nextBytes(16)));
        self::assertSame(61, $random->nextInt(1, 100));
        self::assertSame('c34d', bin2hex($random->nextBytes(2)));
    }

    public function testNextBytesZeroReturnsEmptyString(): void
    {
        $random = new DeterministicRandom(1);

        self::assertSame('', $random->nextBytes(0));
    }

    public function testNextIntSameMinimumAndMaximumReturnsValueWithoutConsumingRandomness(): void
    {
        $random = new DeterministicRandom(1);
        $fresh = new DeterministicRandom(1);

        self::assertSame(42, $random->nextInt(42, 42));
        self::assertSame($fresh->nextBytes(4), $random->nextBytes(4));
    }

    public function testNextIntSupportsNegativeRanges(): void
    {
        $random = new DeterministicRandom(1);

        self::assertSame(-4, $random->nextInt(-10, -1));
    }

    public function testNextIntRejectsRangesLargerThanThirtyTwoBits(): void
    {
        $random = new DeterministicRandom(1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Random integer range must fit within 32 bits.');

        $random->nextInt(PHP_INT_MIN, PHP_INT_MAX);
    }

    public function testNegativeSeedsProduceDeterministicSequenceDifferentFromPositiveSeeds(): void
    {
        $first = new DeterministicRandom(-1);
        $second = new DeterministicRandom(-1);
        $positive = new DeterministicRandom(1);
        $negativeSequence = $first->nextBytes(16);

        self::assertSame($negativeSequence, $second->nextBytes(16));
        self::assertNotSame($positive->nextBytes(16), $negativeSequence);
    }

    public function testNextBytesRejectsNegativeLength(): void
    {
        $random = new DeterministicRandom(1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Random byte length must be greater than or equal to 0.');

        $random->nextBytes(-1);
    }

    public function testNextIntRejectsInvalidRange(): void
    {
        $random = new DeterministicRandom(1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Random integer minimum must be less than or equal to maximum.');

        $random->nextInt(100, 1);
    }
}
