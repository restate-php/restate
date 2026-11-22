<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark;

use PhpBench\Attributes as Bench;
use Restate\Internal\Backend\Extension\Backend;
use Restate\Internal\Backend\IdentityVerifier;
use Restate\Tests\Benchmark\Support\Expect;
use Restate\Tests\Support\RequestIdentityTestSupport;

/**
 * Request identity: building the verifier (once per endpoint, so once per request under php-fpm and `php -S`) and
 * verifying Restate's signed token, through the extension.
 */
#[Bench\Iterations(10)]
#[Bench\Warmup(1)]
#[Bench\Revs(1000)]
#[Bench\OutputTimeUnit('microseconds', precision: 2)]
final class IdentityBench
{
    private const string PATH = '/invoke/Greeter/greet';

    private Backend $backend;

    private IdentityVerifier $verifier;

    private string $identityKey;

    /** @var list<array{0: string, 1: string}> */
    private array $headers = [];

    #[Bench\BeforeMethods('setUp')]
    public function benchBuildVerifier(): void
    {
        $this->backend->identityVerifier([$this->identityKey]);
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchVerifyValid(): void
    {
        $this->verifier->verify($this->headers, self::PATH);
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchVerifyWrongAudience(): void
    {
        $this->verifier->verify($this->headers, '/invoke/Greeter/other');
    }

    public function setUp(): void
    {
        [$this->identityKey, $secretKey] = RequestIdentityTestSupport::keyPair('restate-php-benchmark-identity-1');
        // Valid for an hour, so a long run never sees it expire.
        $claims = ['aud' => self::PATH, 'exp' => time() + 3600, 'iat' => time(), 'nbf' => time() - 60];
        $this->headers = RequestIdentityTestSupport::headerList(RequestIdentityTestSupport::headers(
            RequestIdentityTestSupport::jwt($secretKey, RequestIdentityTestSupport::restateHeader($this->identityKey), $claims),
        ));
        $this->backend = new Backend();
        $this->verifier = $this->backend->identityVerifier([$this->identityKey]);

        Expect::same(null, $this->verifier->verify($this->headers, self::PATH), 'valid identity');
        if ($this->verifier->verify($this->headers, '/invoke/Greeter/other') === null) {
            throw new \RuntimeException('A token for another path was accepted.');
        }
    }
}
