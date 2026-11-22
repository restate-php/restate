<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Restate\Psr\PsrEndpointHandler;
use Restate\Tests\Support\ExtensionProcess;

final class PublicApiContractTest extends TestCase
{
    #[Group('extension')]
    public function testEndpointPsrFactoryReturnsPsrHandler(): void
    {
        $identityKey = 'publickeyv1_ChjENKeMvCtRnqG2mrBK1HmPKufgFUc98K8B3ononQvp';
        // Building an endpoint needs the extension, which only a child process can load.
        $class = ExtensionProcess::runJson(<<<'PHP'
            $handler = Restate\Endpoint::psr(
                [Restate\Service::new('Greeter')],
                new Restate\Tests\Unit\Sdk\PsrTestResponseFactory(),
                identityKeys: [IDENTITY_KEY],
            );
            echo json_encode($handler::class);
            PHP, ['IDENTITY_KEY' => var_export($identityKey, true)]);

        self::assertSame(PsrEndpointHandler::class, $class);
    }
}
