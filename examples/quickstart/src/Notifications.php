<?php

declare(strict_types=1);

namespace App;

/**
 * Stands in for an email or push notification client. For "Alice" it fails most of the time, to show how
 * Restate retries a failed step without repeating the steps that already succeeded.
 */
final class Notifications
{
    public function send(string $greetingId, string $name): void
    {
        if ($name === 'Alice' && random_int(1, 10) <= 7) {
            throw new \RuntimeException("[SIMULATED] Failed to send notification {$greetingId} to {$name}");
        }
        // A real implementation sends the notification here.
    }

    public function remind(string $greetingId, string $name): void
    {
        if ($name === 'Alice' && random_int(1, 10) <= 7) {
            throw new \RuntimeException("[SIMULATED] Failed to send reminder {$greetingId} to {$name}");
        }
    }
}
