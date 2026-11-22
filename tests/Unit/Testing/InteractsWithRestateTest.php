<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Restate\Testing\PHPUnit\InteractsWithRestate;
use Restate\Testing\PhpCliServer;
use Restate\Testing\RestateContainer;
use Restate\Testing\RestateTestEnvironment;
use Restate\Testing\TestEnvironmentException;

final class InteractsWithRestateTest extends TestCase
{
    public function testTheEnvironmentIsBuiltFromTheTestCasesEndpointAndContainer(): void
    {
        $testCase = new class ('probe') extends TestCase {
            use InteractsWithRestate;

            #[\Override]
            protected static function restateEndpoint(): PhpCliServer
            {
                return PhpCliServer::forScript(__FILE__);
            }

            protected static function restateContainer(): RestateContainer
            {
                return RestateContainer::default()->withContainerRuntime('/does/not/exist/podman');
            }

            public static function environment(): RestateTestEnvironment
            {
                return self::restate();
            }
        };

        try {
            $testCase::environment();
            self::fail('The environment started without a container runtime.');
        } catch (TestEnvironmentException $exception) {
            self::assertStringContainsString('/does/not/exist/podman', $exception->getMessage());
        }
    }
}
