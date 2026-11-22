<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark\Tooling;

/** One benchmark variant of one phpbench run. */
final readonly class VariantResult
{
    public function __construct(
        /** Median over the iterations of the time per revolution, in microseconds. */
        public float $median,
        /** Relative standard deviation of the iterations' time per revolution, in percent. */
        public float $rstdev,
        /** Peak memory of the iterations, in bytes. */
        public int $memoryPeak,
    ) {
    }
}
