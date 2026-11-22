<?php

declare(strict_types=1);

namespace Restate\Tests\Extension;

use Restate\Context;
use Restate\Endpoint;
use Restate\EndpointResponse;
use Restate\Extension\ExtensionException;
use Restate\Extension\Vm;
use Restate\Internal\Backend as BackendContract;
use Restate\Internal\Backend\Extension\Backend;
use Restate\Internal\Backend\Extension\Invocation;
use Restate\Internal\Backend\IdentityVerifier;
use Restate\Internal\Backend\Invocation as InvocationContract;
use Restate\Internal\CoreLogForwarder;
use Restate\Service;
use Restate\TerminalError;
use Restate\Tests\Support\Protocol\FrameCodec;
use Restate\Tests\Support\RecordingLogger;

/**
 * In-process VM lifetime: an endpoint on the extension serves 10,000 requests of every outcome in one process,
 * as a long-lived worker does, and no `Restate\Extension\Vm` outlives its request, even under a logger that keeps its
 * records (and the exceptions in them), as Monolog's buffer and fingers-crossed handlers or Sentry breadcrumbs do.
 *
 * Every attempt that fails through the extension throws an `ExtensionException`. With `zend.exception_ignore_args=Off`
 * (PHP's compiled default) its trace records the arguments of every frame, and the BackendException that chains it is
 * logged with the record. A Vm passed as an argument would therefore stay alive with the record, and with it the whole
 * Rust VM: journal, buffers and log capture.
 */
final class VmLifetimeTest extends ExtensionTestCase
{
    private const int REQUESTS = 10_000;

    /** Requests before the memory baseline: the logger's window fills and PHP's caches warm up. */
    private const int WARM_UP = 1_000;

    /** How many records the logger keeps: the newest, like a bounded buffer handler. */
    private const int RETAINED_RECORDS = 256;

    /** One request in this many trips the panic probe (each panic prints a few lines to stderr). */
    private const int PANIC_EVERY = 250;

    /**
     * How much `memory_get_usage()` may grow between the baseline and the last request, after `gc_collect_cycles()`.
     *
     * The logger's window is full by the baseline, so nothing the test keeps grows; measured growth is 0. 16 KiB over
     * 9,000 requests is under 2 bytes per request: a leak of one PHP value per request (an empty array or object is 56
     * bytes or more) would add about 500 KiB, and one per failed request still over 100 KiB. The slack is for a cache
     * PHP or a dependency fills once, late. The Rust heap is invisible here; the live-Vm count covers it.
     */
    private const int MEMORY_TOLERANCE = 16 * 1024;

    private const string PANIC_PROBE = '__restate_php_panic_probe__';

