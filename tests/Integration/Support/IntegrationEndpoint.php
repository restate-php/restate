<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Support;

use Restate\Internal\Backend\Extension\Backend as ExtensionBackend;
use Restate\Tests\Support\Faults\FaultInjector;
use Restate\Tests\Support\Repository;
use Restate\Testing\EndpointContainer;
use Restate\Testing\ExternalEndpoint;
use Restate\Testing\Internal\Process;
use Restate\Testing\PhpCliServer;

/**
 * The integration suite's endpoint, run by the public test harness (`Restate\Testing\RestateTestEnvironment`, shared
 * by every integration test): `endpoint.php` serving the test services, under `php -S` on the host, in a FrankenPHP
 * container with the thread-safe extension, or in a PHP-FPM container behind nginx or Caddy; or `worker.php` in
 * FrankenPHP's worker mode. Or an endpoint that runs outside the harness, which it only connects to: with
 * RESTATE_PHP_TEST_RESTATE=external ({@see IntegrationRestate}), the one a devenv.nix profile serves with `devenv up`.
 *
 * Configuration (environment variables) on top of the harness's own (`RESTATE_PHP_RESTATE_IMAGE`,
 * `RESTATE_PHP_EXTENSION_PATH`, `RESTATE_PHP_TEST_BIND`):
 * - RESTATE_PHP_TEST_SERVER            `php-cli` (default), `frankenphp` (classic mode: endpoint.php per request),
 *                                      `frankenphp-worker` (worker mode: worker.php builds the endpoints once per
 *                                      worker; {@see self::WORKER_CADDYFILE}), `nginx-fpm` (php-fpm behind nginx,
 *                                      HTTP/1.1), `nginx-fpm-h2c` (the same, registered for HTTP/2: h2c) or
 *                                      `caddy-fpm` (php-fpm behind Caddy php_fastcgi, h2c).
 * - RESTATE_PHP_TEST_FRANKENPHP_IMAGE  FrankenPHP endpoint image; default {@see self::DEFAULT_FRANKENPHP_IMAGE}
 *                                      (built by just test integration frankenphp).
 * - RESTATE_PHP_TEST_FPM_IMAGE         PHP-FPM endpoint image; default {@see self::DEFAULT_FPM_IMAGE} (built by
 *                                      just test integration nginx-fpm, nginx-fpm-h2c or caddy-fpm).
 *
 * With RESTATE_PHP_TEST_RESTATE=external, which devenv.nix's profiles export with the endpoint they serve:
 * - RESTATE_PHP_TEST_ENDPOINT_URL      the endpoint, as the tests reach it and Restate does.
 * - RESTATE_PHP_TEST_ENDPOINT_SERVER   what serves it, one of the RESTATE_PHP_TEST_SERVER values (and
 *                                      RESTATE_PHP_TEST_SERVER, if set, must be the same).
 * - RESTATE_PHP_TEST_STATE_DIR         the scratch directory the endpoint shares with the tests ({@see stateDir()}).
 * - RESTATE_PHP_TEST_ENDPOINT_LOG      the endpoint's log file, if it has one ({@see ExternalEndpoint::withLogFile()}).
 */
final class IntegrationEndpoint
{
    public const string DEFAULT_FRANKENPHP_IMAGE = 'restate.local/restate-php-frankenphp:extension-zts';

    public const string DEFAULT_FPM_IMAGE = 'restate.local/restate-php-fpm:extension';

    /** The values of RESTATE_PHP_TEST_SERVER that run php-fpm, and the front door each starts (RESTATE_PHP_FPM_FRONT). */
    public const array FPM_SERVERS = ['nginx-fpm' => 'nginx', 'nginx-fpm-h2c' => 'nginx-h2c', 'caddy-fpm' => 'caddy'];

    /** Where the endpoint images expect the repository. */
    private const string APP_DIR = '/app';

    /** The port the endpoint images serve on. */
    private const int CONTAINER_PORT = 8080;

    /** The worker-mode Caddyfile, from the mounted repository (so changing it needs no image rebuild). */
    public const string WORKER_CADDYFILE = self::APP_DIR . '/docker/frankenphp/worker.Caddyfile';

    /** Worker threads of the worker-mode endpoint (worker.Caddyfile `num`, default 4). */
    public const string WORKER_THREADS_VARIABLE = 'RESTATE_PHP_TEST_WORKER_NUM';

