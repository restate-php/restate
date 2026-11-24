<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Support;

use Restate\Testing\ExternalRestate;
use Restate\Testing\RestateContainer;
use Restate\Testing\RestateProcess;

/**
 * The integration suite's Restate server, run by the public test harness: in a container (the default, what users of
 * `restate/testing` get) or as a process of the harness (`restate-server` from the dev shell, without a container
 * runtime); or one that runs outside the harness, which the harness only connects to (`devenv up` with a profile of
 * devenv.nix). docs/decisions/0038-native-restate-for-the-test-harness.md and
 * docs/decisions/0039-external-test-environments.md have the design.
 *
 * Configuration (environment variables):
 * - RESTATE_PHP_TEST_RESTATE      `container` (default: {@see RestateContainer::default()}, so also
 *                                 `RESTATE_PHP_RESTATE_IMAGE`), `process` ({@see RestateProcess::default()}) or
 *                                 `external` ({@see ExternalRestate::fromEnvironment()}: `RESTATE_ADMIN_URL` and
 *                                 `RESTATE_INGRESS_URL`, which devenv-extras' services.restate exports). `external`
 *                                 also makes the endpoint external ({@see IntegrationEndpoint}).
 * - RESTATE_PHP_TEST_RESTATE_LOG  with `external`, the Restate server's log file, for the log tails of failed tests.
 *
 * Every test that starts a Restate of its own builds it here, so it runs against either kind the harness starts; with
 * `external` it fails, as it needs a Restate the harness configures ({@see managed()}).
 */
final class IntegrationRestate
{
    public const string VARIABLE = 'RESTATE_PHP_TEST_RESTATE';

    public const string LOG_VARIABLE = 'RESTATE_PHP_TEST_RESTATE_LOG';

    /** The group of the tests that need an environment the harness starts: the external runs exclude it. */
    public const string MANAGED_GROUP = 'managed';

    private function __construct()
    {
    }

    /** The configured kind's default. */
    public static function get(): RestateContainer|RestateProcess|ExternalRestate
    {
        if (self::isExternal()) {
            $restate = ExternalRestate::fromEnvironment();
            $log = getenv(self::LOG_VARIABLE);

            return is_string($log) && $log !== '' ? $restate->withLogFile($log) : $restate;
        }

        return self::managed('');
    }

    /**
     * The configured kind with Restate configuration added.
     *
     * @param array<string, string> $variables
     */
    public static function withEnvironment(array $variables): RestateContainer|RestateProcess
    {
        return self::managed('configures Restate')->withEnvironment($variables);
    }

    /**
     * The configured kind with a file on the test host that a setting points to, such as a request identity key:
     * mounted at `$containerPath` in a container, read where it is by a process.
     */
    public static function withFile(string $variable, string $hostPath, string $containerPath): RestateContainer|RestateProcess
    {
        $restate = self::managed('configures Restate');
        if ($restate instanceof RestateProcess) {
            return $restate->withEnvironment([$variable => $hostPath]);
        }

        return $restate->withVolume($hostPath, $containerPath, readOnly: true)->withEnvironment([$variable => $containerPath]);
    }

    /** Whether Restate runs as a process (RESTATE_PHP_TEST_RESTATE=process). */
    public static function isProcess(): bool
    {
        return self::kind() === 'process';
    }

    /** Whether Restate and the endpoint run outside the harness (RESTATE_PHP_TEST_RESTATE=external). */
    public static function isExternal(): bool
    {
        return self::kind() === 'external';
    }

    /**
     * The message for a test that needs an environment the harness starts, run against an external one.
     *
     * @param string $what what the test does, for example `configures Restate`
     */
    public static function needsManaged(string $what): string
    {
        return sprintf(
            "This test %s, which the harness cannot do with an environment that runs outside it (%s=external): "
            . "mark the test class #[Group('%s')], which the external runs exclude.",
            $what,
            self::VARIABLE,
            self::MANAGED_GROUP,
        );
    }

    /** A Restate the harness starts: the configured kind, which must not be `external`. */
    private static function managed(string $what): RestateContainer|RestateProcess
    {
        return match (self::kind()) {
            'container' => RestateContainer::default(),
            'process' => RestateProcess::default(),
            default => throw new \RuntimeException(self::needsManaged($what)),
        };
    }

    private static function kind(): string
    {
        $kind = getenv(self::VARIABLE);
        $kind = $kind === false || $kind === '' ? 'container' : $kind;

        return in_array($kind, ['container', 'process', 'external'], true)
            ? $kind
            : throw new \RuntimeException(sprintf("%s must be 'container', 'process' or 'external', got '%s'.", self::VARIABLE, $kind));
    }
}
