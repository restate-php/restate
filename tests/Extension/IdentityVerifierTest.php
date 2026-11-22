<?php

declare(strict_types=1);

namespace Restate\Tests\Extension;

use PHPUnit\Framework\Attributes\DataProvider;
use Restate\Endpoint;
use Restate\Internal\Backend\Extension\Backend;
use Restate\Internal\Backend\IdentityVerifier;
use Restate\Service;
use Restate\Tests\Support\RequestIdentityTestSupport;

/**
 * Request identity validation on the extension: sdk-shared-core's `IdentityVerifier` (`src/request_identity.rs`:
 * jsonwebtoken, EdDSA only, aud/exp/nbf required, zero leeway), the verifier the official TypeScript and Python SDKs
 * use, checked against tokens made as restate-server makes them (`crates/service-client/src/request_identity/v1.rs`).
 *
 * The verifier reads the system clock (jsonwebtoken's `get_current_timestamp()`), so tokens are signed relative to the
 * current second; each check is retried when the second changed while it ran, so the boundary cases (a token at its
 * `exp`, one second before its `nbf`) are exact.
 */
final class IdentityVerifierTest extends ExtensionTestCase
{
    private const string PATH = '/invoke/Greeter/greet';

    /** restate-server's test signing key (`request_identity/v1.rs`), PKCS#8 DER; the seed is the last 32 bytes. */
    private const string RESTATE_TEST_PRIVATE_KEY_DER = 'MC4CAQAwBQYDK2VwBCIEIPe++4ZTPQDF81otpoU/mOGHC2vOAVp9WbiCblvn3nXO';

    private const string RESTATE_TEST_IDENTITY_KEY = 'publickeyv1_AfQwmwfgEZhrWpvv8N52SHpRtZqGGaFr4AZN6qtYWSiY';

    /**
     * A token signed with restate-server's test key by jsonwebtoken (sdk-shared-core `request_identity.rs`, test
     * `bad_key`), valid from 1721662589 through 1721662709.
     */
    private const string RESTATE_SIGNED_JWT = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJFZERTQSIsImtpZCI6InB1YmxpY2tleXYxX0FmUXdtd2ZnRVpocldwdnY4TjUyU0hwUnRacUdHYUZyNEFaTjZxdFlXU2lZIn0.eyJhdWQiOiIvaW52b2tlL2ZvbyIsImV4cCI6MTcyMTY2MjcwOSwiaWF0IjoxNzIxNjYyNjQ5LCJuYmYiOjE3MjE2NjI1ODl9.UBReG_9cdFQ5VcaJxAV0rM8U_zaNw9kMXJZt691SiI0SWw7Ucmz5Zz3wtmVUc1jrkNsnTDhNEvOFGEZoKXTMCQ';

    /** The key sdk-shared-core's `bad_key` test configures: valid, but not the one that signed the token above. */
    private const string OTHER_RESTATE_IDENTITY_KEY = 'publickeyv1_ChjENKeMvCtRnqG2mrBK1HmPKufgFUc98K8B3ononQvp';