    private string $exceptionIgnoreArgs;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        // php.ini-production turns this on, which would hide frame arguments; the compiled default is off.
        $this->exceptionIgnoreArgs = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    }

    #[\Override]
    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->exceptionIgnoreArgs);
    }

    public function testNoVmOutlivesItsRequestUnderARecordKeepingLogger(): void
    {
        $vms = new class (new Backend()) implements BackendContract {
            /** @var list<\WeakReference<Vm>> the Vms created since the last take() */
            private array $created = [];

            public function __construct(private readonly Backend $backend)
            {
            }

            public function protocolVersions(): array
            {
                return $this->backend->protocolVersions();
            }

            public function newInvocation(array $headers, ?CoreLogForwarder $coreLogs = null): InvocationContract
            {
                $invocation = $this->backend->newInvocation($headers, $coreLogs);
                \assert($invocation instanceof Invocation);
                $vm = new \ReflectionProperty(Invocation::class, 'vm')->getValue($invocation);
                \assert($vm instanceof Vm);
                $this->created[] = \WeakReference::create($vm);

                return $invocation;
            }

            public function identityVerifier(array $identityKeys): IdentityVerifier
            {
                return $this->backend->identityVerifier($identityKeys);
            }

            /** @return list<\WeakReference<Vm>> */
            public function take(): array
            {
                [$created, $this->created] = [$this->created, []];

                return $created;
            }
        };
        $logger = new RecordingLogger();
        $endpoint = Endpoint::create($vms, [self::service()], logger: $logger);

        $baseline = $final = null;
        $outcomes = [];
        for ($i = 0; $i < self::REQUESTS; ++$i) {
            [$outcome, $handler, $body] = self::request($i);
            $response = $endpoint->handleHttpRequest("/invoke/Greeter/{$handler}", [['content-type', self::V5]], $body);
            self::assertResponse($outcome, $response, $i);
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;

            $created = $vms->take();
            self::assertCount(1, $created, "request {$i} ({$outcome})");
            self::assertNull($created[0]->get(), "request {$i} ({$outcome}): its Vm is still alive after the response");

            $logger->records = array_slice($logger->records, -self::RETAINED_RECORDS);

            // Both readings at the same point of the loop, in scalars: nothing the test allocates differs between them.
            if ($i + 1 === self::WARM_UP) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            } elseif ($i + 1 === self::REQUESTS) {
                gc_collect_cycles();
                $final = memory_get_usage();
            }
        }

        // The window kept failures, with their exceptions: the case that used to keep Vms alive.
        self::assertNotSame([], array_filter($logger->records, static fn (array $record): bool => ($record[2]['exception'] ?? null) instanceof \Throwable));
        self::assertSame(['success' => 4_000, 'suspension' => 2_000, 'failure' => 2_000, 'terminal' => 1_960, 'panic' => 40], $outcomes);

        self::assertIsInt($baseline);
        self::assertIsInt($final);
        self::assertLessThan(self::MEMORY_TOLERANCE, $final - $baseline, sprintf('memory grew by %d bytes over %d requests', $final - $baseline, self::REQUESTS - self::WARM_UP));
    }

    /** Whoever still holds the PHP object after the invocation was freed holds an empty shell, not the Rust VM. */
    public function testFreeingTheInvocationReleasesTheRustVmEvenWhileThePhpObjectLives(): void
    {
        $invocation = self::running(coreLogs: new CoreLogForwarder(new RecordingLogger(), 'debug'));
        $vm = new \ReflectionProperty(Invocation::class, 'vm')->getValue($invocation);
        self::assertInstanceOf(Vm::class, $vm);

        $invocation->free();

        self::assertFreed($vm);
    }

    public function testAFreedVmRefusesEveryCallAndFreesAgainQuietly(): void
    {
        $vm = self::runningVm(logLevel: 'debug');

        $vm->free();
        $vm->free();

        self::assertFreed($vm);
    }

    public function testAPoisonedVmCanBeFreed(): void
    {
        $vm = self::runningVm();
        try {
            $vm->sysRun(self::PANIC_PROBE);
            self::fail('Expected the panic probe to trip.');
        } catch (ExtensionException $e) {
            self::assertSame('Rust panic: restate panic probe', $e->getMessage());
        }

        $vm->free();

        self::assertFreed($vm);
    }

    private static function assertFreed(Vm $vm): void
    {
        foreach ([static fn () => $vm->responseHead(), static fn () => $vm->takeLogs(), static fn () => $vm->sysSleep(1)] as $call) {
            try {
                $call();
                self::fail('Expected a freed Vm to refuse the call.');
            } catch (ExtensionException $e) {
                self::assertSame(['internal', 'Restate VM has already been freed.'], [$e->category, $e->getMessage()]);
            }
        }
    }

    private static function service(): Service
    {
        return Service::new('Greeter')
            ->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}")
            ->handler('sleep', static function (Context $ctx, string $_): string {
                $ctx->sleep(60_000);

                return 'unreachable';
            })
            ->handler('step', static fn (Context $ctx, string $_): string => $ctx->run('step', static fn (): string => 'done'))
            ->handler('decline', static fn (Context $ctx, string $_): never => throw new TerminalError('declined', 402))
            ->handler('panic', static fn (Context $ctx, string $_): string => $ctx->run(self::PANIC_PROBE, static fn (): string => 'unreachable'));
    }

    /**
     * The outcome, handler and body of request $i. Per ten requests: four complete, two suspend, two fail through the
     * extension (a journal mismatch) and two fail terminally; every PANIC_EVERY-th request trips the panic probe instead.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function request(int $i): array
    {
        $id = sprintf('inv-%05d', $i); // one length throughout, so the memory baseline compares like with like
        $started = FrameCodec::startFrame($id, 1) . FrameCodec::inputCommandFrame('"Mary"');
        if ($i % self::PANIC_EVERY === self::PANIC_EVERY - 1) {
            return ['panic', 'panic', $started];
        }

        return match ($i % 10) {
            0, 1, 2, 3 => ['success', 'greet', $started],
            4, 5 => ['suspension', 'sleep', $started],
            // The journal recorded a sleep; the handler runs a step instead.
            6, 7 => ['failure', 'step', FrameCodec::startFrame($id, 3) . FrameCodec::inputCommandFrame('"Mary"') . FrameCodec::sleepCommandFrame(1, 1) . FrameCodec::completionFrame(FrameCodec::SLEEP_COMPLETION, 1)],
            default => ['terminal', 'decline', $started],
        };
    }

    private static function assertResponse(string $outcome, EndpointResponse $response, int $i): void
    {
        if ($outcome === 'panic') {
            // The poisoned VM refuses to report the failure, so the endpoint answers with its own error.
            self::assertSame(500, $response->statusCode, "request {$i}");

            return;
        }

        self::assertSame(200, $response->statusCode, "request {$i} ({$outcome}): {$response->body}");
        self::assertSame(match ($outcome) {
            'success', 'terminal' => [FrameCodec::OUTPUT_COMMAND, FrameCodec::END],
            'suspension' => [FrameCodec::SLEEP_COMMAND, FrameCodec::SUSPENSION],
            default => [FrameCodec::ERROR],
        }, FrameCodec::messageTypes($response->body), "request {$i} ({$outcome})");
    }
}
