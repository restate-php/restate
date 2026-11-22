<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark\Support;

use Restate\Internal\Backend;
use Restate\Internal\Backend\Invocation;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\CoreLogForwarder;
use Restate\Tests\Support\FakeIdentityVerifier;
use Restate\Tests\Support\FakeProtocolVersions;
use Restate\Tests\Support\ScriptedInvocation;

/**
 * A `Backend` that hands every request a fresh {@see ScriptedInvocation} with the same input: the SDK's own cost of an
 * attempt, without the VM. Unlike `FakeBackend`, which hands out one invocation, it can serve any number of requests
 * without the scripted journal growing from one to the next.
 */
final class ScriptedBackend implements Backend
{
    /** The invocation of the last request, to check what the handler did. */
    public ?ScriptedInvocation $last = null;

    public function __construct(private readonly string $input)
    {
    }

    #[\Override]
    public function protocolVersions(): array
    {
        return FakeProtocolVersions::RANGE;
    }

    #[\Override]
    public function newInvocation(array $headers, ?CoreLogForwarder $coreLogs = null): Invocation
    {
        $invocation = ScriptedInvocation::new();
        $invocation->input = new InvocationInput('inv_1benchmark000000000000000000000', 0, '', [], $this->input);

        return $this->last = $invocation;
    }

    #[\Override]
    public function identityVerifier(array $identityKeys): FakeIdentityVerifier
    {
        return new FakeIdentityVerifier($identityKeys);
    }

    /** The bytes the handler's last attempt wrote as its successful output; throws when it wrote none. */
    public function lastOutput(): string
    {
        $calls = $this->last?->calls('writeOutputSuccess') ?? [];
        $output = $calls[0][0] ?? null;

        return is_string($output) ? $output : throw new \RuntimeException('The scripted attempt wrote no successful output.');
    }
}
