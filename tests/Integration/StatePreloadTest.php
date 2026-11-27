<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Restate\Testing\DeploymentRegistrationException;
use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\IntegrationTestCase;

/**
 * Selective state preloading (`InvocationConfiguration::$statePreload`). Service `PreloadedProfile` in
 * tests/Integration/Services/state_preload.php preloads only the key `hot`; it is served by a second endpoint under
 * /state-preload that this test registers. A key Restate preloaded is read in the first attempt; any other key is a
 * lazy fetch, which suspends the attempt in request/response mode, so its read returns in the second attempt.
 */
#[Group('runtime')]
final class StatePreloadTest extends IntegrationTestCase
{
    private static bool $registered = false;

    private static ?DeploymentRegistrationException $registrationFailure = null;

    public function testRestateStoresTheKeyList(): void
    {
        $failure = self::registrationFailure();

        self::assertNull($failure, $failure?->getMessage() ?? '');
        $service = self::admin('/services/PreloadedProfile');
        self::assertSame(['Partial' => ['hot']], $service['state_preload_policy'] ?? null, json_encode($service, JSON_THROW_ON_ERROR));
        $handlers = is_array($service['handlers'] ?? null) ? array_column($service['handlers'], null, 'name') : [];
        self::assertSame('All', $handlers['readEager']['state_preload_policy'] ?? null, json_encode($handlers, JSON_THROW_ON_ERROR));
    }

    public function testAPreloadedKeyIsReadEagerlyAndAnyOtherKeyIsFetched(): void
    {
        $failure = self::registrationFailure();
        $key = bin2hex(random_bytes(8));

        self::assertNull($failure, $failure?->getMessage() ?? '');
        self::assertInvocationReturns('"seeded"', "PreloadedProfile/{$key}/seed", null);
        self::assertInvocationReturns('{"value":"preloaded","attempt":1}', "PreloadedProfile/{$key}/read", '"hot"');
        self::assertInvocationReturns('{"value":"fetched","attempt":2}', "PreloadedProfile/{$key}/read", '"cold"');
        // Absent from the preloaded state is not the same as absent: the SDK cannot tell, so it asks Restate.
        self::assertInvocationReturns('{"value":null,"attempt":2}', "PreloadedProfile/{$key}/read", '"missing"');
        self::assertInvocationReturns('{"value":"fetched","attempt":1}', "PreloadedProfile/{$key}/readEager", '"cold"');
    }

    /** Registers the /state-preload endpoint once per run; null when Restate accepted it. */
    private static function registrationFailure(): ?DeploymentRegistrationException
    {
        if (!self::$registered) {
            self::$registered = true;
            try {
                self::restate()->registerEndpoint('/state-preload');
            } catch (DeploymentRegistrationException $exception) {
                self::$registrationFailure = $exception;
            }
        }

        return self::$registrationFailure;
    }

    /** @return array<string, mixed> */
    private static function admin(string $path): array
    {
        $response = HttpClient::get(self::restate()->adminUrl() . $path);
        self::assertTrue($response->isSuccessful(), "GET {$path} failed: HTTP {$response->status} {$response->body}");
        $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
