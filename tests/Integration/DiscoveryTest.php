<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\IntegrationTestCase;

final class DiscoveryTest extends IntegrationTestCase
{
    /**
     * The harness registered the endpoint before the first test; Restate stores the protocol range the manifest
     * advertised (the extension's, `Restate\Extension\protocol_versions()`), having accepted it for this server version.
     */
    public function testRestateRegisteredTheEndpointWithTheProtocolVersionsTheCoreReports(): void
    {
        $response = HttpClient::get(self::restate()->adminUrl() . '/deployments');
        self::assertTrue($response->isSuccessful(), "Failed to list deployments: HTTP {$response->status} {$response->body}");

        $deployments = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)['deployments'] ?? null;
        self::assertIsArray($deployments);
        // StatePreloadTest registers a second deployment, under /state-preload.
        $deployments = array_values(array_filter($deployments, static fn (mixed $deployment): bool => is_array($deployment) && !str_ends_with((string) ($deployment['uri'] ?? ''), '/state-preload')));
        self::assertCount(1, $deployments);
        self::assertSame(7, $deployments[0]['min_protocol_version'] ?? null);
        self::assertSame(8, $deployments[0]['max_protocol_version'] ?? null);
    }

    public function testManifestIncludesExplicitPayloadMetadataAndSchema(): void
    {
        $response = HttpClient::get(
            self::restate()->endpointUrl() . '/discover',
            ['accept' => 'application/vnd.restate.endpointmanifest.v4+json'],
        );
        self::assertTrue($response->isSuccessful(), "Failed to fetch discovery manifest: HTTP {$response->status} {$response->body}");

        $manifest = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['services'] ?? null);

        $handler = null;
        foreach ($manifest['services'] as $service) {
            if (($service['name'] ?? null) !== 'Greeter') {
                continue;
            }
            foreach ($service['handlers'] as $candidate) {
                if (($candidate['name'] ?? null) === 'schemaEcho') {
                    $handler = $candidate;
                    break 2;
                }
            }
        }

        self::assertIsArray($handler, 'Greeter/schemaEcho is missing from the discovery manifest');
        self::assertTrue($handler['input']['required'] ?? null);
        self::assertSame('application/json', $handler['input']['contentType'] ?? null);
        self::assertSame('string', $handler['input']['jsonSchema']['properties']['name']['type'] ?? null);
        self::assertSame('application/json', $handler['output']['contentType'] ?? null);
        self::assertSame('string', $handler['output']['jsonSchema']['properties']['name']['type'] ?? null);
    }
}
