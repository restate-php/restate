<?php

declare(strict_types=1);

namespace App;

use Restate\Attributes as Restate;
use Restate\TerminalError;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

/**
 * A Workflow: `run` executes exactly once per workflow key; shared handlers interact with it while it runs.
 */
#[Restate\Workflow('Signup')]
final class Signup
{
    #[Restate\Handler]
    public function run(WorkflowContext $ctx, string $email): string
    {
        $secret = $ctx->uuid();

        // A real application would email a verification link here.
        $ctx->run('send verification email', static fn () => null);
        $ctx->logger()->info("Verification secret for {$email}: {$secret}");

        // Waits durably, for minutes or days, until click() resolves the promise. No PHP process waits meanwhile.
        if ($ctx->promise('email-link')->value() !== $secret) {
            throw new TerminalError('Invalid verification link', 400);
        }

        return "Welcome, {$email}!";
    }

    #[Restate\Handler]
    #[Restate\Shared]
    public function click(WorkflowSharedContext $ctx, string $secret): void
    {
        $ctx->promise('email-link')->resolve($secret);
    }
}
