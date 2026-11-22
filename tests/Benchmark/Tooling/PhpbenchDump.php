<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark\Tooling;

/**
 * The variants of one `phpbench run --dump-file` XML file, keyed `Class::subject` or `Class::subject#set` (the class
 * without its namespace): per variant the median of its iterations' time per revolution, their relative standard
 * deviation and the peak memory.
 */
final readonly class PhpbenchDump
{
    /** @param array<string, VariantResult> $variants */
    private function __construct(public array $variants)
    {
    }

    public static function read(string $file): self
    {
        $xml = @simplexml_load_file($file);
        if ($xml === false) {
            throw new \RuntimeException("Not a phpbench XML dump: {$file}");
        }

        $variants = [];
        $benchmarks = $xml->xpath('//benchmark');
        foreach (is_array($benchmarks) ? $benchmarks : [] as $benchmark) {
            $class = (string) $benchmark['class'];
            $class = substr($class, (int) strrpos($class, '\\') + 1);
            foreach ($benchmark->subject as $subject) {
                foreach ($subject->variant as $variant) {
                    $set = (string) ($variant->{'parameter-set'}['name'] ?? '');
                    $key = $class . '::' . $subject['name'] . ($set === '' ? '' : "#{$set}");
                    $times = [];
                    $memory = 0;
                    foreach ($variant->iteration as $iteration) {
                        $times[] = (float) $iteration['time-avg'];
                        $memory = max($memory, (int) $iteration['mem-peak']);
                    }
                    if ($times !== [] && !isset($variant->errors)) {
                        $variants[$key] = new VariantResult(self::median($times), self::rstdev($times), $memory);
                    }
                }
            }
        }

        return new self($variants);
    }

    /** @param non-empty-list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** @param non-empty-list<float> $values relative standard deviation, in percent */
    private static function rstdev(array $values): float
    {
        $mean = array_sum($values) / count($values);
        if ($mean <= 0.0) {
            return 0.0;
        }
        $variance = array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $values)) / count($values);

        return sqrt($variance) / $mean * 100;
    }
}
