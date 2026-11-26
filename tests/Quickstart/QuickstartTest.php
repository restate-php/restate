<?php

declare(strict_types=1);

namespace Restate\Tests\Quickstart;

use PHPUnit\Framework\TestCase;
use Restate\Testing\Internal\Process;
use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\HttpResponse;
use Restate\Tests\Integration\Support\HttpTransportException;

/**
 * The Docker quickstart (examples/quickstart), the way its README walks a user through it: Docker Compose starts
 * Restate, the PHP service and the registration, then each test is a step of the walkthrough, in the README's
 * order, against the ports the README calls. Each step first checks that the README still shows the commands it
 * runs, so the walkthrough and this test change together.
 *
 * RESTATE_PHP_QUICKSTART chooses what the PHP image installs: `local` (compose.local.yaml, the extension and the
 * packages of this checkout, from the image `just test quickstart local` builds) or `released` (PIE and Packagist,
 * as a user gets them). The Compose project has a name of its own, so a quickstart you run yourself keeps its
 * state; its ports are the same, so stop it first. `just test quickstart [local|released]`.
 */
final class QuickstartTest extends TestCase
{
    private const string PROJECT = 'restate-php-quickstart-test';

    private const string INGRESS = 'http://localhost:8080';

    private const string ADMIN = 'http://localhost:9070';

    /** Restate retries with backoff, and the greeting sleeps five seconds: a call can take a while. */
    private const float CALL_TIMEOUT = 120.0;

    private static ?string $readme = null;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::compose(['down', '--volumes', '--remove-orphans']);
        self::compose(['up', '--build', '--detach'], check: true);

