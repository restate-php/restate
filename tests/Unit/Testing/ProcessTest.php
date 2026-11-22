<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Testing\Internal\Process;

/**
 * The harness's process handling, on real child processes: what `killEndpoint()`, `signalEndpoint()` and a graceful
 * restart of a `php -S` endpoint rely on.
 */
final class ProcessTest extends TestCase
{
    private string $log = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'restate-php-process-');
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->log);
    }

    public function testASignalEndsTheProcessAndWaitReportsIt(): void
    {
        $process = Process::start([PHP_BINARY, '-r', 'sleep(30);'], $this->log);
        self::assertTrue($process->isRunning());
        self::assertIsInt($process->pid());
        self::assertFalse($process->wait(0.1), 'The process ended on its own.');

        $process->signal(15);

        self::assertTrue($process->wait(5.0));
        self::assertFalse($process->isRunning());
        // Signalling a process that has exited does nothing.
        $process->signal(15);
        $process->stop();
        self::assertNull($process->pid());
    }

    /** @return iterable<string, array{'kill'|'stop'|'signal'}> */
    public static function endings(): iterable
    {
        yield 'kill' => ['kill'];
        yield 'stop' => ['stop'];
        yield 'a signal that ends it' => ['signal'];
    }

    /**
     * On Linux the workers die with their server (php -S sets PR_SET_PDEATHSIG on them); on macOS they would outlive
     * it and keep the port, unless the harness ends them too.
     *
     * @param 'kill'|'stop'|'signal' $ending
     */
    #[DataProvider('endings')]
    public function testEndingTheBuiltInServerTakesItsWorkersWithIt(string $ending): void
    {
        $port = self::freePort();
        $router = tempnam(sys_get_temp_dir(), 'restate-php-router-') . '.php';
        file_put_contents($router, '<?php echo getmypid();');

        try {
            $server = Process::start([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], $this->log, ['PHP_CLI_SERVER_WORKERS' => '3']);
            $pid = (int) $server->pid();
            $deadline = microtime(true) + 5;
            while (count(Process::childrenOf($pid)) < 3 && microtime(true) < $deadline) {
                usleep(20_000);
            }
            $workers = Process::childrenOf($pid);
            self::assertCount(3, $workers, (string) file_get_contents($this->log));

            match ($ending) {
                'kill' => $server->kill(),
                'stop' => $server->stop(),
                'signal' => $server->signal(15),
            };

            self::assertFalse($server->isRunning());
            foreach ($workers as $worker) {
                // A signal returns without waiting for the workers; they have SIGTERM by then.
                for ($gone = microtime(true) + 5; Process::isAlive($worker) && microtime(true) < $gone;) {
                    usleep(20_000);
                }
                self::assertFalse(Process::isAlive($worker), "Worker {$worker} outlived its server.");
            }
            // The port is free again at once, for an endpoint started again on it.
            $socket = @stream_socket_server("tcp://127.0.0.1:{$port}");
            self::assertNotFalse($socket, "Port {$port} is still taken.");
            fclose($socket);
        } finally {
            @unlink($router);
        }
    }

    /** What pauseRestate() and unpauseRestate() send a Restate process; the numbers differ between Linux and macOS. */
    public function testStopAndContinueSignalsPauseAndResumeAProcess(): void
    {
        $process = Process::start([PHP_BINARY, '-r', 'while (true) { usleep(10_000); }'], $this->log);
        $pid = (int) $process->pid();

        try {
            $process->signal(Process::signalNumber('STOP'));
            self::assertTrue(self::waitForState($pid, 'T'), 'The process was not stopped.');
            self::assertTrue($process->isRunning(), 'A stopped process counts as running.');

            $process->signal(Process::signalNumber('CONT'));
            self::assertTrue(self::waitForState($pid, 'R', 'S'), 'The process did not continue.');
        } finally {
            $process->kill();
        }
        self::assertFalse(Process::isAlive($pid));
    }
    public function testChildrenOfAProcessWithoutChildrenIsEmpty(): void
    {
        self::assertSame([], Process::childrenOf(PHP_INT_MAX));
    }

    /** Whether `ps` reports one of these states (its first letter) for the process within 5 seconds. */
    private static function waitForState(int $pid, string ...$states): bool
    {
        $deadline = microtime(true) + 5;
        do {
            [, $stdout] = Process::run(['ps', '-o', 'stat=', '-p', (string) $pid]);
            if (in_array(substr(trim($stdout), 0, 1), $states, true)) {
                return true;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
