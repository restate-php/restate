<?php

declare(strict_types=1);

namespace App;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * A minimal PSR-3 logger that writes one line per record to stderr, which `docker compose logs` shows.
 * Use Monolog or your framework's logger in a real application.
 */
final class StderrLogger extends AbstractLogger
{
    private const array LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private readonly int $minimum;

    public function __construct(string $level = LogLevel::INFO)
    {
        $this->minimum = self::LEVELS[strtolower($level)] ?? self::LEVELS[LogLevel::INFO];
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 0) < $this->minimum) {
            return;
        }

        $line = sprintf('[%s]', $level);
        if (isset($context['restate.invocation.id'])) {
            $line .= sprintf(' [%s]', $context['restate.invocation.id']);
        }
        $line .= ' ' . $message;
        if (($context['exception'] ?? null) instanceof \Throwable) {
            $line .= ': ' . $context['exception']->getMessage();
        }

        file_put_contents('php://stderr', $line . PHP_EOL);
    }
}
