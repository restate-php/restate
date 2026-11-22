<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Support;

use Psr\Log\LogLevel;
use Restate\Endpoint;
use Restate\Request;
use Restate\ServiceDefinition;
use Restate\TerminalError;
use Restate\Tests\Integration\Services\FactoryBoundProbe;
use Restate\Tests\Integration\Services\InsufficientFunds;
use Restate\Tests\Integration\Services\TenantGreeter;
use Restate\Tests\Support\Faults\FaultInjector;
use Restate\Tests\Support\Repository;

/**
 * The integration suite's endpoints and how a request reaches them, shared by every script that serves them: the
 * classic router (endpoint.php, which builds them for each request) and a long-lived worker loop (which builds them
 * once and serves many requests):
 *
 * ```php
 * $endpoints = new IntegrationEndpoints();
 * $endpoints->serve($_SERVER, (string) file_get_contents('php://input'));
 * ```
 *
 * - The main endpoint serves services.php, with the configuration the tests rely on (identity keys, the JSON-lines
 *   logger, a factory-bound service, an error mapper).
 * - Under `/state-preload`, a second endpoint that StatePreloadTest registers itself.
 * - Every request goes through the {@see FaultInjector}, which does nothing unless RESTATE_PHP_TEST_FAULTS=1.
 */
final class IntegrationEndpoints
{
    private const string STATE_PRELOAD_PATH = '/state-preload';

    /**
     * `off`: the main endpoint has no logger (and so captures no shared-core events); a path: the JSON-lines logger
     * writes there (`/dev/null` for a soak run that must not fill the disk) instead of the state directory.
     */
    public const string LOGGER_VARIABLE = 'RESTATE_PHP_TEST_ENDPOINT_LOGGER';

    /** The PSR-3 level from which the main endpoint forwards shared-core's events (default `info`). */
    public const string CORE_LOG_LEVEL_VARIABLE = 'RESTATE_PHP_TEST_CORE_LOG_LEVEL';

    private ?Endpoint $main = null;

    private ?Endpoint $statePreload = null;

    private readonly FaultInjector $faults;

    public function __construct(?FaultInjector $faults = null)
    {
        $this->faults = $faults ?? FaultInjector::fromEnvironment();
    }

    /**
     * The main endpoint's services.
     *
     * @return list<ServiceDefinition>
     */
    public static function services(): array
    {
        /** @var list<ServiceDefinition> */
        return require __DIR__ . '/services.php';
    }

    /** @param array<string, mixed> $server `$_SERVER` of the request */
    public function serve(array $server, string $body): void
    {
        $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';

        // StatePreloadTest: its key list needs discovery manifest v5, which Restate 1.7 does not read, so it must stay
        // out of the endpoint every test relies on.
        if ($uri === self::STATE_PRELOAD_PATH || str_starts_with($uri, self::STATE_PRELOAD_PATH . '/')) {
            $path = substr($uri, strlen(self::STATE_PRELOAD_PATH));
            $this->faults->serve($this->statePreload(), ['REQUEST_URI' => $path === '' ? '/' : $path] + $server, $body);

            return;
        }

        $this->faults->serve($this->main(), $server, $body);
    }

    public function main(): Endpoint
    {
        return $this->main ??= self::buildMain();
    }

    public function statePreload(): Endpoint
    {
        return $this->statePreload ??= Endpoint::builder()
            ->services([require Repository::ROOT . '/tests/Integration/Services/state_preload.php'])
            ->build();
    }

    private static function buildMain(): Endpoint
    {
        // LoggingTest: what the SDK logs, one JSON object per line, in the directory the tests share with the endpoint.
        $stateDir = getenv('RESTATE_PHP_TEST_STATE_DIR');
        $logFile = (is_string($stateDir) && $stateDir !== '' ? $stateDir : Repository::ROOT . '/var') . '/endpoint-log.jsonl';

        // RequestIdentityTest: identity keys (comma-separated), for an environment whose Restate signs its requests.
        // Only configured when set: an empty key list is rejected (identity verification fails closed).
        $identityKeys = getenv('RESTATE_PHP_TEST_IDENTITY_KEYS');

        $builder = Endpoint::builder();
        if (is_string($identityKeys) && $identityKeys !== '') {
            $builder = $builder->identityKeys(explode(',', $identityKeys));
        }

        // The soak suite (tests/Soak) turns the logger off, or captures shared-core's events from another level.
        $coreLogLevel = getenv(self::CORE_LOG_LEVEL_VARIABLE);
        $logger = getenv(self::LOGGER_VARIABLE);
        if (is_string($logger) && str_starts_with($logger, '/')) {
            $logFile = $logger;
        }
        if ($logger !== 'off') {
            $builder = $builder->logger(new JsonLinesLogger($logFile), is_string($coreLogLevel) && $coreLogLevel !== '' ? $coreLogLevel : LogLevel::INFO);
        }

        return $builder
            ->services(self::services())
            // HandlerFactoryTest: a service class instantiated per invocation attempt, from the request.
            ->bind(TenantGreeter::class, static fn (Request $request): TenantGreeter => new TenantGreeter($request->header('x-tenant') ?? 'none'))
            // WorkerIsolationTest: a fresh instance per attempt, also in a long-lived worker.
            ->bind(FactoryBoundProbe::class, static fn (): FactoryBoundProbe => new FactoryBoundProbe())
            // HooksTest: a domain exception becomes a terminal 422 instead of being retried.
            ->errorMapper(static fn (\Throwable $e): ?TerminalError => $e instanceof InsufficientFunds
                ? new TerminalError($e->getMessage(), 422, ['account' => $e->account], $e)
                : null)
            ->build();
    }
}
