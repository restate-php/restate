<?php

declare(strict_types=1);

namespace Restate\Tests\Soak\Support;

use Restate\Tests\Support\RequestIdentityTestSupport;
use Restate\Tests\Support\Workload\WorkloadRequest;

/**
 * Restate's request identity for a soak run without Restate: a key pair whose identity key the endpoint trusts
 * (RESTATE_PHP_TEST_IDENTITY_KEYS), and the signature headers Restate would send, one token per path re-signed every
 * 30 s (tokens are valid for 60 s). {@see forged()} signs with a key the endpoint does not trust.
 */
final class SoakIdentity
{
    public readonly string $identityKey;

    /** @var non-empty-string */
    private readonly string $secretKey;

    /** @var non-empty-string */
    private readonly string $otherSecretKey;

    private readonly string $otherIdentityKey;

    /** @var array<string, array{int, list<array{0: string, 1: string}>}> */
    private array $tokens = [];

    public function __construct()
    {
        [$this->identityKey, $this->secretKey] = RequestIdentityTestSupport::keyPair();
        [$this->otherIdentityKey, $this->otherSecretKey] = RequestIdentityTestSupport::keyPair();
    }

    /** @return list<array{0: string, 1: string}> */
    public function headers(WorkloadRequest $request): array
    {
        $now = time();
        $cached = $this->tokens[$request->path] ?? null;
        if ($cached === null || $now - $cached[0] >= 30) {
            $cached = $this->tokens[$request->path] = [$now, self::sign($this->secretKey, $this->identityKey, $request->path, $now)];
        }

        return $cached[1];
    }

    /** @return list<array{0: string, 1: string}> */
    public function forged(WorkloadRequest $request): array
    {
        return self::sign($this->otherSecretKey, $this->otherIdentityKey, $request->path, time());
    }

    /**
     * @param non-empty-string $secretKey
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function sign(string $secretKey, string $identityKey, string $path, int $now): array
    {
        $jwt = RequestIdentityTestSupport::jwt($secretKey, RequestIdentityTestSupport::restateHeader($identityKey), RequestIdentityTestSupport::restateClaims($path, $now));

        return RequestIdentityTestSupport::headerList(RequestIdentityTestSupport::headers($jwt));
    }
}
