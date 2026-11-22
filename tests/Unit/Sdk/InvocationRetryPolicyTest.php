<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Restate\InvocationRetryPolicy;

final class InvocationRetryPolicyTest extends TestCase
{
    public function testManifestFieldsUseDiscoveryNamesAndOmitNulls(): void
    {
        $policy = new InvocationRetryPolicy(
            initialIntervalMillis: 100,
            exponentiationFactor: 2.5,
            maxIntervalMillis: 5000,
            maxAttempts: 3,
            onMaxAttempts: InvocationRetryPolicy::ON_MAX_ATTEMPTS_KILL,
        );

        self::assertSame([
            'retryPolicyInitialInterval' => 100,
            'retryPolicyMaxInterval' => 5000,
            'retryPolicyMaxAttempts' => 3,
            'retryPolicyExponentiationFactor' => 2.5,
            'retryPolicyOnMaxAttempts' => 'KILL',
        ], $policy->manifestFields());
    }

    public function testEmptyPolicyEmitsNoManifestFields(): void
    {
        self::assertSame([], new InvocationRetryPolicy()->manifestFields());
    }

    public function testRejectsNegativeDurations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invocation retry policy durations must be greater than or equal to 0 milliseconds.');

        new InvocationRetryPolicy(initialIntervalMillis: -1);
    }

    public function testRejectsNegativeMaxAttempts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invocation retry policy max attempts must be greater than or equal to 0.');

        new InvocationRetryPolicy(maxAttempts: -1);
    }

    public function testRejectsExponentiationFactorBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invocation retry policy exponentiation factor must be greater than or equal to 1.0.');

        new InvocationRetryPolicy(exponentiationFactor: 0.99);
    }

    public function testRejectsNonFiniteExponentiationFactor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invocation retry policy exponentiation factor must be finite.');

        new InvocationRetryPolicy(exponentiationFactor: INF);
    }

    public function testRejectsInvalidOnMaxAttempts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invocation retry policy on max attempts must be PAUSE or KILL.');

        new InvocationRetryPolicy(onMaxAttempts: 'RETRY_FOREVER');
    }
}