    public function testAcceptsTheTokenRestateSends(): void
    {
        [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair();

        self::assertNull(self::verifyNow([$identityKey], static fn (int $now): string => RequestIdentityTestSupport::jwt(
            $secretKey,
            RequestIdentityTestSupport::restateHeader($identityKey),
            [
                ...RequestIdentityTestSupport::restateClaims(self::PATH, $now),
                'sub' => 'alice',
                'target' => ['method' => 'POST', 'path_and_query' => '/base' . self::PATH . '?tenant=a'],
            ],
        )));
    }

    public function testRestateTestKeyEncodesToTheKidRestateDerives(): void
    {
        [$identityKey] = RequestIdentityTestSupport::keyPair(self::restateTestSeed());

        self::assertSame(self::RESTATE_TEST_IDENTITY_KEY, $identityKey);
    }

    /** @return iterable<string, array{0: int}> seconds from now to the token's iat */
    public static function restateTokenValidityWindow(): iterable
    {
        // restate-server signs nbf = iat - 60 and exp = iat + 60 (restateClaims()).
        yield 'at nbf' => [60];
        yield 'at iat' => [0];
        yield 'at exp' => [-60];
    }

    #[DataProvider('restateTokenValidityWindow')]
    public function testAcceptsATokenSignedWithRestatesKeyWithinItsValidityWindow(int $iatFromNow): void
    {
        self::assertNull(self::verifyNow([self::RESTATE_TEST_IDENTITY_KEY], static fn (int $now): string => self::restateSignedJwt('/invoke/foo', $now + $iatFromNow), '/invoke/foo'));
    }

    /** @return iterable<string, array{0: int}> seconds from now to the token's iat */
    public static function outsideRestateTokenValidityWindow(): iterable
    {
        yield 'one second before nbf' => [61];
        yield 'one second after exp' => [-61];
    }

    #[DataProvider('outsideRestateTokenValidityWindow')]
    public function testRejectsATokenSignedWithRestatesKeyOutsideItsValidityWindow(int $iatFromNow): void
    {
        self::assertNotNull(self::verifyNow([self::RESTATE_TEST_IDENTITY_KEY], static fn (int $now): string => self::restateSignedJwt('/invoke/foo', $now + $iatFromNow), '/invoke/foo'));
    }

    public function testRejectsATokenSignedWithRestatesKeyByAnotherKey(): void
    {
        self::assertNotNull(self::verifyNow([self::OTHER_RESTATE_IDENTITY_KEY], static fn (int $now): string => self::restateSignedJwt('/invoke/foo', $now), '/invoke/foo'));
    }

    public function testRejectsTheTokenJsonwebtokenSignedWithRestatesKeyOnceExpired(): void
    {
        self::assertSame(
            'invalid JWT: ExpiredSignature',
            self::verifier([self::RESTATE_TEST_IDENTITY_KEY])->verify(self::headers(self::RESTATE_SIGNED_JWT), '/invoke/foo'),
        );
    }

    public function testAcceptsATokenSignedByAnyConfiguredKey(): void
    {
        [$firstKey] = RequestIdentityTestSupport::keyPair();
        [$secondKey, $secondSecret] = RequestIdentityTestSupport::keyPair();

        self::assertNull(self::verifyNow([$firstKey, $secondKey], static fn (int $now): string => self::jwt($secondSecret, $secondKey, $now)));
    }

    public function testAcceptsAKeyWhosePublicKeyStartsWithAZeroByte(): void
    {
        [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair(self::seedForPublicKeyWithLeadingZeroByte());

        self::assertStringStartsWith('publickeyv1_1', $identityKey);
        self::assertNull(self::verifyNow([$identityKey], static fn (int $now): string => self::jwt($secretKey, $identityKey, $now)));
    }

    public function testMatchesHeaderNamesCaseInsensitively(): void
    {
        [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair();

        self::assertNull(self::verifyNow([$identityKey], static fn (int $now): array => [
            ['X-Restate-Signature-Scheme', 'v1'],
            ['X-Restate-JWT-V1', self::jwt($secretKey, $identityKey, $now)],
        ]));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function audienceForPath(): iterable
    {
        yield 'mount prefix before /invoke is not part of the audience' => ['/invoke/Greeter/greet', '/base/invoke/Greeter/greet'];
        yield 'discovery' => ['/discover', '/discover'];
        yield 'mount prefix before /discover is not part of the audience' => ['/discover', '/base/discover'];
        yield 'deeper mount prefix before /discover is not part of the audience' => ['/discover', '/a/b/discover'];
        yield 'keyed invocation path is the whole path' => ['/invoke/Counter/alice/add', '/invoke/Counter/alice/add'];
        yield 'root' => ['/', '/'];
    }

    #[DataProvider('audienceForPath')]
    public function testAudienceIsTheRequestPathRelativeToTheDeployment(string $audience, string $path): void
    {
        [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair();

        self::assertNull(self::verifyNow([$identityKey], static fn (int $now): string => self::jwt($secretKey, $identityKey, $now, RequestIdentityTestSupport::restateClaims($audience, $now)), $path));
    }

    /** @return iterable<string, array{0: \Closure(int): array<string, mixed>}> claim changes, given the current second */
    public static function acceptedClaimChanges(): iterable
    {
        yield 'expires exactly now' => [static fn (int $now): array => ['exp' => $now]];
        yield 'valid from exactly now' => [static fn (int $now): array => ['nbf' => $now]];
        yield 'audience list with the request path' => [static fn (int $now): array => ['aud' => ['/invoke/Greeter/other', self::PATH]]];
        yield 'audience list with only the request path' => [static fn (int $now): array => ['aud' => [self::PATH]]];
        // jsonwebtoken checks only exp and nbf (and requires them); iat is neither required nor checked.
        yield 'missing iat' => [static fn (int $now): array => ['iat' => null]];
        yield 'negative iat' => [static fn (int $now): array => ['iat' => -1]];
        // jsonwebtoken rounds a fractional exp or nbf to the nearest second.
        yield 'fractional exp' => [static fn (int $now): array => ['exp' => $now + 59.5]];
        yield 'fractional nbf that rounds to now' => [static fn (int $now): array => ['nbf' => $now + 0.4]];
    }

    /** @param \Closure(int): array<string, mixed> $changes */
    #[DataProvider('acceptedClaimChanges')]
    public function testAcceptsClaims(\Closure $changes): void
    {
        [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair();

        self::assertNull(self::verifyNow([$identityKey], static fn (int $now): string => self::jwt($secretKey, $identityKey, $now, self::claims($now, $changes($now)))));
    }

    /** @return iterable<string, array{0: \Closure(int): array<string, mixed>}> claim changes (null: remove), given the current second */
    public static function rejectedClaimChanges(): iterable
    {
        yield 'expired' => [static fn (int $now): array => ['exp' => $now - 1]];
        yield 'long expired' => [static fn (int $now): array => ['exp' => $now - 3600, 'iat' => $now - 3660, 'nbf' => $now - 3720]];
        yield 'not yet valid' => [static fn (int $now): array => ['nbf' => $now + 1]];
        yield 'not yet valid, issued in the future' => [static fn (int $now): array => ['nbf' => $now + 60, 'iat' => $now + 120, 'exp' => $now + 180]];
        yield 'wrong audience' => [static fn (int $now): array => ['aud' => '/invoke/Greeter/other']];
        yield 'audience differs in case' => [static fn (int $now): array => ['aud' => '/invoke/greeter/greet']];
        yield 'audience includes the mount prefix' => [static fn (int $now): array => ['aud' => '/base/invoke/Greeter/greet']];
        yield 'audience list without the request path' => [static fn (int $now): array => ['aud' => ['/invoke/Greeter/other', '/discover']]];
        yield 'empty audience list' => [static fn (int $now): array => ['aud' => []]];
        yield 'audience object whose value is the request path' => [static fn (int $now): array => ['aud' => ['first' => self::PATH]]];
        yield 'audience list with a non-string member' => [static fn (int $now): array => ['aud' => [self::PATH, 1]]];
        yield 'numeric audience' => [static fn (int $now): array => ['aud' => 1]];
        yield 'missing audience' => [static fn (int $now): array => ['aud' => null]];
        yield 'missing exp' => [static fn (int $now): array => ['exp' => null]];
        yield 'missing nbf' => [static fn (int $now): array => ['nbf' => null]];
        yield 'string exp' => [static fn (int $now): array => ['exp' => (string) ($now + 60)]];
        yield 'string nbf' => [static fn (int $now): array => ['nbf' => (string) ($now - 60)]];
        yield 'fractional exp that rounds to before now' => [static fn (int $now): array => ['exp' => $now - 0.6]];
        yield 'negative nbf' => [static fn (int $now): array => ['nbf' => -1]];
    }

    /** @param \Closure(int): array<string, mixed> $changes */
    #[DataProvider('rejectedClaimChanges')]
    public function testRejectsClaims(\Closure $changes): void
    {
        [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair();

        self::assertNotNull(self::verifyNow([$identityKey], static fn (int $now): string => self::jwt($secretKey, $identityKey, $now, self::claims($now, $changes($now)))));
    }

    /** A JSON `null` claim is absent to jsonwebtoken, as a missing one. */
    public function testRejectsNullClaims(): void
    {
        [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair();

        foreach (['aud', 'exp', 'nbf'] as $claim) {
            self::assertNotNull(self::verifyNow([$identityKey], static fn (int $now): string => self::jwt($secretKey, $identityKey, $now, [...RequestIdentityTestSupport::restateClaims(self::PATH, $now), $claim => null])), $claim);
        }
    }

    public function testRejectsAClaimsSetThatIsNotAnObject(): void
    {
        [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair();

        self::assertNotNull(self::verifyNow([$identityKey], static function (int $now) use ($identityKey, $secretKey): string {
            $header = RequestIdentityTestSupport::base64UrlEncode(json_encode(RequestIdentityTestSupport::restateHeader($identityKey), JSON_THROW_ON_ERROR));
            $claims = RequestIdentityTestSupport::base64UrlEncode(json_encode(array_values(RequestIdentityTestSupport::restateClaims(self::PATH, $now)), JSON_THROW_ON_ERROR));

            return "{$header}.{$claims}." . RequestIdentityTestSupport::base64UrlEncode(sodium_crypto_sign_detached("{$header}.{$claims}", $secretKey));
        }));
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function rejectedHeaderChanges(): iterable
    {
        yield 'HS256' => [['alg' => 'HS256']];
        yield 'none' => [['alg' => 'none']];
        yield 'ES256' => [['alg' => 'ES256']];
        yield 'lowercase eddsa' => [['alg' => 'eddsa']];
        yield 'Ed25519' => [['alg' => 'Ed25519']];
        yield 'alg as list' => [['alg' => ['EdDSA']]];
        yield 'null alg' => [['alg' => null]];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('rejectedHeaderChanges')]
    public function testRejectsAnyAlgorithmButEdDsa(array $changes): void
    {
        [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair();

        self::assertNotNull(self::verifyNow([$identityKey], static fn (int $now): string => RequestIdentityTestSupport::jwt(
            $secretKey,
            [...RequestIdentityTestSupport::restateHeader($identityKey), ...$changes],
            RequestIdentityTestSupport::restateClaims(self::PATH, $now),
        )));
    }

    public function testRejectsAHeaderWithoutAlg(): void
    {
        [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair();

        self::assertNotNull(self::verifyNow([$identityKey], static fn (int $now): string => RequestIdentityTestSupport::jwt($secretKey, ['typ' => 'JWT', 'kid' => $identityKey], RequestIdentityTestSupport::restateClaims(self::PATH, $now))));
    }

    public function testRejectsAValidTokenSignedByAnotherKey(): void
    {
        [$configuredKey] = RequestIdentityTestSupport::keyPair();
        [$otherKey, $otherSecret] = RequestIdentityTestSupport::keyPair();

        self::assertSame('invalid JWT: InvalidSignature', self::verifyNow([$configuredKey], static fn (int $now): string => self::jwt($otherSecret, $otherKey, $now)));
    }

    public function testRejectsATokenThatNamesTheConfiguredKeyButIsSignedByAnother(): void
    {
        [$configuredKey] = RequestIdentityTestSupport::keyPair();
        [, $otherSecret] = RequestIdentityTestSupport::keyPair();

        self::assertNotNull(self::verifyNow([$configuredKey], static fn (int $now): string => self::jwt($otherSecret, $configuredKey, $now)));
    }

    public function testRejectsClaimsChangedAfterSigning(): void
    {
        [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair();

        self::assertNotNull(self::verifyNow([$identityKey], static function (int $now) use ($identityKey, $secretKey): string {
            [$header, , $signature] = explode('.', self::jwt($secretKey, $identityKey, $now, RequestIdentityTestSupport::restateClaims('/invoke/Greeter/other', $now)));
            [, $claims] = explode('.', self::jwt($secretKey, $identityKey, $now));

            return "{$header}.{$claims}.{$signature}";
        }));
    }

    /** @return iterable<string, array{0: \Closure(string): string}> */
    public static function malformedTokens(): iterable
    {
        $signature = static fn (string $jwt): string => explode('.', $jwt)[2];
        $withSignature = static fn (string $jwt, string $signature): string => implode('.', [...array_slice(explode('.', $jwt), 0, 2), $signature]);
        $signatureBytes = static fn (string $jwt): string => (string) base64_decode(strtr($signature($jwt), '-_', '+/'), true);

        yield 'empty' => [static fn (string $jwt): string => ''];
        yield 'two parts' => [static fn (string $jwt): string => implode('.', array_slice(explode('.', $jwt), 0, 2))];
        yield 'four parts' => [static fn (string $jwt): string => "{$jwt}." . $signature($jwt)];
        yield 'empty signature' => [static fn (string $jwt): string => $withSignature($jwt, '')];
        yield 'signature one byte short' => [static fn (string $jwt): string => $withSignature($jwt, RequestIdentityTestSupport::base64UrlEncode(substr($signatureBytes($jwt), 0, 63)))];
        yield 'signature one byte long' => [static fn (string $jwt): string => $withSignature($jwt, RequestIdentityTestSupport::base64UrlEncode($signatureBytes($jwt) . "\0"))];
        yield 'signature with base64 padding' => [static fn (string $jwt): string => "{$jwt}=="];
        yield 'signature in standard base64' => [static fn (string $jwt): string => $withSignature($jwt, rtrim(base64_encode($signatureBytes($jwt)), '='))];
        yield 'signature with non-canonical trailing bits' => [static fn (string $jwt): string => $withSignature($jwt, self::withNonCanonicalTrailingBits($signature($jwt)))];
        yield 'signature with embedded whitespace' => [static fn (string $jwt): string => $withSignature($jwt, substr($signature($jwt), 0, 40) . ' ' . substr($signature($jwt), 40))];
        yield 'signature not base64' => [static fn (string $jwt): string => $withSignature($jwt, str_repeat('*', 86))];
        yield 'signature of a length base64 cannot have' => [static fn (string $jwt): string => substr($jwt, 0, -1)];
        yield 'header not base64' => [static fn (string $jwt): string => '*' . $jwt];
        yield 'header not JSON' => [static fn (string $jwt): string => RequestIdentityTestSupport::base64UrlEncode('not json') . strstr($jwt, '.')];
        yield 'header JSON but not an object' => [static fn (string $jwt): string => RequestIdentityTestSupport::base64UrlEncode('"EdDSA"') . strstr($jwt, '.')];
        yield 'claims not JSON' => [static fn (string $jwt): string => self::withClaimsPart($jwt, 'not json')];
        yield 'claims JSON but not an object' => [static fn (string $jwt): string => self::withClaimsPart($jwt, '42')];
    }

    /** @param \Closure(string): string $mutate */
    #[DataProvider('malformedTokens')]
    public function testRejectsMalformedTokens(\Closure $mutate): void
    {
        // A signature whose base64url form has no '-' or '_' reads the same in standard base64 (about 1 in 15 random
        // keys), which would make the "standard base64" case vacuous; use a key whose signature has one.
        do {
            [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair();
        } while (strpbrk(explode('.', self::jwt($secretKey, $identityKey, self::now()))[2], '-_') === false);
        self::assertNull(self::verifyNow([$identityKey], static fn (int $now): string => self::jwt($secretKey, $identityKey, $now)), 'unmodified token must verify');

        self::assertNotNull(self::verifyNow([$identityKey], static fn (int $now): string => $mutate(self::jwt($secretKey, $identityKey, $now))));
    }

    /** @return iterable<string, array{0: \Closure(string): list<array{0: string, 1: string}>, 1: string}> */
    public static function rejectedSchemeHeaders(): iterable
    {
        yield 'no identity headers' => [static fn (string $jwt): array => [], 'missing header: x-restate-signature-scheme'];
        yield 'token without scheme' => [static fn (string $jwt): array => [['x-restate-jwt-v1', $jwt]], 'missing header: x-restate-signature-scheme'];
        yield 'unsigned scheme' => [static fn (string $jwt): array => [['x-restate-signature-scheme', 'unsigned'], ['x-restate-jwt-v1', $jwt]], 'got unsigned request, expecting only signed requests matching the configured keys'];
        yield 'unknown scheme' => [static fn (string $jwt): array => [['x-restate-signature-scheme', 'v2'], ['x-restate-jwt-v1', $jwt]], 'bad x-restate-signature-scheme header, unexpected value v2'];
        yield 'scheme in upper case' => [static fn (string $jwt): array => [['x-restate-signature-scheme', 'V1'], ['x-restate-jwt-v1', $jwt]], 'bad x-restate-signature-scheme header, unexpected value V1'];
        yield 'v1 scheme without token' => [static fn (string $jwt): array => [['x-restate-signature-scheme', 'v1']], 'missing header: x-restate-jwt-v1'];
        yield 'token in another header' => [static fn (string $jwt): array => [['x-restate-signature-scheme', 'v1'], ['authorization', "Bearer {$jwt}"]], 'missing header: x-restate-jwt-v1'];
    }

    /** @param \Closure(string): list<array{0: string, 1: string}> $headers */
    #[DataProvider('rejectedSchemeHeaders')]
    public function testRejectsRequestsWithoutAV1Signature(\Closure $headers, string $reason): void
    {
        [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair();

        self::assertSame($reason, self::verifier([$identityKey])->verify($headers(self::jwt($secretKey, $identityKey, self::now())), self::PATH));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function malformedIdentityKeys(): iterable
    {
        $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(str_repeat("\1", 32)));
        $base58 = RequestIdentityTestSupport::base58Encode($publicKey);

        yield 'no prefix' => [$base58, 'expected to start with publickeyv1_'];
        yield 'prefix in another case' => ['PublicKeyV1_' . $base58, 'expected to start with publickeyv1_'];
        yield 'another version prefix' => ['publickeyv2_' . $base58, 'expected to start with publickeyv1_'];
        yield 'leading whitespace' => [' publickeyv1_' . $base58, 'expected to start with publickeyv1_'];
        yield 'prefix only' => ['publickeyv1_', 'should have length of 32, was 0'];
        yield '31 bytes' => ['publickeyv1_' . RequestIdentityTestSupport::base58Encode(substr($publicKey, 0, 31)), 'should have length of 32, was 31'];
        yield '33 bytes' => ['publickeyv1_' . RequestIdentityTestSupport::base58Encode($publicKey . "\0"), 'should have length of 32, was 33'];
        yield 'extra leading zero byte' => ['publickeyv1_1' . $base58, 'should have length of 32, was 33'];
        yield 'base58 excludes 0' => ['publickeyv1_0' . substr($base58, 1), 'cannot decode the public key with base58'];
        yield 'base58 excludes O' => ['publickeyv1_O' . substr($base58, 1), 'cannot decode the public key with base58'];
        yield 'base58 excludes I' => ['publickeyv1_I' . substr($base58, 1), 'cannot decode the public key with base58'];
        yield 'base58 excludes l' => ['publickeyv1_l' . substr($base58, 1), 'cannot decode the public key with base58'];
        yield 'base64 character' => ['publickeyv1_' . substr($base58, 0, -1) . '+', 'cannot decode the public key with base58'];
        yield 'trailing whitespace' => ['publickeyv1_' . $base58 . ' ', 'cannot decode the public key with base58'];
        yield 'non-ASCII' => ['publickeyv1_' . substr($base58, 0, -1) . 'é', 'cannot decode the public key with base58'];
        yield 'base64 of the key' => ['publickeyv1_' . base64_encode($publicKey), 'cannot decode the public key with base58'];
    }

    #[DataProvider('malformedIdentityKeys')]
    public function testRejectsMalformedIdentityKeys(string $identityKey, string $message): void
    {
        [$validKey] = RequestIdentityTestSupport::keyPair();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Invalid request identity key #2: .*' . preg_quote($message, '/') . '/');

        new Backend()->identityVerifier([$validKey, $identityKey]);
    }

    public function testAVerifierIsReusedAcrossRequests(): void
    {
        [$identityKey, $secretKey] = RequestIdentityTestSupport::keyPair();
        $verifier = self::verifier([$identityKey]);

        for ($i = 0; $i < 3; $i++) {
            self::assertNull(self::verifyNow($verifier, static fn (int $now): string => self::jwt($secretKey, $identityKey, $now)));
            self::assertNotNull($verifier->verify(self::headers(self::RESTATE_SIGNED_JWT), self::PATH));
        }
    }

    public function testAnEndpointOnTheExtensionRejectsAnUnsignedRequestAndServesASignedOne(): void
    {
        [$identityKey, $identityHeaders] = RequestIdentityTestSupport::signedIdentity('/discover');
        $endpoint = Endpoint::create(new Backend(), [Service::new('Greeter')], identityKeys: [$identityKey]);
        $accept = ['accept', 'application/vnd.restate.endpointmanifest.v4+json'];

        $unsigned = $endpoint->handleHttpRequest('/discover', [$accept], '');
        $signed = $endpoint->handleHttpRequest('/discover', [$accept, ...RequestIdentityTestSupport::headerList($identityHeaders)], '');

        self::assertSame(401, $unsigned->statusCode);
        self::assertSame('{"error":"Invalid Restate request identity."}', $unsigned->body);
        self::assertSame(200, $signed->statusCode);
        self::assertStringContainsString('"Greeter"', $signed->body);
    }

    public function testAnEndpointOnTheExtensionRejectsARequestWhosePathIsNotUtf8With401(): void
    {
        [$identityKey, $identityHeaders] = RequestIdentityTestSupport::signedIdentity('/invoke/Greeter/greet');
        $endpoint = Endpoint::create(new Backend(), [Service::new('Greeter')], identityKeys: [$identityKey]);

        $response = $endpoint->handleHttpRequest("/invoke/Greeter/gr\xffeet", RequestIdentityTestSupport::headerList($identityHeaders), '');

        self::assertSame(401, $response->statusCode);
    }

    /** @param non-empty-list<string> $identityKeys */
    private static function verifier(array $identityKeys): IdentityVerifier
    {
        return new Backend()->identityVerifier($identityKeys);
    }

    /**
     * Verifies the request $request builds for the current second, again when the second changed while it ran.
     *
     * @param non-empty-list<string>|IdentityVerifier $verifier
     * @param \Closure(int): (string|list<array{0: string, 1: string}>) $request a JWT or the identity headers, given the current second
     */
    private static function verifyNow(array|IdentityVerifier $verifier, \Closure $request, string $path = self::PATH): ?string
    {
        $verifier = $verifier instanceof IdentityVerifier ? $verifier : self::verifier($verifier);
        for ($attempt = 1; ; $attempt++) {
            $now = self::now();
            $built = $request($now);
            $result = $verifier->verify(is_string($built) ? self::headers($built) : $built, $path);
            if (self::now() === $now || $attempt === 3) {
                return $result;
            }
        }
    }

    /** The wall-clock second, as the verifier's `SystemTime::now()` reads it (time() may use a coarser clock). */
    private static function now(): int
    {
        return (int) microtime(true);
    }

    /**
     * restate-server's claims for the current second with $changes applied; a null change removes the claim.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private static function claims(int $now, array $changes): array
    {
        return array_filter([...RequestIdentityTestSupport::restateClaims(self::PATH, $now), ...$changes], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param non-empty-string $secretKey
     * @param array<string, mixed>|null $claims
     */
    private static function jwt(string $secretKey, string $identityKey, int $now, ?array $claims = null): string
    {
        return RequestIdentityTestSupport::jwt(
            $secretKey,
            RequestIdentityTestSupport::restateHeader($identityKey),
            $claims ?? RequestIdentityTestSupport::restateClaims(self::PATH, $now),
        );
    }

    /** A token restate-server's test key signs for $audience, issued at $iat. */
    private static function restateSignedJwt(string $audience, int $iat): string
    {
        [, $secretKey] = RequestIdentityTestSupport::keyPair(self::restateTestSeed());

        return RequestIdentityTestSupport::jwt($secretKey, RequestIdentityTestSupport::restateHeader(self::RESTATE_TEST_IDENTITY_KEY), RequestIdentityTestSupport::restateClaims($audience, $iat));
    }

    /** @return non-empty-string */
    private static function restateTestSeed(): string
    {
        $seed = substr((string) base64_decode(self::RESTATE_TEST_PRIVATE_KEY_DER, true), -32);
        self::assertSame(32, strlen($seed));

        return $seed;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function headers(string $jwt): array
    {
        return RequestIdentityTestSupport::headerList(RequestIdentityTestSupport::headers($jwt));
    }

    /** @return non-empty-string */
    private static function seedForPublicKeyWithLeadingZeroByte(): string
    {
        for ($i = 0; ; $i++) {
            $seed = hash('sha256', "restate-php-identity-seed-{$i}", true);
            if (sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed))[0] === "\0") {
                return $seed;
            }
        }
    }

    private static function withClaimsPart(string $jwt, string $json): string
    {
        $parts = explode('.', $jwt);
        $parts[1] = RequestIdentityTestSupport::base64UrlEncode($json);

        return implode('.', $parts);
    }

    /**
     * A 64-byte signature is 86 base64url characters; the last one carries 2 data bits and 4 padding bits that
     * must be zero. Setting one of those keeps the decoded bytes but changes the token text.
     */
    private static function withNonCanonicalTrailingBits(string $signature): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $last = strpos($alphabet, $signature[-1]);
        self::assertIsInt($last);

        return substr($signature, 0, -1) . $alphabet[$last | 1];
    }
}