    public const string EXTERNAL_URL_VARIABLE = 'RESTATE_PHP_TEST_ENDPOINT_URL';

    public const string EXTERNAL_SERVER_VARIABLE = 'RESTATE_PHP_TEST_ENDPOINT_SERVER';

    public const string EXTERNAL_LOG_VARIABLE = 'RESTATE_PHP_TEST_ENDPOINT_LOG';

    public const string STATE_DIR_VARIABLE = 'RESTATE_PHP_TEST_STATE_DIR';

    /** The servers that Restate reaches over h2c (registered for HTTP/2). */
    private const array HTTP2_SERVERS = ['nginx-fpm-h2c', 'caddy-fpm'];

    private static PhpCliServer|EndpointContainer|ExternalEndpoint|null $endpoint = null;

    private static ?string $stateDir = null;

    private static ?\Throwable $failure = null;

    /** @var array<string, true> images whose extension backend loads */
    private static array $preflighted = [];

    public static function get(): PhpCliServer|EndpointContainer|ExternalEndpoint
    {
        if (self::$endpoint !== null) {
            return self::$endpoint;
        }
        if (self::$failure !== null) {
            throw new \RuntimeException(self::$failure->getMessage(), 0, self::$failure);
        }

        $server = self::server();
        if (IntegrationRestate::isExternal()) {
            return self::$endpoint = self::external($server);
        }

        return self::$endpoint = match (true) {
            $server === 'php-cli' => self::phpCli(),
            $server === 'frankenphp' => self::remember(static fn (): EndpointContainer => self::frankenPhp(self::frankenPhpImage())),
            $server === 'frankenphp-worker' => self::remember(static fn (): EndpointContainer => self::frankenPhp(self::frankenPhpImage())
                ->withCommand(['frankenphp', 'run', '--config', self::WORKER_CADDYFILE])),
            isset(self::FPM_SERVERS[$server]) => self::remember(static function () use ($server): EndpointContainer {
                $endpoint = self::fpm(self::FPM_SERVERS[$server]);

                // Restate talks h2c to a deployment registered for HTTP/2; nginx (http2 on) and Caddy (protocols h1 h2c)
                // serve the harness's HTTP/1.1 health checks on the same port.
                return in_array($server, self::HTTP2_SERVERS, true) ? $endpoint->withHttp2() : $endpoint;
            }),
            default => throw new \RuntimeException(sprintf(
                "RESTATE_PHP_TEST_SERVER must be 'php-cli', 'frankenphp', 'frankenphp-worker' or one of '%s', got '{$server}'.",
                implode("', '", array_keys(self::FPM_SERVERS)),
            )),
        };
    }

    /**
     * The RESTATE_PHP_TEST_SERVER the suite runs against; with an external endpoint, what serves it
     * (RESTATE_PHP_TEST_ENDPOINT_SERVER).
     */
    public static function server(): string
    {
        $configured = self::env('RESTATE_PHP_TEST_SERVER');
        if (!IntegrationRestate::isExternal()) {
            return $configured ?? 'php-cli';
        }

        $external = self::env(self::EXTERNAL_SERVER_VARIABLE) ?? throw new \RuntimeException(sprintf(
            '%s is not set: with RESTATE_PHP_TEST_RESTATE=external it names what serves the external endpoint (a devenv.nix profile exports it).',
            self::EXTERNAL_SERVER_VARIABLE,
        ));
        if ($configured !== null && $configured !== $external) {
            throw new \RuntimeException(sprintf('RESTATE_PHP_TEST_SERVER is %s, but the external endpoint is served by %s (%s).', $configured, $external, self::EXTERNAL_SERVER_VARIABLE));
        }

        return $external;
    }

    /** Whether the endpoint runs outside the harness (RESTATE_PHP_TEST_RESTATE=external). */
    public static function isExternal(): bool
    {
        return IntegrationRestate::isExternal();
    }

    /**
     * The endpoint the harness serves, for a test that configures it ({@see PhpCliServer::withEnvironment()},
     * {@see EndpointContainer::withEnvironment()}): {@see get()}, which must not be external.
     *
     * @param string $what what the test does, for the message when the endpoint is external
     */
    public static function managed(string $what): PhpCliServer|EndpointContainer
    {
        $endpoint = self::get();

        return $endpoint instanceof ExternalEndpoint ? throw new \RuntimeException(IntegrationRestate::needsManaged($what)) : $endpoint;
    }

