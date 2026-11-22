<?php

declare(strict_types=1);

namespace Restate\Tests\Support;

use Restate\Internal\Backend;
use Restate\Internal\Backend\Invocation;
use Restate\Internal\CoreLogForwarder;

/**
 * The unit tests' `Backend`: hands out the invocation a test built (usually a PHPUnit mock of `Backend\Invocation`),
 * throws the given exception, or fails the test when it has neither. It reports the protocol range the extension
 * reports ({@see FakeProtocolVersions}) unless given another, checked against {@see BackendShapes}. Its identity
 * verifier is a {@see FakeIdentityVerifier}.
 */
final class FakeBackend implements Backend
{
    /** @var list<array{0: string, 1: string}>|null the headers of the last newInvocation() call */
    public ?array $headers = null;

    /** The core log forwarder of the last newInvocation() call. */
    public ?CoreLogForwarder $coreLogs = null;

    /** The verifier of the last identityVerifier() call, which the endpoint makes once, when it has identity keys. */
    public ?FakeIdentityVerifier $identityVerifier = null;

    /** @param array{min: int, max: int} $protocolVersions */
    public function __construct(
        private readonly Invocation|\Throwable|null $invocation = null,
        private readonly array $protocolVersions = FakeProtocolVersions::RANGE,
    ) {
    }

    public function protocolVersions(): array
    {
        return BackendShapes::check('protocolVersions', $this->protocolVersions);
    }

    public function identityVerifier(array $identityKeys): FakeIdentityVerifier
    {
        return $this->identityVerifier = new FakeIdentityVerifier($identityKeys);
    }

    public function newInvocation(array $headers, ?CoreLogForwarder $coreLogs = null): Invocation
    {
        $this->headers = $headers;
        $this->coreLogs = $coreLogs;

        return match (true) {
            $this->invocation instanceof Invocation => $this->invocation,
            $this->invocation instanceof \Throwable => throw $this->invocation,
            default => throw new \LogicException('This test does not create an invocation.'),
        };
    }
}
