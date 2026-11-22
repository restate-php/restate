<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark\Support;

use Psr\Log\AbstractLogger;

/**
 * A logger that drops every record. Unlike `NullLogger`, which the endpoint recognizes and treats as no logger at all,
 * it switches the SDK's logging on (and shared-core's log capture, at the endpoint's default INFO level), so a
 * benchmark measures what logging costs the SDK, not what a log handler costs.
 */
final class DiscardingLogger extends AbstractLogger
{
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
    }
}