    /** Whether the endpoint runs in FrankenPHP's worker mode (RESTATE_PHP_TEST_SERVER=frankenphp-worker). */
    public static function isWorker(): bool
    {
        return self::server() === 'frankenphp-worker';
    }

    /**
     * The worker-mode endpoint with this many worker threads. A different configuration from {@see get()}'s, so the
     * harness gives it an environment of its own; with 1, every request reaches the same PHP thread.
     */
    public static function withWorkerThreads(int $threads): EndpointContainer
    {
        $endpoint = self::managed('runs the worker-mode endpoint with other worker threads');
        if (!self::isWorker() || !$endpoint instanceof EndpointContainer) {
            throw new \RuntimeException('Worker threads are a setting of the worker-mode endpoint: run with RESTATE_PHP_TEST_SERVER=frankenphp-worker (just test integration frankenphp-worker).');
        }

        return $endpoint->withEnvironment([self::WORKER_THREADS_VARIABLE => (string) $threads]);
    }

    /**
     * The `php -S` endpoint with the harness's ini defaults (`display_errors=0`, `log_errors=1`,
     * `enable_post_data_reading=0`), for tests that start it with other settings ({@see PhpCliServer::withIniSetting()}),
     * whatever RESTATE_PHP_TEST_SERVER is.
     */
    public static function phpCli(): PhpCliServer
    {
        if (self::isExternal()) {
            throw new \RuntimeException(IntegrationRestate::needsManaged('starts a php -S endpoint of its own'));
        }

        return PhpCliServer::forScript(__DIR__ . '/endpoint.php')
            ->withWorkingDirectory(Repository::ROOT)
            ->withEnvironment(['RESTATE_PHP_TEST_STATE_DIR' => self::stateDir()]);
    }

    /**
     * A PHP-FPM endpoint container (docker/fpm/Dockerfile target fpm-extension, run by
     * docker/fpm/entrypoint.sh): php-fpm behind `$front` (`nginx`, `nginx-h2c`, `caddy`, or `apache` in the
     * fpm-extension-apache image), serving the repository mounted at /app. `$settings` are more of the entrypoint's
     * RESTATE_PHP_FPM_* variables (profile, timeouts, `clear_env`, router script). Registered for HTTP/1.1; call
     * `withHttp2()` for h2c.
     *
     * @param array<string, string> $settings
     */
    public static function fpm(string $front, array $settings = [], ?string $image = null): EndpointContainer
    {
        if (self::isExternal()) {
            throw new \RuntimeException(IntegrationRestate::needsManaged('starts a php-fpm endpoint of its own'));
        }
        $image ??= self::env('RESTATE_PHP_TEST_FPM_IMAGE') ?? self::DEFAULT_FPM_IMAGE;
        self::preflight($image, 'just test integration nginx-fpm', 'RESTATE_PHP_TEST_FPM_IMAGE');

        return self::container($image)
            ->withEnvironment(['RESTATE_PHP_FPM_FRONT' => $front, ...$settings]);
    }

    private static function frankenPhpImage(): string
    {
        return self::env('RESTATE_PHP_TEST_FRANKENPHP_IMAGE') ?? self::DEFAULT_FRANKENPHP_IMAGE;
    }

    /**
     * The same endpoint with the fault injector enabled (RESTATE_PHP_TEST_FAULTS=1, {@see FaultInjector}). Its
     * configuration differs, so the harness gives it an environment of its own: a test that kills workers or restarts
     * Restate never disturbs the one every other test shares.
     */
    public static function withFaults(): PhpCliServer|EndpointContainer
    {
        return self::managed('enables the fault injector in an endpoint of its own')->withEnvironment([FaultInjector::ENABLE_VARIABLE => '1']);
    }

