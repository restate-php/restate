<?php

declare(strict_types=1);

namespace Restate\Ingress;

/**
 * The output of an invocation read without waiting for it: ready with the value it returned, or not ready while it
 * still runs. A failed invocation has no output; reading it throws the invocation's {@see \Restate\TerminalError}.
 *
 * @template-covariant T = mixed
 */
final readonly class Output
{
    /** @param T $value */
    private function __construct(
        private bool $ready,
        private mixed $value,
    ) {
    }

    /**
     * @template V
     *
     * @param V $value
     *
     * @return self<V>
     */
    public static function ready(mixed $value): self
    {
        return new self(true, $value);
    }

    /** @return self<never> not ready, so it has no value of any type */
    public static function notReady(): self
    {
        /** @var self<never> */
        return new self(false, null);
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * The value the invocation returned.
     *
     * @return T
     *
     * @throws \LogicException when the output is not ready
     */
    public function value(): mixed
    {
        if (!$this->ready) {
            throw new \LogicException('The invocation has not completed yet; check isReady() before reading its value.');
        }

        return $this->value;
    }
}
