<?php

declare(strict_types=1);

namespace Restate\Tests\Load\Support;

/**
 * The load endpoint's side-effect ledger: one line `<invocation id> <step>` per execution of a `LoadRuns` run, in the
 * file LOAD_EFFECTS_LOG names (nothing when unset). A step that appears twice for one invocation ran twice: a
 * duplicated side effect, which at-least-once execution allows only when an attempt failed after the run executed.
 * Lines are short single `write()`s in append mode, so concurrent PHP threads never interleave them.
 */
final class EffectsLog
{
    private function __construct()
    {
    }

    public static function record(string $invocationId, int $step): void
    {
        $path = getenv('LOAD_EFFECTS_LOG');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, "{$invocationId} {$step}\n", FILE_APPEND);
        }
    }

    /**
     * Executions beyond the first of each (invocation, step), from a ledger's lines.
     *
     * @param iterable<string> $lines
     *
     * @return array{executions: int, duplicates: int}
     */
    public static function count(iterable $lines): array
    {
        $seen = [];
        $executions = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $executions++;
            $seen[$line] = ($seen[$line] ?? 0) + 1;
        }

        return ['executions' => $executions, 'duplicates' => $executions - count($seen)];
    }
}
