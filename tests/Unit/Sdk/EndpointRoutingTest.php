<?php

declare(strict_types=1);

namespace Restate;

function header(string $header, bool $replace = true, int $response_code = 0): void
{
    \Restate\Tests\Unit\Sdk\EndpointHeaderEmitter::header($header, $replace, $response_code);
}

function http_response_code(int $response_code = 0): int
{
    return \Restate\Tests\Unit\Sdk\EndpointHeaderEmitter::httpResponseCode($response_code);
}

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\Endpoint;
use Restate\EndpointResponse;
use Restate\Internal\Backend;
use Restate\Internal\Backend\Invocation;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\Backend\ResponseHead;
use Restate\Internal\Route;
use Restate\Internal\RouteKind;
use Restate\ObjectContext;
use Restate\RetryableError;
use Restate\Service;
use Restate\Tests\Support\FakeBackend;
use Restate\Tests\Support\FakeIdentityVerifier;
use Restate\Tests\Support\RequestIdentityTestSupport;
use Restate\VirtualObject;

final class EndpointRoutingTest extends TestCase
{
    private const string IDENTITY_KEY = 'publickeyv1_ChjENKeMvCtRnqG2mrBK1HmPKufgFUc98K8B3ononQvp';

    public function testParseInvocationRoute(): void
    {
        $route = Route::parse('/invoke/Greeter/greet');

        self::assertSame([RouteKind::Invoke, 'Greeter', 'greet', null], [$route->kind, $route->service, $route->handler, $route->key]);
    }

    public function testParseVirtualObjectInvocationRoute(): void
    {
        $route = Route::parse('/invoke/Failing/object-1/sideEffectFailsAfterGivenAttempts');

        self::assertSame([RouteKind::Invoke, 'Failing', 'sideEffectFailsAfterGivenAttempts', 'object-1'], [$route->kind, $route->service, $route->handler, $route->key]);
    }

    public function testParseHealthAndDiscoverRoutes(): void
    {
        self::assertSame(RouteKind::Health, Route::parse('/health')->kind);
        self::assertSame(RouteKind::Discover, Route::parse('/discover')->kind);
    }

    public function testRootWithDiscoveryAcceptHeaderParsesAsDiscover(): void
    {
        self::assertSame(RouteKind::Discover, Route::parse('/', 'application/vnd.restate.endpointmanifest.v4+json')->kind);
    }

    public function testRootWithoutDiscoveryAcceptHeaderRemainsUnknown(): void
    {
        self::assertSame(RouteKind::Unknown, Route::parse('/')->kind);
    }

    public function testUnknownRoute(): void
    {
        self::assertSame(RouteKind::Unknown, Route::parse('/missing')->kind);
    }

    public function testEndpointAcceptsServiceBuilders(): void
    {
        $endpoint = Endpoint::create(new FakeBackend(), [\Restate\Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => $name)]);

