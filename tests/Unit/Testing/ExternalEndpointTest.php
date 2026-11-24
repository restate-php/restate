<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Restate\Testing\ExternalEndpoint;

final class ExternalEndpointTest extends TestCase
{
    public function testTheUrlIsKeptWithoutATrailingSlashAndRestateReachesItThere(): void
    {
        $endpoint = ExternalEndpoint::at('http://127.0.0.1:8000/restate/');

        self::assertSame('http://127.0.0.1:8000/restate', $endpoint->url);
        self::assertSame('http://127.0.0.1:8000/restate', $endpoint->uriForRestate());
        self::assertSame(8000, $endpoint->port());
        self::assertFalse($endpoint->http2);
        self::assertNull($endpoint->logFile);
    }

    public function testEveryOptionIsKeptAndTheOriginalIsUnchanged(): void
    {
        $original = ExternalEndpoint::at('http://127.0.0.1:8000');
        $endpoint = $original
            ->withRestateUri('http://host.docker.internal:8000/')
            ->withHttp2()
            ->withLogFile('/tmp/endpoint.log');

        self::assertSame('http://host.docker.internal:8000', $endpoint->uriForRestate());
        self::assertTrue($endpoint->http2);
        self::assertSame('/tmp/endpoint.log', $endpoint->logFile);
        self::assertFalse($endpoint->withHttp2(false)->http2);
        self::assertEquals(ExternalEndpoint::at('http://127.0.0.1:8000'), $original);
    }

    public function testThePortDefaultsToTheSchemes(): void
    {
        self::assertSame(80, ExternalEndpoint::at('http://endpoint.localhost')->port());
        self::assertSame(443, ExternalEndpoint::at('https://endpoint.localhost')->port());
    }

    public function testRejectsInvalidOptions(): void
    {
        foreach ([
            'URL without a scheme' => static fn () => ExternalEndpoint::at('127.0.0.1:8000'),
            'URL without a host' => static fn () => ExternalEndpoint::at('http:///restate'),
            'URL with a fragment' => static fn () => ExternalEndpoint::at('http://127.0.0.1:8000/#x'),
            'URI for Restate of another scheme' => static fn () => ExternalEndpoint::at('http://127.0.0.1:8000')->withRestateUri('ftp://127.0.0.1'),
            'empty log file' => static fn () => ExternalEndpoint::at('http://127.0.0.1:8000')->withLogFile(''),
        ] as $case => $with) {
            try {
                $with();
                self::fail("Invalid option ({$case}) was accepted.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
