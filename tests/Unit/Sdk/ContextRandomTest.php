<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

final class ContextRandomTest extends TestCase
{
    public function testDefaultRandomSeedIsZero(): void
    {
        self::assertSame(0, Contexts::service(ScriptedInvocation::unused())->randomSeed());
    }

    public function testRandomSeedReturnsConstructorSeed(): void
    {
        self::assertSame(12345, Contexts::service(ScriptedInvocation::unused(), 12345)->randomSeed());
    }

    public function testRandHelpersUseDeterministicSequence(): void
    {
        $ctx = Contexts::service(ScriptedInvocation::unused(), 1);

        self::assertSame(37, $ctx->randInt(1, 100));
        self::assertSame('beeb8da1', bin2hex($ctx->randBytes(4)));
        self::assertSame('f893a2ee-71c1-4690-b1bb-54d8c34d0bff', $ctx->uuid());
    }

    public function testTwoContextsWithSameSeedProduceSameSequence(): void
    {
        $left = Contexts::service(ScriptedInvocation::unused(), 99);
        $right = Contexts::service(ScriptedInvocation::unused(), 99);

        self::assertSame($left->uuid(), $right->uuid());
        self::assertSame($left->randInt(1, 1000), $right->randInt(1, 1000));
    }

    public function testRandIntRejectsInvalidRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Random integer minimum must be less than or equal to maximum.');

        Contexts::service(ScriptedInvocation::unused(), 1)->randInt(10, 1);
    }

    public function testRandBytesRejectsNegativeLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Random byte length must be greater than or equal to 0.');

        Contexts::service(ScriptedInvocation::unused(), 1)->randBytes(-1);
    }
}