        // register runs once Restate and the PHP service are healthy, and exits.
        $state = '';
        self::eventually(static function () use (&$state): bool {
            [, $state] = self::compose(['ps', '--all', '--format', '{{.State}} {{.ExitCode}}', 'register']);
            $state = trim($state);
            if (str_starts_with($state, 'exited') && $state !== 'exited 0') {
                self::fail("register exited: {$state}\n" . self::logs());
            }

            return $state === 'exited 0';
        }, 180.0, static fn (): string => "register did not finish: {$state}");
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::compose(['down', '--volumes', '--remove-orphans']);
    }

    /** Step 1: `docker compose up` registered the three services, and `docker compose logs register` shows it. */
    public function testRegistersTheThreeServices(): void
    {
        self::assertReadmeShows('docker compose up --build -d');
        self::assertReadmeShows('docker compose ps --all');
        self::assertReadmeShows('docker compose logs register');

        $services = HttpClient::get(self::ADMIN . '/services');
        self::assertTrue($services->isSuccessful(), $services->body);
        $names = array_column(self::decode($services->body)['services'] ?? [], 'name');
        sort($names);
        self::assertSame(['Counter', 'Greeter', 'Signup'], $names);

        self::assertStringContainsString('"name":"Greeter"', self::compose(['logs', '--no-log-prefix', 'register'], check: true)[1]);
    }

    /** Step 3. The first call can meet a server that is still starting its partitions, so it is retried a while. */
    public function testGreetsSarah(): void
    {
        self::assertReadmeShows(<<<'SH'
            curl localhost:8080/Greeter/greet --json '{"name": "Sarah"}'
            SH);

        $last = 'no response';
        self::eventually(static function () use (&$last): bool {
            try {
                $response = self::greet('Sarah');
            } catch (HttpTransportException $e) {
                $last = $e->getMessage();

                return false;
            }
            $last = $response->body;

            return $response->isSuccessful();
        }, 30.0, static fn (): string => "greet Sarah: {$last}");

        self::assertSame('{"message":"You said hi to Sarah!"}', $last);
    }

    /** Step 5: the notification fails 7 times out of 10 for Alice, and Restate retries it until it is sent. */
    public function testRetriesAlicesNotificationUntilItIsSent(): void
    {
        self::assertReadmeShows(<<<'SH'
            curl localhost:8080/Greeter/greet --json '{"name": "Alice"}'
            SH);

        self::assertSame('{"message":"You said hi to Alice!"}', self::greet('Alice')->body);
    }

    /** Step 6: kill the PHP service while the greeting sleeps; Restate finishes it, with one notification. */
    public function testFinishesAGreetingThePhpServiceWasKilledIn(): void
    {
        self::assertReadmeShows(<<<'SH'
            curl localhost:8080/Greeter/greet/send --json '{"name": "Mary"}'
            docker compose kill greeter
            SH);
        self::assertReadmeShows('docker compose exec restate restate invocations describe inv_');
        self::assertReadmeShows(<<<'SH'
            docker compose start greeter
            curl localhost:8080/restate/invocation/inv_
            SH);
        self::assertReadmeShows('docker compose logs greeter | grep inv_');

        $sent = HttpClient::postJson(self::INGRESS . '/Greeter/greet/send', '{"name": "Mary"}');
        self::assertTrue($sent->isSuccessful(), $sent->body);
        $id = self::decode($sent->body)['invocationId'] ?? null;
        self::assertIsString($id, $sent->body);

        // What the README's reader does by pasting both lines at once: the kill comes during the sleep.
        self::eventually(
            static fn (): bool => self::countInGreeterLog($id, 'Notification sent') === 1,
            10.0,
            static fn (): string => "no notification for {$id}",
        );
        self::compose(['kill', 'greeter'], check: true);

        $description = '';
        self::eventually(static function () use ($id, &$description): bool {
            [, $description] = self::compose(['exec', '-T', 'restate', 'restate', 'invocations', 'describe', $id]);

            return str_contains($description, 'backing-off');
        }, 30.0, static fn (): string => "Restate does not show {$id} backing off:\n{$description}");

        self::compose(['start', 'greeter'], check: true);
        $result = HttpClient::get(self::INGRESS . "/restate/invocation/{$id}/attach", timeout: self::CALL_TIMEOUT);
        self::assertSame('{"message":"You said hi to Mary!"}', $result->body);

        self::assertSame(1, self::countInGreeterLog($id, 'Notification sent'));
        self::assertSame(1, self::countInGreeterLog($id, 'Reminder sent'));
    }

    /** Step 7: a Virtual Object's state, per key. */
    public function testCountsPerKey(): void
    {
        self::assertReadmeShows('curl localhost:8080/Counter/mary/add --json 1');
        self::assertReadmeShows('curl localhost:8080/Counter/mary/add --json 2');
        self::assertReadmeShows('curl -X POST localhost:8080/Counter/mary/get');

        self::assertSame('1', HttpClient::postJson(self::INGRESS . '/Counter/mary/add', '1')->body);
        self::assertSame('3', HttpClient::postJson(self::INGRESS . '/Counter/mary/add', '2')->body);
        self::assertSame('3', HttpClient::postJson(self::INGRESS . '/Counter/mary/get', null)->body);
    }

    /** Step 8: a Workflow waits for the click with the secret its log shows. */
    public function testWaitsForTheSignupClick(): void
    {
        self::assertReadmeShows(<<<'SH'
            curl localhost:8080/Signup/mary/run/send --json '"mary@example.com"'
            docker compose logs greeter | grep "Verification secret"
            SH);
        self::assertReadmeShows('curl localhost:8080/Signup/mary/click --json \'"');
        self::assertReadmeShows('curl localhost:8080/restate/workflow/Signup/mary/attach');

        $sent = HttpClient::postJson(self::INGRESS . '/Signup/mary/run/send', '"mary@example.com"');
        self::assertTrue($sent->isSuccessful(), $sent->body);

        $secret = null;
        self::eventually(static function () use (&$secret): bool {
            $found = preg_match_all('/Verification secret for mary@example\.com: ([0-9a-f-]+)/', self::greeterLog(), $matches);
            $secret = $found > 0 ? end($matches[1]) : null;

            return $secret !== null;
        }, 30.0, static fn (): string => 'no verification secret in the log');

        $clicked = HttpClient::postJson(self::INGRESS . '/Signup/mary/click', (string) json_encode($secret));
        self::assertTrue($clicked->isSuccessful(), $clicked->body);
        $result = HttpClient::get(self::INGRESS . '/restate/workflow/Signup/mary/attach', timeout: self::CALL_TIMEOUT);
        self::assertSame('"Welcome, mary@example.com!"', $result->body);
    }

    #[\Override]
    protected function onNotSuccessfulTest(\Throwable $t): never
    {
        fwrite(STDERR, "\n{$this->name()}: the Compose project's logs\n" . self::logs() . "\n");

        throw $t;
    }

    private static function greet(string $name): HttpResponse
    {
        return HttpClient::postJson(self::INGRESS . '/Greeter/greet', (string) json_encode(['name' => $name]), timeout: self::CALL_TIMEOUT);
    }

    /** The README shows the command (or the start of one, before an invocation id the reader copies). */
    private static function assertReadmeShows(string $command): void
    {
        self::$readme ??= (string) file_get_contents(self::directory() . '/README.md');
        self::assertStringContainsString($command, self::$readme, 'examples/quickstart/README.md no longer shows the command this step runs: change the step with the walkthrough');
    }

    private static function countInGreeterLog(string $invocationId, string $message): int
    {
        $count = 0;
        foreach (explode("\n", self::greeterLog()) as $line) {
            if (str_contains($line, $invocationId) && str_contains($line, $message)) {
                ++$count;
            }
        }

        return $count;
    }

    private static function greeterLog(): string
    {
        return self::compose(['logs', '--no-log-prefix', 'greeter'], check: true)[1];
    }

    private static function logs(): string
    {
        [, $stdout, $stderr] = self::compose(['logs', '--no-color', '--tail', '200']);

        return $stdout . $stderr;
    }

    /**
     * `docker compose` in examples/quickstart, on this test's project.
     *
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private static function compose(array $arguments, bool $check = false): array
    {
        $source = getenv('RESTATE_PHP_QUICKSTART');
        $files = match ($source === false || $source === '' ? 'local' : $source) {
            'local' => 'compose.yaml:compose.local.yaml',
            'released' => 'compose.yaml',
            default => throw new \RuntimeException('RESTATE_PHP_QUICKSTART is local or released'),
        };
        $result = Process::run(
            ['docker', 'compose', ...$arguments],
            ['COMPOSE_FILE' => $files, 'COMPOSE_PROJECT_NAME' => self::PROJECT],
            self::directory(),
        );
        if ($check && $result[0] !== 0) {
            self::fail('docker compose ' . implode(' ', $arguments) . " exited {$result[0]}:\n{$result[2]}");
        }

        return $result;
    }

    /**
     * Poll until `$done` returns true, or fail with `$message` after `$timeout` seconds.
     *
     * @param \Closure(): bool $done
     * @param \Closure(): string $message
     */
    private static function eventually(\Closure $done, float $timeout, \Closure $message): void
    {
        $deadline = microtime(true) + $timeout;
        while (!$done()) {
            if (microtime(true) > $deadline) {
                self::fail($message());
            }
            usleep(500_000);
        }
    }

    /** @return array<array-key, mixed> */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $json);

        return $decoded;
    }

    private static function directory(): string
    {
        return dirname(__DIR__, 2) . '/examples/quickstart';
    }
}
