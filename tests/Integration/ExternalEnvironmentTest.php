<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\IntegrationEndpoint;
use Restate\Tests\Integration\Support\IntegrationTestCase;
use Restate\Testing\EndpointContainer;
use Restate\Testing\ExternalEndpoint;
use Restate\Testing\ExternalRestate;
use Restate\Testing\RestateTestEnvironment;
use Restate\Testing\TestEnvironmentException;

/**
 * {@see ExternalRestate} and {@see ExternalEndpoint} against a real Restate and endpoint: those of the shared
 * environment, which a second environment connects to as if they ran outside the harness. It registers the endpoint,
 * serves invocations, refuses the lifecycle calls, and leaves both running when it stops.
 */
final class ExternalEnvironmentTest extends IntegrationTestCase
{
    public function testAnEnvironmentThatRunsOutsideTheHarnessServesInvocations(): void
    {
        $managed = self::restate();
        $endpoint = IntegrationEndpoint::get();
        $restateUri = preg_replace(
            '#^http://[^/]+#',
            'http://' . $managed->testHostForRestate() . ':' . $managed->endpointPort(),
            $managed->endpointUrl(),
        );
        self::assertIsString($restateUri);

        $external = RestateTestEnvironment::start(
            ExternalEndpoint::at($managed->endpointUrl())
                ->withRestateUri($restateUri)
                ->withHttp2($endpoint instanceof EndpointContainer && $endpoint->http2),
            ExternalRestate::at($managed->adminUrl(), $managed->ingressUrl())->withTestHost($managed->testHostForRestate()),
        );
        try {
            self::assertSame($managed->ingressUrl(), $external->ingressUrl());
            $response = HttpClient::postJson($external->ingressUrl() . '/Greeter/greet', '"Ext"');
            self::assertSame('"Hello Ext"', $response->body, "HTTP {$response->status}");
            self::assertTrue($external->isEndpointRunning());

            try {
                $external->restartRestate();
                self::fail('restartRestate() of an external Restate did not refuse.');
            } catch (TestEnvironmentException $exception) {
                self::assertStringContainsString('ExternalRestate', $exception->getMessage());
            }
            try {
                $external->killEndpoint();
                self::fail('killEndpoint() of an external endpoint did not refuse.');
            } catch (TestEnvironmentException $exception) {
                self::assertStringContainsString('ExternalEndpoint', $exception->getMessage());
            }
        } finally {
            $external->stop();
        }

        self::assertTrue($managed->isEndpointRunning());
        self::assertInvocationReturns('"Hello Ext"', 'Greeter/greet', '"Ext"');
    }
}
