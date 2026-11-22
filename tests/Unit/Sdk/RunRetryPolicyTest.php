<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\RunRetryPolicy;

final class RunRetryPolicyTest extends TestCase
{
    public function testNoneSerializesToArray(): void
    {
        self::assertSame(['type' => 'none'], RunRetryPolicy::none()->toArray());
    }

    public function testInfiniteSerializesToArray(): void
    {
        self::assertSame(['type' => 'infinite'], RunRetryPolicy::infinite()->toArray());
    }

    public function testFixedDelaySerializesConfiguredValues(): void
    {
        self::assertSame([
            'type' => 'fixed_delay',
            'interval_millis' => 100,
            'max_attempts' => 3,
            'max_duration_millis' => 1000,
            'on_max_attempts' => 'fail',
        ], RunRetryPolicy::fixedDelay(100, 3, 1000)->toArray());
    }

    public function testFixedDelaySerializesNullDefaults(): void
    {
        self::assertSame([
            'type' => 'fixed_delay',
            'interval_millis' => null,
            'max_attempts' => null,
            'max_duration_millis' => null,
            'on_max_attempts' => 'fail',
        ], RunRetryPolicy::fixedDelay()->toArray());
    }

    public function testFixedDelayAcceptsMaximumUnsignedThirtyTwoBitMaxAttempts(): void
    {
        self::assertSame(['type' => 'fixed_delay', 'interval_millis' => null, 'max_attempts' => 4294967295, 'max_duration_millis' => null, 'on_max_attempts' => 'fail'], RunRetryPolicy::fixedDelay(maxAttempts: 4294967295)->toArray());
    }

    public function testFixedDelayRejectsMaxAttemptsAboveUnsignedThirtyTwoBits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry policy max attempts must fit within 32 bits.');

        RunRetryPolicy::fixedDelay(maxAttempts: 4294967296);
    }

    public function testExponentialSerializesDefaultsAndConfiguredMaxAttempts(): void
    {
        self::assertSame([
            'type' => 'exponential',
            'initial_interval_millis' => 100,
            'factor' => 2.0,
            'max_interval_millis' => 2000,
            'max_attempts' => 3,
            'max_duration_millis' => 50000,
            'on_max_attempts' => 'fail',
        ], RunRetryPolicy::exponential(maxAttempts: 3)->toArray());
    }

    public function testRejectsNegativeDurations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry policy durations must be greater than or equal to 0 milliseconds.');

        RunRetryPolicy::fixedDelay(-1);
    }

    public function testRejectsNegativeMaxAttempts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry policy max attempts must be greater than or equal to 0.');

        RunRetryPolicy::fixedDelay(maxAttempts: -1);
    }

    public function testRejectsExponentialFactorBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry policy exponentiation factor must be greater than or equal to 1.0.');

        RunRetryPolicy::exponential(factor: 0.99);
    }

    public function testRejectsInfiniteExponentialFactor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry policy exponentiation factor must be finite.');

        RunRetryPolicy::exponential(factor: INF);
    }

    public function testRejectsNanExponentialFactor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry policy exponentiation factor must be finite.');

        RunRetryPolicy::exponential(factor: NAN);
    }
}
