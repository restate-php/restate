<?php

declare(strict_types=1);

namespace Restate\Tests\Soak;

use PHPUnit\Framework\TestCase;
use Restate\Tests\Integration\Support\IntegrationEndpoints;
use Restate\Tests\Integration\Support\WorkerProbe;
use Restate\Tests\Soak\Support\Checkpoints;
use Restate\Tests\Soak\Support\SoakEndpoint;
use Restate\Tests\Soak\Support\SoakRequest;
use Restate\Tests\Soak\Support\SoakRun;
use Restate\Tests\Soak\Support\SoakSettings;
use Restate\Tests\Soak\Support\Slope;
use Restate\Tests\Support\Workload\Workload;
use Restate\Tests\Support\Workload\WorkloadResponse;

/**
 * A PHP fatal error in the middle of a conversion inside the extension (the bailout probe of the debug build:
 * a request header whose value is `__restate_php_bailout_probe__` raises E_ERROR while the extension converts the
 * request's headers into Rust strings). PHP unwinds with longjmp across the Rust frames, so their destructors never
 * run: the strings converted so far leak. The VM itself never existed; VMs alive at a fatal error are still freed at
 * request shutdown (free_obj runs for every object). In worker mode the fatal error ends the worker script, and
 * FrankenPHP starts it again.
 *
 * Asserted, over about 100 fatal errors (20 in a quick run), each followed by ordinary requests:
 * - the leak per fatal error (the Rust heap's growth, from the diagnostics build's counting allocator) is bounded by
 *   the headers converted before the probe (a 12 KiB header is sent with it; FrankenPHP passes headers in random
 *   order, so it is converted before the probe about half the time);
 * - every fatal error restarts the worker script (a new instance), and the next requests succeed on it;
 * - FrankenPHP never gives up on the worker (max_consecutive_failures, 6 by default, counts only workers that fail
 *   before they first reach frankenphp_handle_request()), also for fatal errors back to back.
 */
final class BailoutSoakTest extends TestCase
{
    private const string BAILOUT_PROBE = '__restate_php_bailout_probe__';

    private const int PAD_BYTES = 12 * 1024;

    public function testAFatalErrorInAConversionLeaksAtMostWhatItWasConverting(): void
    {
        $report = SoakSettings::reportDir('bailout');
        $endpoint = SoakEndpoint::start(SoakSettings::debugImage(), 1, [IntegrationEndpoints::LOGGER_VARIABLE => 'off', 'RESTATE_PHP_TEST_DISPLAY_ERRORS' => '0']);
        $run = new SoakRun($endpoint, concurrency: 1);
        $iterations = SoakSettings::count(100, 20);

        $run->phase(Checkpoints::WARM_UP, 200, self::ok(...), batches: 1);
        $samples = [];
        $instances = [$endpoint->stats()['instance']];
        for ($iteration = 0; $iteration < $iterations; ++$iteration) {
            $run->send([self::bailout($instances)]);
            $run->send(array_map(self::ok(...), range(1, 10)));
            $stats = $endpoint->stats();
            self::assertNotContains($stats['instance'], array_slice($instances, 0, -1), 'The fatal error did not restart the worker script.');
            $instances[] = $stats['instance'];
            $samples[] = [$iteration, $stats['diagnostics']['rust_live_bytes'] ?? 0, ($stats['process']['rssAnonKib'] ?? 0) * 1024, $stats['diagnostics']['live_vms'] ?? -1];
        }
        // Back to back, with no request in between.
        $run->send(array_map(fn (): SoakRequest => self::bailout($instances, track: false), range(1, 20)));
        $run->send([self::ok(0)]);

        $settled = array_slice($samples, intdiv(count($samples), 10));
        $rustPerFatal = Slope::theilSen(array_map(static fn (array $sample): array => [$sample[0], $sample[1]], $settled));
        $rssPerFatal = Slope::theilSen(array_map(static fn (array $sample): array => [$sample[0], $sample[2]], $settled));
        $total = $samples[count($samples) - 1][1] - $samples[0][1];
        SoakSettings::report($report, sprintf('%d fatal errors: Rust heap %+.0f bytes per fatal error (Theil-Sen; %.0f on average), anonymous RSS %+.0f bytes per fatal error; %d bytes of headers sent with each', $iterations, $rustPerFatal, $total / max(1, count($samples) - 1), $rssPerFatal, self::PAD_BYTES + strlen(self::BAILOUT_PROBE)));

        self::assertSame([], $run->unexpected());
        self::assertSame([0], array_values(array_unique(array_column($samples, 3))), 'A VM outlived its request.');
        // At most the headers the extension had converted, plus their Vec and String overhead.
        self::assertLessThanOrEqual(self::PAD_BYTES + 1024, $total / max(1, count($samples) - 1));
        self::assertTrue($endpoint->isRunning());
        $logs = $endpoint->logs();
        self::assertStringNotContainsString('too many consecutive', $logs);
        self::assertGreaterThanOrEqual($iterations + 20, substr_count($logs, 'unexpected termination, restarting'));
    }

    /** @param list<string> $instances the worker script runs so far; the response's must be the last one */
    private static function bailout(array &$instances, bool $track = true): SoakRequest
    {
        return new SoakRequest(
            Workload::start(invocationId: 'inv_1workload' . bin2hex(random_bytes(8))),
            'none',
            static function (WorkloadResponse $response) use (&$instances, $track): bool {
                return $response->status === 500 && (!$track || $response->header(WorkerProbe::HEADER_INSTANCE) === $instances[count($instances) - 1]);
            },
            'bailout-probe',
            [['x-restate-php-pad', str_repeat('p', self::PAD_BYTES)], ['x-restate-php-probe', self::BAILOUT_PROBE]],
        );
    }

    private static function ok(int $i): SoakRequest
    {
        return new SoakRequest(Workload::start(invocationId: 'inv_1workload' . bin2hex(random_bytes(8))), 'none');
    }
}
