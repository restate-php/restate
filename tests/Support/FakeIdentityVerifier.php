<?php

declare(strict_types=1);

namespace Restate\Tests\Support;

use Restate\Internal\Backend\IdentityVerifier;

/**
 * {@see FakeBackend}'s identity verifier: accepts a request whose `x-restate-signature-scheme` is `v1` and whose
 * `x-restate-jwt-v1` is the fake token {@see signedHeaders()} made for one of its keys and exactly the path the
 * endpoint passes. The real verifier (sdk-shared-core's, through the extension) is tested in
 * tests/Extension/IdentityVerifierTest.php; this one lets endpoint tests check when and with what the endpoint asks.
 */
final class FakeIdentityVerifier implements IdentityVerifier
{
    public const string REJECTION = 'fake verifier: no token for this key and path';

    /** @var list<array{headers: list<array{0: string, 1: string}>, path: string}> every verify() call, in order */
    public array $calls = [];

    /** @param non-empty-list<string> $identityKeys */
    public function __construct(public readonly array $identityKeys)
    {
    }

    /**
     * Identity headers this verifier accepts for $path when it trusts $identityKey.
     *
     * @return array<string, string>
     */
    public static function signedHeaders(string $identityKey, string $path): array
    {
        return ['x-restate-signature-scheme' => 'v1', 'x-restate-jwt-v1' => "fake.{$identityKey}.{$path}"];
    }

    public function verify(array $headers, string $path): ?string
    {
        $this->calls[] = ['headers' => $headers, 'path' => $path];

        $header = static fn (string $name): ?string => array_find($headers, static fn (array $header): bool => strtolower($header[0]) === $name)[1] ?? null;
        if ($header('x-restate-signature-scheme') !== 'v1') {
            return self::REJECTION;
        }
        foreach ($this->identityKeys as $identityKey) {
            if ($header('x-restate-jwt-v1') === "fake.{$identityKey}.{$path}") {
                return null;
            }
        }

        return self::REJECTION;
    }
}