    /**
     * Scratch directory shared with the endpoint (RESTATE_PHP_TEST_STATE_DIR), where test services record attempts.
     * Removed when the process exits; an external endpoint's is its own (RESTATE_PHP_TEST_STATE_DIR), and kept.
     */
    public static function stateDir(): string
    {
        if (self::$stateDir !== null) {
            return self::$stateDir;
        }
        if (self::isExternal()) {
            $directory = self::env(self::STATE_DIR_VARIABLE);
            if ($directory === null || !is_dir($directory)) {
                throw new \RuntimeException(sprintf(
                    '%s must be the external endpoint\'s scratch directory (a devenv.nix profile exports it), got %s.',
                    self::STATE_DIR_VARIABLE,
                    $directory === null ? 'nothing' : "'{$directory}', which is not a directory",
                ));
            }

            return self::$stateDir = $directory;
        }

        $directory = sys_get_temp_dir() . '/restate-php-integration-' . bin2hex(random_bytes(4));
        if (!mkdir($directory, 0o700, true)) {
            throw new \RuntimeException("Could not create {$directory}.");
        }
        // Registered from a shutdown function, so it runs after the harness has stopped the endpoint.
        register_shutdown_function(static fn () => register_shutdown_function(static fn () => self::removeDirectory($directory)));

        return self::$stateDir = $directory;
    }

    /**
     * The FrankenPHP image serves the router script from the repository mounted at /app, with the thread-safe
     * extension.
     */
    private static function frankenPhp(string $image): EndpointContainer
    {
        self::preflight($image, 'just test integration frankenphp', 'RESTATE_PHP_TEST_FRANKENPHP_IMAGE', threadSafe: true);

        return self::container($image);
    }

    /** The endpoint of RESTATE_PHP_TEST_ENDPOINT_URL, which runs outside the harness. */
    private static function external(string $server): ExternalEndpoint
    {
        $url = self::env(self::EXTERNAL_URL_VARIABLE) ?? throw new \RuntimeException(sprintf(
            '%s is not set: with RESTATE_PHP_TEST_RESTATE=external it is the external endpoint (a devenv.nix profile exports it).',
            self::EXTERNAL_URL_VARIABLE,
        ));
        $endpoint = ExternalEndpoint::at($url)->withHttp2(in_array($server, self::HTTP2_SERVERS, true));
        $log = self::env(self::EXTERNAL_LOG_VARIABLE);

        return $log !== null ? $endpoint->withLogFile($log) : $endpoint;
    }

    /** An endpoint image with the repository at /app and the state directory at its own path. */
    private static function container(string $image): EndpointContainer
    {
        return EndpointContainer::forImage($image, self::CONTAINER_PORT)
            ->withVolume(Repository::ROOT, self::APP_DIR, readOnly: true)
            ->withVolume(self::stateDir(), self::stateDir())
            ->withEnvironment(['RESTATE_PHP_TEST_STATE_DIR' => self::stateDir()]);
    }

    /**
     * Construct the extension backend with the image's PHP CLI, against the mounted repository, so a module that
     * does not load fails with its own message before the harness starts anything. Once per image and process.
     */
    private static function preflight(string $image, string $build, string $variable, bool $threadSafe = false): void
    {
        if (isset(self::$preflighted[$image])) {
            return;
        }

        $docker = Process::findExecutable('docker') ?? Process::findExecutable('podman')
            ?? throw new \RuntimeException('An endpoint container needs `docker` or `podman` on PATH.');
        $code = sprintf(
            '%srequire %s; new \\%s();',
            $threadSafe ? 'if (!PHP_ZTS) { fwrite(STDERR, "not a thread-safe PHP\n"); exit(1); } ' : '',
            var_export(self::APP_DIR . '/vendor/autoload.php', true),
            ExtensionBackend::class,
        );

        [$exitCode, $stdout, $stderr] = Process::run([
            $docker, 'run', '--rm',
            '--volume', Repository::ROOT . ':' . self::APP_DIR . ':ro',
            $image, 'php', '-r', $code,
        ]);
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf(
                "The extension backend does not load in %s (build it with `%s` or set %s):\n%s",
                $image,
                $build,
                $variable,
                trim($stdout . $stderr),
            ));
        }

        self::$preflighted[$image] = true;
    }

    /**
     * @param \Closure(): EndpointContainer $create
     */
    private static function remember(\Closure $create): EndpointContainer
    {
        try {
            return $create();
        } catch (\Throwable $exception) {
            self::$failure = $exception;

            throw $exception;
        }
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($directory);
    }
}
