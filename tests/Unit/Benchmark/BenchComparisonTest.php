<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Benchmark;

use PHPUnit\Framework\TestCase;
use Restate\Tests\Benchmark\Tooling\BenchComparison;
use Restate\Tests\Benchmark\Tooling\PhpbenchDump;

/** bin/bench-compare.sh's verdicts, from hand-written phpbench dumps. */
final class BenchComparisonTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->files);
    }

    public function testReadsTheMedianTimePerRevolutionOfEveryVariant(): void
    {
        $dump = PhpbenchDump::read($this->dump(['benchReplay#runs=10' => [30, 10, 20], 'benchHealth' => [5, 5]]));

        self::assertSame(['EndpointBench::benchReplay#runs=10', 'EndpointBench::benchHealth'], array_keys($dump->variants));
        self::assertSame(20.0, $dump->variants['EndpointBench::benchReplay#runs=10']->median);
        self::assertSame(1000, $dump->variants['EndpointBench::benchHealth']->memoryPeak);
        self::assertSame(0.0, $dump->variants['EndpointBench::benchHealth']->rstdev);
    }

    public function testARegressionMustShowInBothPairsAndOnlyComparedVariantsCount(): void
    {
        $base = ['same' => [100, 100], 'slower' => [100, 100], 'once' => [100, 100], 'faster' => [100, 100], 'noisy' => [100, 100], 'removed' => [1, 1]];
        $head1 = ['same' => [105, 105], 'slower' => [130, 130], 'once' => [130, 130], 'faster' => [50, 50], 'noisy' => [50, 150], 'added' => [1, 1]];
        $head2 = ['same' => [95, 95], 'slower' => [125, 125], 'once' => [101, 101], 'faster' => [60, 60], 'noisy' => [130, 130], 'added' => [1, 1]];

        $comparison = BenchComparison::compare(
            PhpbenchDump::read($this->dump($base)),
            PhpbenchDump::read($this->dump($head1)),
            PhpbenchDump::read($this->dump($head2)),
            PhpbenchDump::read($this->dump($base)),
            ['greet' => 1000.0, 'replay' => 1000.0],
            ['greet' => 1020.0, 'replay' => 1040.0, 'new' => 1.0],
        );

        self::assertSame([
            'EndpointBench::same' => 'same',
            'EndpointBench::slower' => 'regression',
            'EndpointBench::once' => 'same',
            'EndpointBench::faster' => 'improvement',
            'EndpointBench::noisy' => 'noisy',
        ], array_map(static fn (array $row): string => $row['verdict'], $comparison->rows));
        self::assertSame(['EndpointBench::added'], $comparison->added);
        self::assertSame(['EndpointBench::removed'], $comparison->removed);
        self::assertSame(['same', 'regression'], array_column(array_values($comparison->instructions), 'verdict'));
        self::assertSame(['EndpointBench::slower', 'instructions: replay'], $comparison->regressions());
        self::assertStringContainsString('| `EndpointBench::slower` | 100.00 | 127.50 | +30.0% | +25.0% |', $comparison->markdown());
    }

    /** @param array<string, list<int>> $variants subject[#set] => time per revolution of each iteration */
    private function dump(array $variants): string
    {
        $subjects = '';
        foreach ($variants as $name => $times) {
            [$subject, $set] = explode('#', $name) + [1 => ''];
            $iterations = implode('', array_map(static fn (int $time): string => "<iteration time-avg=\"{$time}\" mem-peak=\"1000\"/>", $times));
            $subjects .= "<subject name=\"{$subject}\"><variant revs=\"1\"><parameter-set name=\"{$set}\"/>{$iterations}</variant></subject>";
        }
        $file = tempnam(sys_get_temp_dir(), 'phpbench-dump-');
        self::assertIsString($file);
        file_put_contents($file, "<?xml version=\"1.0\"?><phpbench><suite><benchmark class=\"\\Restate\\Tests\\Benchmark\\EndpointBench\">{$subjects}</benchmark></suite></phpbench>");
        $this->files[] = $file;

        return $file;
    }
}
