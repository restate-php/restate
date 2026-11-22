<?php

declare(strict_types=1);

namespace Restate\Tests\Soak\Support;

/**
 * Growth rate of a resource over a soak run: the Theil–Sen estimator, the median of the slopes between every pair of
 * samples. Robust to the outliers a garbage collector's sawtooth or a one-off allocation make, which a least-squares
 * fit is not.
 */
final class Slope
{
    private function __construct()
    {
    }

    /**
     * @param list<array{0: int|float, 1: int|float}> $points (x, y), e.g. (requests served, bytes)
     */
    public static function theilSen(array $points): float
    {
        $slopes = [];
        $count = count($points);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $dx = $points[$j][0] - $points[$i][0];
                if ($dx !== 0 && $dx !== 0.0) {
                    $slopes[] = ($points[$j][1] - $points[$i][1]) / $dx;
                }
            }
        }
        if ($slopes === []) {
            return 0.0;
        }
        sort($slopes);
        $middle = intdiv(count($slopes), 2);

        return (float) (count($slopes) % 2 === 1 ? $slopes[$middle] : ($slopes[$middle - 1] + $slopes[$middle]) / 2);
    }
}
