<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Support;

use Restate\Testing\RestateContainer;
use Restate\Testing\RestateProcess;

/** Request-identity key pairs for tests whose Restate signs its requests (RequestIdentityTest, ClockSkewTest). */
final class IdentityKeys
{
    /** Where the tests mount the private key in a Restate container (a Restate process reads it where it is). */
    public const string PEM_PATH_IN_CONTAINER = '/etc/restate/request-identity.pem';

    /** Restate's setting for that file (the conformance suite's top-level one is deprecated on 1.7.14 and 1.8.1). */
    public const string PEM_FILE_VARIABLE = 'RESTATE_WORKER__INVOKER__REQUEST_IDENTITY_PRIVATE_KEY_PEM_FILE';

    private const string BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    private function __construct()
    {
    }

    /** The suite's Restate ({@see IntegrationRestate}), signing its requests with the private key in `$pemFile`. */
    public static function restate(string $pemFile): RestateContainer|RestateProcess
    {
        return IntegrationRestate::withFile(self::PEM_FILE_VARIABLE, $pemFile, self::PEM_PATH_IN_CONTAINER);
    }

    /**
     * A fresh Ed25519 key pair: the private key as the PKCS#8 PEM restate-server reads, the public key as the
     * `publickeyv1_<base58>` identity key the SDKs take.
     *
     * @return array{string, string}
     */
    public static function keyPair(): array
    {
        $keyPair = sodium_crypto_sign_keypair();
        $seed = substr(sodium_crypto_sign_secretkey($keyPair), 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);
        // PKCS#8 PrivateKeyInfo { version 0, algorithm id-Ed25519 (1.3.101.112), OCTET STRING { OCTET STRING seed } }.
        $der = hex2bin('302e020100300506032b657004220420') . $seed;
        $pem = "-----BEGIN PRIVATE KEY-----\n" . base64_encode($der) . "\n-----END PRIVATE KEY-----\n";

        return [$pem, 'publickeyv1_' . self::base58Encode(sodium_crypto_sign_publickey($keyPair))];
    }

    private static function base58Encode(string $bytes): string
    {
        $digits = [];
        foreach (str_split($bytes) as $byte) {
            $carry = ord($byte);
            foreach ($digits as $i => $digit) {
                $carry += $digit * 256;
                $digits[$i] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
            while ($carry > 0) {
                $digits[] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
        }

        $encoded = str_repeat('1', strspn($bytes, "\0"));
        foreach (array_reverse($digits) as $digit) {
            $encoded .= self::BASE58_ALPHABET[$digit];
        }

        return $encoded;
    }
}
