<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Restate\Testing\DeploymentRegistrationException;
use Restate\Testing\RestateTestEnvironment;
use Restate\Testing\TestEnvironmentException;
use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\IdentityKeys;
use Restate\Tests\Integration\Support\IntegrationEndpoint;

/**
 * Request identity end to end: a Restate server that signs its requests with an Ed25519 key
 * (`worker.invoker.request-identity-private-key-pem-file`; the top-level `request-identity-private-key-pem-file` the
 * upstream suite sets is deprecated on 1.7.14 and 1.8.1), and the integration endpoint configured with identity keys
 * (`RESTATE_PHP_TEST_IDENTITY_KEYS`, read by `Support/endpoint.php`). Each test starts its own environment, because
 * the shared one runs an unsigned Restate and an open endpoint.
 */
final class RequestIdentityTest extends TestCase
{
    private ?RestateTestEnvironment $environment = null;

    private ?string $pemFile = null;

    #[\Override]
    protected function tearDown(): void
    {
        $this->environment?->stop();
        if ($this->pemFile !== null) {
            @unlink($this->pemFile);
        }
    }

    public function testTheEndpointServesRestateWhenItTrustsRestatesSigningKey(): void
    {
        [$pem, $identityKey] = IdentityKeys::keyPair();
        $this->environment = $this->start($pem, $identityKey);

        $key = 'identity-' . bin2hex(random_bytes(4));
        $add = HttpClient::postJson($this->environment->ingressUrl() . "/Counter/{$key}/add", '3');
        self::assertSame(200, $add->status, $add->body);
        self::assertSame('{"oldValue":0,"newValue":3}', $add->body);
        $get = HttpClient::postJson($this->environment->ingressUrl() . "/Counter/{$key}/get", null);
        self::assertSame(200, $get->status, $get->body);
        self::assertSame('3', $get->body);

        // The endpoint does verify: the same requests without Restate's signature are refused.
        self::assertSame(401, HttpClient::get($this->environment->endpointUrl() . '/discover')->status);
    }

    public function testRestateCannotRegisterAnEndpointThatTrustsAnotherKey(): void
    {
        [$pem] = IdentityKeys::keyPair();
        [, $otherIdentityKey] = IdentityKeys::keyPair();

        try {
            $this->environment = $this->start($pem, $otherIdentityKey);
            self::fail('Restate registered an endpoint that does not trust its signing key.');
        } catch (TestEnvironmentException $exception) {
            $registration = $exception->getPrevious();
            self::assertInstanceOf(DeploymentRegistrationException::class, $registration, $exception->getMessage());
            // Discovery is the first request Restate signs; the endpoint refuses it, and Restate passes the refusal on
            // (1.8.1: HTTP 500, META0003 "got status code '401 Unauthorized'" with the endpoint's response body).
            self::assertStringContainsString('401 Unauthorized', $registration->responseBody(), $exception->getMessage());
            self::assertStringContainsString('Invalid Restate request identity.', $registration->responseBody(), $exception->getMessage());
        }
    }

    private function start(string $pem, string $identityKey): RestateTestEnvironment
    {
        $pemFile = tempnam(sys_get_temp_dir(), 'restate-php-identity-');
        if ($pemFile === false) {
            throw new \RuntimeException('tempnam() failed.');
        }
        $this->pemFile = $pemFile;
        file_put_contents($this->pemFile, $pem);
        chmod($this->pemFile, 0o644);

        return RestateTestEnvironment::start(
            IntegrationEndpoint::get()->withEnvironment(['RESTATE_PHP_TEST_IDENTITY_KEYS' => $identityKey]),
            IdentityKeys::restate($this->pemFile),
        );
    }
}
