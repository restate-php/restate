<?php

declare(strict_types=1);

namespace Restate\Tests\Soak;

use PHPUnit\Framework\TestCase;
use Restate\Tests\Integration\Support\IntegrationEndpoints;
use Restate\Tests\Integration\Support\WorkerProbe;
use Restate\Tests\Soak\Support\Checkpoints;
use Restate\Tests\Soak\Support\SoakEndpoint;
use Restate\Tests\Soak\Support\SoakIdentity;
use Restate\Tests\Soak\Support\SoakRequest;
use Restate\Tests\Soak\Support\SoakRun;
use Restate\Tests\Soak\Support\SoakSettings;
use Restate\Tests\Support\Workload\Workload;

/**
 * A FrankenPHP worker (one thread, release build with the `diagnostics` feature) serves about a million requests
 * replayed straight to it, as Restate sends them (chunked, no Content-Length, signed), with no Restate in between.
 * Every request class of the workload library runs alone first, so a leak is pinned to a class, then all of them
 * mixed. The worker's heaps are sampled between batches; see {@see Checkpoints} for what passes. A quick run
 * (RESTATE_PHP_SOAK_QUICK=1) sends a few percent of it and does not gate on RSS.
 *
 * The thresholds come from clean runs (TESTING.md, "Soak suite"): the Rust and PHP heaps do not grow at all once warm
 * (measured 0.000 bytes per request), so the threshold is 1 byte per request; RSS grows a little
 * while glibc's and Go's allocators settle, under 16 bytes per request.
 *
 * {@see testPlantedLeaksAreCaught()} is the suite's self-test: a worker that keeps 256 bytes of PHP heap, or one Rust
 * VM, per request must fail these checks, naming the counter.
 */
final class FrameReplaySoakTest extends TestCase
{
    /** @var array{rust: float, php: float, rss: ?float} */
    private const array MAX_SLOPES = ['rust' => 1.0, 'php' => 1.0, 'rss' => 16.0];

    /** Bodies above this are sent less often, so each class costs about the same time. */
    private const int CHEAP_BODY = 64 * 1024;

    public function testNoRequestClassLeaks(): void
    {
        $report = SoakSettings::reportDir('frame-replay');
        $identity = new SoakIdentity();
        $run = new SoakRun(self::endpoint($identity), $identity, concurrency: 4);
        $classes = self::classes();

        $perClass = SoakSettings::count(50_000, 2_000);
        $run->phase(Checkpoints::WARM_UP, SoakSettings::count(50_000, 500), self::mix($classes), batches: 5);
        foreach ($classes as $class => $make) {
            $rate = $run->phase($class, self::share($make, $perClass), static fn (int $i): SoakRequest => $make(self::invocationId()), batches: 20);
            SoakSettings::report($report, sprintf('%-22s %.0f requests/s', $class, $rate));
        }
        $rate = $run->phase('mixed', SoakSettings::count(500_000, 10_000), self::mix($classes), batches: 40);
        SoakSettings::report($report, sprintf('%-22s %.0f requests/s', 'mixed', $rate));

        $run->checkpoints->writeCsv("{$report}/checkpoints.csv");
        SoakSettings::report($report, $run->checkpoints->summary());
        SoakSettings::report($report, 'responses: ' . json_encode($run->responses()));

        self::assertSame([], $run->unexpected(), 'Requests that did not get the expected answer.');
        self::assertSame([], $run->checkpoints->failures(self::maxSlopes()), "Soak checks failed; samples in {$report}/checkpoints.csv");
        self::assertTrue($run->endpoint->isRunning());
        self::assertStringNotContainsString('panicked', $run->endpoint->logs());
    }

    public function testPlantedLeaksAreCaught(): void
    {
        $expected = ['php' => 'phpBytes grows', 'vm' => 'live_vms is'];
        foreach ($expected as $canary => $failure) {
            $identity = new SoakIdentity();
            $endpoint = self::endpoint($identity, [WorkerProbe::CANARY_VARIABLE => $canary]);
            $run = new SoakRun($endpoint, $identity, concurrency: 4);
            $make = self::classes()['start'];

            $run->phase(Checkpoints::WARM_UP, 500, static fn (int $i): SoakRequest => $make(self::invocationId()), batches: 2);
            $run->phase('start', 4_000, static fn (int $i): SoakRequest => $make(self::invocationId()), batches: 10);
            $endpoint->stop();

            $failures = $run->checkpoints->failures(self::maxSlopes());
            self::assertNotEmpty(array_filter($failures, static fn (string $message): bool => str_starts_with($message, 'start: ') && str_contains($message, $failure)), "The {$canary} canary went unnoticed: " . json_encode($failures));
            if ($canary === 'vm') {
                self::assertNotEmpty(array_filter($failures, static fn (string $message): bool => str_contains($message, 'rustBytes grows')), json_encode($failures, JSON_THROW_ON_ERROR));
            }
        }
    }

    /**
     * The request classes: every class of the workload library, a 9 MiB body (over `post_max_size`, with
     * display_errors=0 the startup warning stays out of the response), and a request whose identity is forged.
     *
     * @return array<string, \Closure(string): SoakRequest>
     */
    private static function classes(): array
    {
        $classes = [];
        foreach (Workload::classes() as $class => $make) {
            $classes[$class] = static fn (string $id): SoakRequest => new SoakRequest($make($id));
        }
        $classes['large-input:9437184'] = static fn (string $id): SoakRequest => new SoakRequest(Workload::largeInput(9 << 20, $id));
        $classes['forged-identity'] = static fn (string $id): SoakRequest => SoakRequest::forged(Workload::start(invocationId: $id));

        return $classes;
    }

    /**
     * Every class in turn, the large ones less often.
     *
     * @param array<string, \Closure(string): SoakRequest> $classes
     *
     * @return \Closure(int): SoakRequest
     */
    private static function mix(array $classes): \Closure
    {
        $schedule = [];
        foreach ($classes as $make) {
            $schedule = [...$schedule, ...array_fill(0, self::share($make, 64), $make)];
        }

        return static fn (int $i): SoakRequest => $schedule[$i % count($schedule)](self::invocationId());
    }

    /**
     * `$count` for a class with a cheap body, fewer for a large one.
     *
     * @param \Closure(string): SoakRequest $make
     */
    private static function share(\Closure $make, int $count): int
    {
        $bytes = strlen($make(Workload::INVOCATION_ID)->request->body);

        return max(1, (int) ($count * min(1.0, self::CHEAP_BODY / max(1, $bytes))));
    }

    /** @return array{rust: float, php: float, rss: ?float} */
    private static function maxSlopes(): array
    {
        return SoakSettings::quick() ? ['rss' => null] + self::MAX_SLOPES : self::MAX_SLOPES;
    }

    /** @param array<string, string> $environment */
    private static function endpoint(SoakIdentity $identity, array $environment = []): SoakEndpoint
    {
        return SoakEndpoint::start(SoakSettings::releaseImage(), 1, $environment + [
            'RESTATE_PHP_TEST_IDENTITY_KEYS' => $identity->identityKey,
            // The SDK logs every request through the JSON-lines logger (shared-core's events at info), to nowhere.
            IntegrationEndpoints::LOGGER_VARIABLE => '/dev/null',
            'RESTATE_PHP_TEST_DISPLAY_ERRORS' => '0',
            // PHP reads the body itself too (into a temporary file above post_max_size): the FD count must not grow.
            'RESTATE_PHP_TEST_POST_DATA_READING' => '1',
        ]);
    }

    private static function invocationId(): string
    {
        return 'inv_1workload' . bin2hex(random_bytes(8));
    }
}
