<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use Restate\Testing\PHPUnit\InteractsWithRestate;
use Restate\Testing\PhpCliServer;

/**
 * Testing your own services against a real Restate server with PHPUnit. Needs Docker or podman, the Restate PHP
 * extension (in php.ini, RESTATE_PHP_EXTENSION_PATH, or PhpCliServer::withExtension()), and guzzlehttp/guzzle for the
 * default ingress client.
 *
 * The repository's integration suite runs this file (`composer test:integration`).
 */
final class GreeterTest extends TestCase
{
    use InteractsWithRestate;

    protected static function restateEndpoint(): PhpCliServer
    {
        return PhpCliServer::forScript(__DIR__ . '/endpoint.php');
    }

    public function testGreets(): void
    {
        $greeter = self::restate()->ingressClient()->serviceClient('Greeter');

        self::assertSame('Hello Mary', $greeter->request('greet', 'Mary')->call());
    }

    public function testCountsPerKey(): void
    {
        // Restate keeps state between tests: give every test its own keys.
        $key = 'counter-' . bin2hex(random_bytes(4));
        $counter = self::restate()->ingressClient()->objectClient('Counter', $key);

        self::assertSame(2, $counter->request('add', 2)->call());
        self::assertSame(5, $counter->request('add', 3)->call());
    }

    public function testAnUnknownHandlerIsRejected(): void
    {
        $this->expectException(\Restate\Ingress\IngressException::class);

        self::restate()->ingressClient()->serviceClient('Greeter')->request('wave', 'Mary')->call();
    }
}
