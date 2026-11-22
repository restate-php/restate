<?php

declare(strict_types=1);

namespace Restate\Tests\Support;

/**
 * Restate request identities as restate-server makes them (`crates/service-client/src/request_identity/v1.rs`): Ed25519
 * key pairs, `publickeyv1_<base58>` identity keys, and EdDSA JWTs signed with ext-sodium.
 */
final class RequestIdentityTestSupport
{
    private const string BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /**
     * A fresh identity key and the identity headers Restate would send now for $audience.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function signedIdentity(string $audience): array
    {
        [$identityKey, $secretKey] = self::keyPair();

        return [$identityKey, self::headers(self::jwt($secretKey, self::restateHeader($identityKey), self::restateClaims($audience, time())))];
    }

    /**
     * A fresh Ed25519 key pair: the identity key in Restate's `publickeyv1_` + base58 format, and the secret key.
     *
     * @param non-empty-string|null $seed
     *
     * @return array{0: string, 1: non-empty-string}
     */
    public static function keyPair(?string $seed = null): array
    {
        $keypair = $seed === null ? sodium_crypto_sign_keypair() : sodium_crypto_sign_seed_keypair($seed);

        return [self::identityKey(sodium_crypto_sign_publickey($keypair)), sodium_crypto_sign_secretkey($keypair)];
    }

    public static function identityKey(string $publicKey): string
    {
        return 'publickeyv1_' . self::base58Encode($publicKey);
    }

    /**
     * The JOSE header restate-server signs with (`crates/service-client/src/request_identity/v1.rs`).
     *
     * @return array<string, mixed>
     */
    public static function restateHeader(string $identityKey): array
    {
        return ['typ' => 'JWT', 'alg' => 'EdDSA', 'kid' => $identityKey];
    }

    /**
     * The claims restate-server signs with its default 60 s expiration leeway.
     *
     * @return array<string, mixed>
     */
    public static function restateClaims(string $audience, int $now): array
    {
        return ['aud' => $audience, 'exp' => $now + 60, 'iat' => $now, 'nbf' => $now - 60];
    }

    /**
     * @param non-empty-string $secretKey
     * @param array<array-key, mixed> $header
     * @param array<array-key, mixed> $claims
     */
    public static function jwt(string $secretKey, array $header, array $claims): string
    {
        $payload = self::base64UrlEncode(json_encode((object) $header, JSON_THROW_ON_ERROR))
            . '.' . self::base64UrlEncode(json_encode((object) $claims, JSON_THROW_ON_ERROR));

        return "{$payload}." . self::base64UrlEncode(sodium_crypto_sign_detached($payload, $secretKey));
    }

    /** @return array<string, string> */
    public static function headers(string $jwt): array
    {
        return [
            'x-restate-signature-scheme' => 'v1',
            'x-restate-jwt-v1' => $jwt,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return list<array{0: string, 1: string}>
     */
    public static function headerList(array $headers): array
    {
        $list = [];
        foreach ($headers as $name => $value) {
            $list[] = [$name, $value];
        }

        return $list;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function serverHeaders(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, list<string>>
     */
    public static function psrHeaders(array $headers): array
    {
        $psrHeaders = [];
        foreach ($headers as $name => $value) {
            $psrHeaders[$name] = [$value];
        }

        return $psrHeaders;
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base58Encode(string $bytes): string
    {
        $zeroes = 0;
        $length = strlen($bytes);
        while ($zeroes < $length && $bytes[$zeroes] === "\0") {
            $zeroes++;
        }

        $digits = [];
        for ($offset = $zeroes; $offset < $length; $offset++) {
            $carry = ord($bytes[$offset]);
            for ($i = count($digits) - 1; $i >= 0; $i--) {
                $carry += $digits[$i] << 8;
                $digits[$i] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
            while ($carry > 0) {
                array_unshift($digits, $carry % 58);
                $carry = intdiv($carry, 58);
            }
        }

        $encoded = str_repeat('1', $zeroes);
        foreach ($digits as $digit) {
            $encoded .= self::BASE58_ALPHABET[$digit];
        }

        return $encoded;
    }
}
