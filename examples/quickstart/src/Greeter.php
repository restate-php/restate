<?php

declare(strict_types=1);

namespace App;

use Restate\Attributes as Restate;
use Restate\Context;

/**
 * A Basic Service: stateless handlers that Restate runs to completion, across failures and restarts.
 */
#[Restate\Service('Greeter')]
final class Greeter
{
    public function __construct(private readonly Notifications $notifications)
    {
    }

    #[Restate\Handler]
    public function greet(Context $ctx, Greeting $greeting): GreetingResponse
    {
        // Deterministic: Restate records the value, so every retry of this invocation sees the same id.
        $greetingId = $ctx->uuid();

        // A durable step. Once it succeeds, Restate records its result and a retry skips it.
        $ctx->run('notification', fn () => $this->notifications->send($greetingId, $greeting->name));
        // Logged once per invocation, not once per attempt: $ctx->logger() is quiet while the handler replays.
        $ctx->logger()->info("Notification sent: {$greetingId}");

        // A durable timer. The PHP request ends here and Restate calls the handler again in 5 seconds,
        // even if this service or Restate restarted in between.
        $ctx->sleep(5_000);

        $ctx->run('reminder', fn () => $this->notifications->remind($greetingId, $greeting->name));
        $ctx->logger()->info("Reminder sent: {$greetingId}");

        return new GreetingResponse("You said hi to {$greeting->name}!");
    }
}
