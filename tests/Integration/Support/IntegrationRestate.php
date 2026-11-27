<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Support;

use Restate\Testing\RestateContainer;
use Restate\Testing\RestateProcess;

/**
 * The integration suite's Restate server, run by the public test harness: in a container (the default, what users of
 * `restate/testing` get) or as a process of the harness (`restate-server` from the dev shell, without a container
 * runtime). docs/decisions/0038-native-restate-for-the-test-harness.md has the design.
 *
 * Configuration (environment variables):
 * - RESTATE_PHP_TEST_RESTATE      `container` (default: {@see RestateContainer::default()}, so also
 *                                 `RESTATE_PHP_RESTATE_IMAGE`) or `process` ({@see RestateProcess::default()}).
 *
 * Every test that starts a Restate of its own builds it here, so it runs against either kind.
 */
final class IntegrationRestate
{
    public const string VARIABLE = 'RESTATE_PHP_TEST_RESTATE';

    private function __construct()
    {
    }

    /** The configured kind's default. */
    public static function get(): RestateContainer|RestateProcess
    {
        return match (self::kind()) {
            'container' => RestateContainer::default(),
            'process' => RestateProcess::default(),
        };
    }

    /**
     * The configured kind with Restate configuration added.
     *
     * @param array<string, string> $variables
     */
    public static function withEnvironment(array $variables): RestateContainer|RestateProcess
    {
        return self::get()->withEnvironment($variables);
    }

    /**
     * The configured kind with a file on the test host that a setting points to, such as a request identity key:
     * mounted at `$containerPath` in a container, read where it is by a process.
     */
    public static function withFile(string $variable, string $hostPath, string $containerPath): RestateContainer|RestateProcess
    {
        $restate = self::get();
        if ($restate instanceof RestateProcess) {
            return $restate->withEnvironment([$variable => $hostPath]);
        }

        return $restate->withVolume($hostPath, $containerPath, readOnly: true)->withEnvironment([$variable => $containerPath]);
    }

    /** @return 'container'|'process' */
    private static function kind(): string
    {
        $kind = getenv(self::VARIABLE);

        return match ($kind === false || $kind === '' ? 'container' : $kind) {
            'container' => 'container',
            'process' => 'process',
            default => throw new \RuntimeException(sprintf("%s must be 'container' or 'process', got '%s'.", self::VARIABLE, $kind)),
        };
    }
}