        $response = $endpoint->handleRequest(['REQUEST_URI' => '/discover', 'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v2+json'], '');

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('"name":"Greeter"', $response->body);
    }

    public function testEndpointResponseStoresStatusHeadersAndBody(): void
    {
        $response = new EndpointResponse(202, [['x-test', 'yes']], 'body');

        self::assertSame(202, $response->statusCode);
        self::assertSame([['x-test', 'yes']], $response->headers);
        self::assertSame('body', $response->body);
    }

    public function testHandleRequestReturnsHealthResponse(): void
    {
        $endpoint = Endpoint::create($this->unusedCore(), []);

        $response = $endpoint->handleRequest(['REQUEST_URI' => '/health'], '');

        self::assertSame(200, $response->statusCode);
        self::assertSame([['content-type', 'application/json']], $response->headers);
        self::assertSame('{"status":"ok"}', $response->body);
    }

    public function testHandleRequestReturnsDiscoveryResponse(): void
    {
        $endpoint = Endpoint::create($this->unusedCore(), [Service::new('Greeter')]);

        $response = $endpoint->handleRequest([
            'REQUEST_URI' => '/discover',
            'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v4+json',
        ], '');

        self::assertSame(200, $response->statusCode);
        self::assertSame([['content-type', 'application/vnd.restate.endpointmanifest.v4+json']], $response->headers);
        $manifest = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Greeter', $manifest['services'][0]['name']);
    }

    public function testDiscoveryAnswersRestate18WithManifestV5(): void
    {
        $endpoint = Endpoint::create($this->unusedCore(), [Service::new('Greeter')]);

        $response = $endpoint->handleRequest([
            'REQUEST_URI' => '/discover',
            // What Restate 1.8 sends: every manifest version it reads.
            'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v1+json, application/vnd.restate.endpointmanifest.v2+json, '
                . 'application/vnd.restate.endpointmanifest.v3+json, application/vnd.restate.endpointmanifest.v4+json, '
                . 'application/vnd.restate.endpointmanifest.v5+json',
        ], '');

        self::assertSame(200, $response->statusCode);
        self::assertSame([['content-type', 'application/vnd.restate.endpointmanifest.v5+json']], $response->headers);
    }

    public function testDiscoveryAtTheRootMatchesTheAcceptHeaderCaseInsensitively(): void
    {
        $endpoint = Endpoint::create($this->unusedCore(), [Service::new('Greeter')]);

        $response = $endpoint->handleRequest([
            'REQUEST_URI' => '/',
            'HTTP_ACCEPT' => 'Application/Vnd.Restate.Endpointmanifest.V5+json',
        ], '');

        self::assertSame(200, $response->statusCode);
        self::assertSame([['content-type', 'application/vnd.restate.endpointmanifest.v5+json']], $response->headers);
    }

    public function testDiscoveryAdvertisesTheProtocolVersionsTheBackendReports(): void
    {
        $backend = new FakeBackend(protocolVersions: ['min' => 6, 'max' => 7]);
        $endpoint = Endpoint::create($backend, [Service::new('Greeter')]);

        $response = $endpoint->handleRequest([
            'REQUEST_URI' => '/discover',
            'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v5+json',
        ], '');

        $manifest = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(6, $manifest['minProtocolVersion']);
        self::assertSame(7, $manifest['maxProtocolVersion']);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function discoveryRequestsWithoutASupportedManifestVersion(): iterable
    {
        yield 'no accept header' => [['REQUEST_URI' => '/discover'], 'Missing accept header'];
        yield 'plain JSON' => [
            ['REQUEST_URI' => '/discover', 'HTTP_ACCEPT' => 'application/json'],
            "Unsupported service discovery protocol version 'application/json'",
        ];
        yield 'v1 only' => [
            ['REQUEST_URI' => '/', 'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v1+json'],
            "Unsupported service discovery protocol version 'application/vnd.restate.endpointmanifest.v1+json'",
        ];
    }

    /** @param array<string, string> $server */
    #[DataProvider('discoveryRequestsWithoutASupportedManifestVersion')]
    public function testDiscoveryWithoutASupportedManifestVersionIsRejectedWith415(array $server, string $error): void
    {
        $endpoint = Endpoint::create($this->unusedCore(), [Service::new('Greeter')]);

        $response = $endpoint->handleRequest($server, '');

        self::assertSame(415, $response->statusCode);
        self::assertSame(json_encode(['error' => $error], JSON_THROW_ON_ERROR), $response->body);
    }

    public function testHandleRequestOmitsV3MetadataFromV2DiscoveryResponse(): void
    {
        $endpoint = Endpoint::create($this->unusedCore(), [Service::new('Greeter', documentation: 'Greeter service.', metadata: ['domain' => 'examples'])]);

        $response = $endpoint->handleRequest([
            'REQUEST_URI' => '/discover',
            'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v2+json',
        ], '');

        self::assertSame([['content-type', 'application/vnd.restate.endpointmanifest.v2+json']], $response->headers);
        $manifest = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('documentation', $manifest['services'][0]);
        self::assertArrayNotHasKey('metadata', $manifest['services'][0]);
    }

    public function testHandleRequestReturnsUnknownRouteResponse(): void
    {
        $endpoint = Endpoint::create($this->unusedCore(), []);

        $response = $endpoint->handleRequest(['REQUEST_URI' => '/missing'], '');

        self::assertSame(404, $response->statusCode);
        self::assertSame([], $response->headers);
        self::assertSame('', $response->body);
    }

    public function testHandleStillEmitsResponseBody(): void
    {
        $endpoint = Endpoint::create($this->unusedCore(), []);

        ob_start();
        try {
            $endpoint->handle(['REQUEST_URI' => '/health'], '');
            $body = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        self::assertSame('{"status":"ok"}', $body);
    }

    public function testHandleRequestPassesTheInvocationRequestToTheHandlerContext(): void
    {
        $invocation = $this->successfulInvocation([['content-type', 'application/vnd.restate.invocation.v7']], input: new InvocationInput(
            'inv_1abc',
            0,
            'counter-1',
            [['x-my-custom-header', 'x-my-custom-value']],
            '"Mary"',
            scope: 'tenant-a',
            limitKey: 'limit-1',
            idempotencyKey: 'idem-1',
        ));
        $endpoint = Endpoint::create($this->coreForInvocation($invocation), [
            VirtualObject::new('Counter')->handler('request', static function (ObjectContext $ctx, string $_): array {
                $request = $ctx->request();

                return [
                    'id' => $request->id,
                    'key' => $request->target->key(),
                    'headers' => $request->headers,
                    'attemptHeaders' => $request->attemptHeaders,
                    'body' => $request->body,
                    'idempotencyKey' => $request->idempotencyKey,
                    'scope' => $request->scope,
                    'limitKey' => $request->limitKey,
                ];
            }),
        ]);

        $response = $endpoint->handleRequest([
            'REQUEST_URI' => '/invoke/Counter/request',
            'CONTENT_TYPE' => 'application/vnd.restate.invocation.v7',
            'HTTP_X_RESTATE_ATTEMPT' => '2',
        ], 'request-body');

        self::assertSame(202, $response->statusCode);
        self::assertSame([
            'id' => 'inv_1abc',
            'key' => 'counter-1',
            'headers' => ['x-my-custom-header' => 'x-my-custom-value'],
            'attemptHeaders' => ['content-type' => 'application/vnd.restate.invocation.v7', 'x-restate-attempt' => '2'],
            'body' => '"Mary"',
            'idempotencyKey' => 'idem-1',
            'scope' => 'tenant-a',
            'limitKey' => 'limit-1',
        ], json_decode($response->body, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testIdentityValidationAllowsValidInvocationRequest(): void
    {
        $identityKey = self::IDENTITY_KEY;
        $identityHeaders = FakeIdentityVerifier::signedHeaders($identityKey, '/invoke/Greeter/greet');
        $handlerInvoked = false;
        $endpoint = Endpoint::create($this->coreForInvocation($this->successfulInvocation([
            ['content-type', 'application/vnd.restate.invocation.v6'],
        ])), [
            Service::new('Greeter')->handler('greet', static function (Context $ctx, string $name) use (&$handlerInvoked): string {
                $handlerInvoked = true;

                return "Hello {$name}";
            }),
        ], identityKeys: [$identityKey]);

        $response = $endpoint->handleRequest([
            'REQUEST_URI' => '/invoke/Greeter/greet?debug=1',
            'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6',
            ...RequestIdentityTestSupport::serverHeaders($identityHeaders),
        ], 'request-body');

        self::assertSame(202, $response->statusCode);
        self::assertSame('"Hello Mary"', $response->body);
        self::assertTrue($handlerInvoked);
    }

    public function testIdentityValidationRejectsInvalidInvocationRequestBeforeHandlerExecution(): void
    {
        $identityKey = self::IDENTITY_KEY;
        $identityHeaders = FakeIdentityVerifier::signedHeaders($identityKey, '/invoke/Greeter/greet');
        $identityHeaders['x-restate-jwt-v1'] .= 'invalid';
        $handlerInvoked = false;
        $endpoint = Endpoint::create($this->unusedCore(), [
            Service::new('Greeter')->handler('greet', static function (Context $ctx, string $name) use (&$handlerInvoked): string {
                $handlerInvoked = true;

                return "Hello {$name}";
            }),
        ], identityKeys: [$identityKey]);

        $response = $endpoint->handleRequest([
            'REQUEST_URI' => '/invoke/Greeter/greet',
            ...RequestIdentityTestSupport::serverHeaders($identityHeaders),
        ], 'request-body');

        self::assertSame(401, $response->statusCode);
        self::assertSame([['content-type', 'application/json']], $response->headers);
        self::assertSame('{"error":"Invalid Restate request identity."}', $response->body);
        self::assertFalse($handlerInvoked);
    }

    public function testTheEndpointBuildsOneVerifierForItsKeysAndAsksItWithTheRequestPathAndHeaders(): void
    {
        $backend = new FakeBackend();
        $endpoint = Endpoint::create($backend, [Service::new('Greeter')], identityKeys: new \ArrayIterator([self::IDENTITY_KEY, 'publickeyv1_second']));
        $verifier = $backend->identityVerifier;
        self::assertNotNull($verifier);
        self::assertSame([self::IDENTITY_KEY, 'publickeyv1_second'], $verifier->identityKeys);

        $endpoint->handleRequest(['REQUEST_URI' => '/invoke/Greeter/greet?debug=1', 'HTTP_X_RESTATE_SIGNATURE_SCHEME' => 'v1'], '');
        $endpoint->handleHttpRequest('', [['accept', 'application/vnd.restate.endpointmanifest.v4+json']], '');

        self::assertSame($verifier, $backend->identityVerifier, 'one verifier serves every request');
        self::assertSame([
            ['headers' => [['x-restate-signature-scheme', 'v1']], 'path' => '/invoke/Greeter/greet'],
            ['headers' => [['accept', 'application/vnd.restate.endpointmanifest.v4+json']], 'path' => '/'],
        ], $verifier->calls);
    }

    public function testAnEndpointWithoutIdentityKeysBuildsNoVerifier(): void
    {
        $backend = new FakeBackend();

        Endpoint::create($backend, [Service::new('Greeter')]);

        self::assertNull($backend->identityVerifier);
    }

    /**
     * Configured keys fail closed: `[getenv('RESTATE_IDENTITY_KEY')]` with the variable unset, an empty variable, or an
     * empty list are configuration errors, never "verification off".
     *
     * @param iterable<mixed> $identityKeys
     * @param non-empty-string $message
     */
    #[DataProvider('misconfiguredIdentityKeys')]
    public function testMisconfiguredIdentityKeysAreRejectedWhenTheEndpointIsBuilt(iterable $identityKeys, string $message): void
    {
        $backend = new FakeBackend();

        try {
            Endpoint::create($backend, [Service::new('Greeter')], identityKeys: $identityKeys);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringStartsWith($message, $e->getMessage());
        }
        self::assertNull($backend->identityVerifier);
    }

    /** The builder and the shortcuts check keys before they look for the extension, which the unit suite lacks. */
    public function testTheBuilderAndShortcutsRejectAnUnsetKeyBeforeNeedingTheExtension(): void
    {
        $services = [Service::new('Greeter')];
        foreach ([
            'builder' => [static fn (): mixed => Endpoint::builder()->services($services)->identityKeys([false])->build(), 'A request identity key is false'],
            'fromServices' => [static fn (): mixed => Endpoint::fromServices($services, identityKeys: ['']), 'A request identity key is empty'],
            'psr' => [static fn (): mixed => Endpoint::psr($services, new PsrTestResponseFactory(), identityKeys: []), 'No request identity keys given'],
        ] as $entry => [$build, $message]) {
            try {
                $build();
                self::fail("{$entry}: expected InvalidArgumentException");
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith($message, $e->getMessage(), $entry);
            }
        }
    }

    /** @return iterable<string, array{0: iterable<mixed>, 1: non-empty-string}> */
    public static function misconfiguredIdentityKeys(): iterable
    {
        yield 'getenv() of an unset variable' => [[false], 'A request identity key is false, as getenv() returns for an environment variable that is not set.'];
        yield 'false among valid keys' => [[self::IDENTITY_KEY, false], 'A request identity key is false'];
        yield 'empty key' => [[''], 'A request identity key is empty.'];
        yield 'empty key from explode()' => [explode(',', self::IDENTITY_KEY . ','), 'A request identity key is empty.'];
        yield 'empty list' => [[], 'No request identity keys given.'];
        yield 'empty iterator' => [new \ArrayIterator([]), 'No request identity keys given.'];
    }

    public function testIdentityKeysMustBeStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Request identity keys must be strings.');

        Endpoint::create(new FakeBackend(), [Service::new('Greeter')], identityKeys: [self::IDENTITY_KEY, 42]);
    }

    public function testIdentityValidationRejectsMissingInvocationIdentityBeforeHandlerExecution(): void
    {
        $identityKey = self::IDENTITY_KEY;
        $handlerInvoked = false;
        $endpoint = Endpoint::create($this->unusedCore(), [
            Service::new('Greeter')->handler('greet', static function (Context $ctx, string $name) use (&$handlerInvoked): string {
                $handlerInvoked = true;

                return "Hello {$name}";
            }),
        ], identityKeys: [$identityKey]);

        $response = $endpoint->handleRequest(['REQUEST_URI' => '/invoke/Greeter/greet', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], 'request-body');

        self::assertSame(401, $response->statusCode);
        self::assertSame('{"error":"Invalid Restate request identity."}', $response->body);
        self::assertFalse($handlerInvoked);
    }

    public function testIdentityValidationIsDisabledByDefault(): void
    {
        $handlerInvoked = false;
        $endpoint = Endpoint::create($this->coreForInvocation($this->successfulInvocation([
            ['content-type', 'application/vnd.restate.invocation.v6'],
        ])), [
            Service::new('Greeter')->handler('greet', static function (Context $ctx, string $name) use (&$handlerInvoked): string {
                $handlerInvoked = true;

                return "Hello {$name}";
            }),
        ]);

        $response = $endpoint->handleRequest(['REQUEST_URI' => '/invoke/Greeter/greet', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], 'request-body');

        self::assertSame(202, $response->statusCode);
        self::assertTrue($handlerInvoked);
    }

    public function testIdentityValidationDoesNotBlockHealth(): void
    {
        $identityKey = self::IDENTITY_KEY;
        $endpoint = Endpoint::create($this->unusedCore(), [Service::new('Greeter')], identityKeys: [$identityKey]);

        $health = $endpoint->handleRequest(['REQUEST_URI' => '/health'], '');

        self::assertSame(200, $health->statusCode);
        self::assertSame('{"status":"ok"}', $health->body);
    }

    public function testIdentityValidationRejectsDiscoveryWithoutIdentity(): void
    {
        $identityKey = self::IDENTITY_KEY;
        $endpoint = Endpoint::create($this->unusedCore(), [Service::new('Greeter')], identityKeys: [$identityKey]);

        $discovery = $endpoint->handleRequest([
            'REQUEST_URI' => '/discover',
            'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v4+json',
        ], '');

        self::assertSame(401, $discovery->statusCode);
        self::assertSame('{"error":"Invalid Restate request identity."}', $discovery->body);
    }

    public function testIdentityValidationRejectsRootDiscoveryWithoutIdentity(): void
    {
        $identityKey = self::IDENTITY_KEY;
        $endpoint = Endpoint::create($this->unusedCore(), [Service::new('Greeter')], identityKeys: [$identityKey]);

        $discovery = $endpoint->handleRequest([
            'REQUEST_URI' => '/',
            'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v4+json',
        ], '');

        self::assertSame(401, $discovery->statusCode);
    }

    public function testIdentityValidationRejectsDiscoverySignedForAnotherPath(): void
    {
        $identityKey = self::IDENTITY_KEY;
        $identityHeaders = FakeIdentityVerifier::signedHeaders($identityKey, '/invoke/Greeter/greet');
        $endpoint = Endpoint::create($this->unusedCore(), [Service::new('Greeter')], identityKeys: [$identityKey]);

        $discovery = $endpoint->handleRequest([
            'REQUEST_URI' => '/discover',
            'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v4+json',
            ...RequestIdentityTestSupport::serverHeaders($identityHeaders),
        ], '');

        self::assertSame(401, $discovery->statusCode);
    }

    public function testIdentityValidationAllowsSignedDiscovery(): void
    {
        $identityKey = self::IDENTITY_KEY;
        $identityHeaders = FakeIdentityVerifier::signedHeaders($identityKey, '/discover');
        $endpoint = Endpoint::create($this->unusedCore(), [Service::new('Greeter')], identityKeys: [$identityKey]);

        $discovery = $endpoint->handleRequest([
            'REQUEST_URI' => '/discover',
            'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v4+json',
            ...RequestIdentityTestSupport::serverHeaders($identityHeaders),
        ], '');

        self::assertSame(200, $discovery->statusCode);
        self::assertSame([['content-type', 'application/vnd.restate.endpointmanifest.v4+json']], $discovery->headers);
        self::assertStringContainsString('"Greeter"', $discovery->body);
    }

    public function testIdentityValidationStillAnswersUnknownRoutesWith404(): void
    {
        $identityKey = self::IDENTITY_KEY;
        $endpoint = Endpoint::create($this->unusedCore(), [Service::new('Greeter')], identityKeys: [$identityKey]);

        self::assertSame(404, $endpoint->handleRequest(['REQUEST_URI' => '/nope'], '')->statusCode);
    }

    public function testHandleRequestReportsUnhandledHandlerExceptionAsRetryableError(): void
    {
        $chunks = [];
        $invocation = $this->successfulInvocationMock([['content-type', 'application/vnd.restate.invocation.v6']]);
        $invocation->expects(self::once())
            ->method('notifyError')
            ->with('boom')
            ->willReturnCallback(static function () use (&$chunks): void {
                $chunks[] = 'retryable-error-frame';
            });
        $invocation->expects(self::never())->method('writeOutputFailure');
        $invocation->expects(self::never())->method('end');
        $invocation->method('takeOutput')->willReturnCallback(static function () use (&$chunks): ?string {
            if ($chunks === []) {
                return null;
            }

            return array_shift($chunks);
        });
        $endpoint = Endpoint::create($this->coreForInvocation($invocation), [
            Service::new('Greeter')->handler('explode', static fn (Context $ctx, mixed $_): never => throw new \RuntimeException('boom')),
        ]);

        $response = $endpoint->handleRequest(['REQUEST_URI' => '/invoke/Greeter/explode', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], 'request-body');

        self::assertSame('', $response->body);
    }

    /** A handler's RetryableError reports its retry-after delay and status code; any other exception is 500. */
    public function testHandleRequestPassesTheRetryAfterAndStatusCodeOfARetryableErrorFromTheHandler(): void
    {
        $reported = [];
        $invocation = $this->successfulInvocation([['content-type', 'application/vnd.restate.invocation.v6']]);
        $invocation->method('notifyError')->willReturnCallback(
            static function (string $message, string $stacktrace = '', ?int $retryAfterMillis = null, int $code = 500) use (&$reported): void {
                $reported[] = [$message, $retryAfterMillis, $code];
            },
        );
        $endpoint = Endpoint::create($this->coreForInvocation($invocation), [
            Service::new('Greeter')
                ->handler('rateLimited', static fn (Context $ctx, mixed $_): never => throw new RetryableError('rate limited', retryAfterMillis: 2_000))
                ->handler('unavailable', static fn (Context $ctx, mixed $_): never => throw new RetryableError('unavailable', 503, ['reason' => 'maintenance']))
                ->handler('explode', static fn (Context $ctx, mixed $_): never => throw new \RuntimeException('boom')),
        ]);

        $endpoint->handleRequest(['REQUEST_URI' => '/invoke/Greeter/rateLimited', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], 'request-body');
        $endpoint->handleRequest(['REQUEST_URI' => '/invoke/Greeter/unavailable', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], 'request-body');
        $endpoint->handleRequest(['REQUEST_URI' => '/invoke/Greeter/explode', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], 'request-body');

        self::assertSame([['rate limited', 2_000, 500], ['unavailable', null, 503], ['boom', null, 500]], $reported);
    }

    public function testHandleRequestReportsJsonExceptionFromHandlerCodeAsRetryableError(): void
    {
        $invocation = $this->successfulInvocationMock([['content-type', 'application/vnd.restate.invocation.v6']]);
        $invocation->expects(self::once())->method('notifyError')->with(self::jsonError('{bad-json'));
        $invocation->expects(self::never())->method('writeOutputFailure');
        $invocation->expects(self::never())->method('end');
        $endpoint = Endpoint::create($this->coreForInvocation($invocation), [
            Service::new('Greeter')->handler('parse', static fn (Context $ctx, string $_): mixed => json_decode('{bad-json', flags: JSON_THROW_ON_ERROR)),
        ]);

        $endpoint->handleRequest(['REQUEST_URI' => '/invoke/Greeter/parse', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], 'request-body');
    }

    public function testHandleRequestReportsOutputEncodingFailureAsRetryableError(): void
    {
        $invocation = $this->successfulInvocationMock([['content-type', 'application/vnd.restate.invocation.v6']]);
        $invocation->expects(self::once())->method('notifyError')->with('Malformed UTF-8 characters, possibly incorrectly encoded');
        $invocation->expects(self::never())->method('writeOutputFailure');
        $invocation->expects(self::never())->method('writeOutputSuccess');
        $invocation->expects(self::never())->method('end');
        $endpoint = Endpoint::create($this->coreForInvocation($invocation), [
            Service::new('Greeter')->handler('latin1', static fn (Context $ctx, string $_): string => "\xB1"),
        ]);

        $endpoint->handleRequest(['REQUEST_URI' => '/invoke/Greeter/latin1', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], 'request-body');
    }

    public function testHandleRequestRejectsMalformedHandlerInputAsTerminal400(): void
    {
        $invocation = $this->successfulInvocationMock(
            [['content-type', 'application/vnd.restate.invocation.v6']],
            input: '{bad-json',
        );
        $invocation->expects(self::once())->method('writeOutputFailure')->with(400, 'Invalid JSON payload: ' . self::jsonError('{bad-json'), []);
        $invocation->expects(self::never())->method('notifyError');
        $invocation->expects(self::once())->method('end');
        $handlerInvoked = false;
        $endpoint = Endpoint::create($this->coreForInvocation($invocation), [
            Service::new('Greeter')->handler('greet', static function (Context $ctx, string $name) use (&$handlerInvoked): string {
                $handlerInvoked = true;

                return $name;
            }),
        ]);

        $endpoint->handleRequest(['REQUEST_URI' => '/invoke/Greeter/greet', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], 'request-body');

        self::assertFalse($handlerInvoked);
    }

    public function testHandleReplacesContentTypeAndPreservesDuplicateHeaders(): void
    {
        EndpointHeaderEmitter::reset();
        $endpoint = Endpoint::create($this->coreForInvocation($this->successfulInvocation([
            ['content-type', 'application/vnd.restate.invocation.v6'],
            ['set-cookie', 'a=1'],
            ['set-cookie', 'b=2'],
        ])), [
            Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}"),
        ]);

        ob_start();
        try {
            $endpoint->handle(['REQUEST_URI' => '/invoke/Greeter/greet', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], 'request-body');
            $body = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        self::assertSame('"Hello Mary"', $body);
        self::assertSame([
            ['content-type: application/vnd.restate.invocation.v6', true],
            ['set-cookie: a=1', false],
            ['set-cookie: b=2', false],
        ], EndpointHeaderEmitter::headers());
    }

    private function unusedCore(): Backend
    {
        return new FakeBackend();
    }

    private function coreForInvocation(Invocation $invocation): Backend
    {
        return new FakeBackend($invocation);
    }

    /**
     * @param list<array{0: string, 1: string}> $responseHeaders
     * @param list<array{0: string, 1: string}> $inputHeaders
     * @param string|InvocationInput $input the invocation input bytes, or the whole input
     */
    private function successfulInvocation(array $responseHeaders, array $inputHeaders = [], string|InvocationInput $input = '"Mary"'): Invocation&Stub
    {
        return self::succeeding(self::createStub(Invocation::class), $responseHeaders, $inputHeaders, $input);
    }

    /**
     * successfulInvocation() as a mock, for a test that sets expectations on it.
     *
     * @param list<array{0: string, 1: string}> $responseHeaders
     */
    private function successfulInvocationMock(array $responseHeaders, string|InvocationInput $input = '"Mary"'): Invocation&MockObject
    {
        return self::succeeding($this->createMock(Invocation::class), $responseHeaders, [], $input);
    }

    /**
     * @template T of Invocation&Stub
     *
     * @param T                                 $invocation
     * @param list<array{0: string, 1: string}> $responseHeaders
     * @param list<array{0: string, 1: string}> $inputHeaders
     *
     * @return T
     */
    private static function succeeding(Invocation&Stub $invocation, array $responseHeaders, array $inputHeaders, string|InvocationInput $input): Invocation&Stub
    {
        $chunks = [];
        $invocation->method('responseHead')->willReturn(new ResponseHead(202, $responseHeaders));
        $invocation->method('isReadyToExecute')->willReturn(true);
        $invocation->method('input')->willReturn($input instanceof InvocationInput ? $input : new InvocationInput('inv-1', 0, '', $inputHeaders, $input));
        $invocation->method('writeOutputSuccess')->willReturnCallback(static function (string $bytes) use (&$chunks): void {
            $chunks[] = $bytes;
        });
        $invocation->method('takeOutput')->willReturnCallback(static function () use (&$chunks): ?string {
            if ($chunks === []) {
                return null;
            }

            return array_shift($chunks);
        });

        return $invocation;
    }

    /** PHP's message for decoding $json: 8.6 adds where the error is ("Syntax error near location 1:2"). */
    private static function jsonError(string $json): string
    {
        json_decode($json);

        return json_last_error_msg();
    }
}

final class EndpointHeaderEmitter
{
    /** @var list<array{0: string, 1: bool, 2: int}> */
    private static array $headers = [];

    private static int $statusCode = 200;

    public static function reset(): void
    {
        self::$headers = [];
        self::$statusCode = 200;
    }

    public static function header(string $header, bool $replace = true, int $responseCode = 0): void
    {
        self::$headers[] = [$header, $replace, $responseCode];
        if ($responseCode !== 0) {
            self::$statusCode = $responseCode;
        }
    }

    public static function httpResponseCode(int $responseCode = 0): int
    {
        if ($responseCode !== 0) {
            self::$statusCode = $responseCode;
        }

        return self::$statusCode;
    }

    /** @return list<array{0: string, 1: bool}> */
    public static function headers(): array
    {
        $headers = [];
        foreach (self::$headers as [$header, $replace]) {
            $headers[] = [$header, $replace];
        }

        return $headers;
    }
}
