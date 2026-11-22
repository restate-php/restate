<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Restate\Context;
use Restate\Endpoint;
use Restate\Internal\Backend\Invocation;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\Backend\ResponseHead;
use Restate\Psr\PsrEndpointHandler;
use Restate\Service;
use Restate\Tests\Support\FakeBackend;
use Restate\Tests\Support\FakeIdentityVerifier;
use Restate\Tests\Support\RequestIdentityTestSupport;

final class PsrEndpointHandlerTest extends TestCase
{
    private const string IDENTITY_KEY = 'publickeyv1_ChjENKeMvCtRnqG2mrBK1HmPKufgFUc98K8B3ononQvp';

    public function testConvertsServerRequestToEndpointRequest(): void
    {
        $invocation = $this->successfulInvocation('request-body');
        $core = new FakeBackend($invocation);
        $endpoint = Endpoint::create($core, [
            Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}"),
        ]);
        $factory = new PsrTestResponseFactory();
        $handler = new PsrEndpointHandler($endpoint, $factory);

        $response = $handler->handle($this->request(
            path: '/invoke/Greeter/greet',
            query: 'x=1',
            method: 'POST',
            protocol: '2',
            headers: [
                'accept' => ['application/vnd.restate.endpointmanifest.v4+json'],
                'content-type' => ['application/vnd.restate.invocation.v6'],
                'content-length' => ['12'],
                'x-custom-header' => ['a', 'b'],
            ],
            body: 'request-body',
        ));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('"Hello Mary"', (string) $response->getBody());
        self::assertSame([202], $factory->statusCodes);
        self::assertNotNull($core->headers);
        self::assertContains(['accept', 'application/vnd.restate.endpointmanifest.v4+json'], $core->headers);
        self::assertContains(['content-type', 'application/vnd.restate.invocation.v6'], $core->headers);
        self::assertContains(['content-length', '12'], $core->headers);
        self::assertContains(['x-custom-header', 'a, b'], $core->headers);
    }

    public function testHeaderNamesReachTheEndpointUnchangedApartFromCase(): void
    {
        $core = new FakeBackend($this->successfulInvocation('request-body'));
        $endpoint = Endpoint::create($core, [
            Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}"),
        ]);
        $handler = new PsrEndpointHandler($endpoint, new PsrTestResponseFactory());

        $handler->handle($this->request(
            path: '/invoke/Greeter/greet',
            headers: [
                'content-type' => ['application/vnd.restate.invocation.v6'],
                'X_Trace_Id' => ['underscore'],
                'x-trace-id' => ['dash'],
                // A numeric name becomes an integer array key.
                '1234' => ['digits'],
            ],
            body: 'request-body',
        ));

        self::assertNotNull($core->headers);
        self::assertContains(['x_trace_id', 'underscore'], $core->headers);
        self::assertContains(['x-trace-id', 'dash'], $core->headers);
        self::assertContains(['1234', 'digits'], $core->headers);
    }

    public function testAHeaderNamedContentTypeWithAnUnderscoreIsNotTheContentType(): void
    {
        $core = new FakeBackend();
        $endpoint = Endpoint::create($core, [
            Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}"),
        ]);
        $handler = new PsrEndpointHandler($endpoint, new PsrTestResponseFactory());

        $response = $handler->handle($this->request(
            path: '/invoke/Greeter/greet',
            headers: ['content_type' => ['application/vnd.restate.invocation.v6']],
            body: 'request-body',
        ));

        self::assertSame(415, $response->getStatusCode());
        self::assertSame('{"error":"Missing content-type header"}', (string) $response->getBody());
        self::assertNull($core->headers, 'no invocation was created');
    }

    public function testAddsDuplicateResponseHeaders(): void
    {
        $endpoint = Endpoint::create(new FakeBackend($this->successfulInvocation('request-body')), [
            Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}"),
        ]);
        $handler = new PsrEndpointHandler($endpoint, new PsrTestResponseFactory());

        $response = $handler->handle($this->request(path: '/invoke/Greeter/greet', headers: ['content-type' => ['application/vnd.restate.invocation.v6']], body: 'request-body'));

        self::assertSame(['a=1', 'b=2'], $response->getHeader('set-cookie'));
    }

    public function testWritesResponseBodyToPsrStream(): void
    {
        $endpoint = Endpoint::create(new FakeBackend($this->successfulInvocation('request-body')), [
            Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}"),
        ]);
        $handler = new PsrEndpointHandler($endpoint, new PsrTestResponseFactory());

        $response = $handler->handle($this->request(path: '/invoke/Greeter/greet', headers: ['content-type' => ['application/vnd.restate.invocation.v6']], body: 'request-body'));

        self::assertSame('"Hello Mary"', (string) $response->getBody());
    }

    public function testIdentityValidationAppliesToPsrEndpointHandler(): void
    {
        $identityKey = self::IDENTITY_KEY;
        $identityHeaders = FakeIdentityVerifier::signedHeaders($identityKey, '/invoke/Greeter/greet');
        $identityHeaders['x-restate-jwt-v1'] .= 'invalid';
        $core = new FakeBackend();
        $endpoint = Endpoint::create($core, [
            Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}"),
        ], identityKeys: [$identityKey]);
        $handler = new PsrEndpointHandler($endpoint, new PsrTestResponseFactory());

        $response = $handler->handle($this->request(
            path: '/invoke/Greeter/greet',
            headers: RequestIdentityTestSupport::psrHeaders($identityHeaders),
            body: 'request-body',
        ));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('{"error":"Invalid Restate request identity."}', (string) $response->getBody());
        self::assertNull($core->headers, 'no invocation was created');
    }

    public function testIdentityValidationGatesDiscoveryButNotHealthInPsrEndpointHandler(): void
    {
        $identityKey = self::IDENTITY_KEY;
        $identityHeaders = FakeIdentityVerifier::signedHeaders($identityKey, '/discover');
        $endpoint = Endpoint::create(new FakeBackend(), [
            Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}"),
        ], identityKeys: [$identityKey]);
        $handler = new PsrEndpointHandler($endpoint, new PsrTestResponseFactory());
        $accept = ['accept' => ['application/vnd.restate.endpointmanifest.v4+json']];

        $unsigned = $handler->handle($this->request(path: '/discover', headers: $accept));
        $signed = $handler->handle($this->request(
            path: '/discover',
            headers: [...$accept, ...RequestIdentityTestSupport::psrHeaders($identityHeaders)],
        ));
        $health = $handler->handle($this->request(path: '/health'));

        self::assertSame(401, $unsigned->getStatusCode());
        self::assertSame(200, $signed->getStatusCode());
        self::assertStringContainsString('"Greeter"', (string) $signed->getBody());
        self::assertSame(200, $health->getStatusCode());
    }

    /** @param array<array-key, list<string>> $headers */
    private function request(
        string $path = '/health',
        string $query = '',
        string $method = 'GET',
        string $protocol = '1.1',
        array $headers = [],
        string $body = '',
    ): ServerRequestInterface {
        $uri = self::createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $uri->method('getQuery')->willReturn($query);

        $request = self::createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn($method);
        $request->method('getProtocolVersion')->willReturn($protocol);
        $request->method('getHeaders')->willReturn($headers);
        $request->method('getBody')->willReturn(new PsrTestStream($body));

        return $request;
    }

    private function successfulInvocation(string $expectedBody): Invocation
    {
        $chunks = [];
        $invocation = $this->createMock(Invocation::class);
        $invocation->method('responseHead')->willReturn(new ResponseHead(202, [['set-cookie', 'a=1'], ['set-cookie', 'b=2']]));
        $invocation->expects(self::once())->method('notifyInput')->with($expectedBody);
        $invocation->expects(self::once())->method('notifyInputClosed');
        $invocation->method('isReadyToExecute')->willReturn(true);
        $invocation->method('input')->willReturn(new InvocationInput('inv-1', 0, '', [], '"Mary"'));
        $invocation->method('writeOutputSuccess')->willReturnCallback(static function (string $bytes) use (&$chunks): void {
            $chunks[] = $bytes;
        });
        $invocation->method('takeOutput')->willReturnCallback(static function () use (&$chunks): ?string {
            return array_shift($chunks);
        });

        return $invocation;
    }
}
