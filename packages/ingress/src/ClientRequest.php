<?php

declare(strict_types=1);

namespace Restate\Ingress;

use Restate\Internal\FlowControlKeys;
use Restate\Serde;
use Restate\Target;

/**
 * One request to a handler through the ingress, configured fluently and then called or sent. Each configuration
 * method returns a new request.
 *
 * ```php
 * $restate->objectClient('Counter', 'alice')->request('add', 1)->idempotencyKey('add-1')->send();
 * ```
 *
 * TOutput is the handler's result as the output serde decodes it: `mixed` for a request made with a handler name, the
 * handler's return type for a request a typed client declares. Another output serde makes it `mixed` again.
 *
 * @template-covariant TOutput = mixed
 */
final readonly class ClientRequest
{
    /**
     * @param array<string, string> $headers
     *
     * @internal built by the clients {@see IngressClient} hands out
     */
    public function __construct(
        private IngressClient $client,
        private Target $target,
        private mixed $payload = null,
        private ?string $idempotencyKey = null,
        private ?Serde $inputSerde = null,
        private ?Serde $outputSerde = null,
        private array $headers = [],
        private ?string $limitKey = null,
    ) {
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('Idempotency key must not be empty.');
        }
        FlowControlKeys::assertLimitKeyFor($target->scope(), $limitKey);
    }

    /**
     * Restate runs the request once per key: a call or send with the same key (within the retention) returns the
     * first one's result, and its invocation can be attached to with {@see IngressClient::idempotentInvocation()}.
     *
     * @return self<TOutput>
     */
    public function idempotencyKey(string $key): self
    {
        return new self($this->client, $this->target, $this->payload, $key, $this->inputSerde, $this->outputSerde, $this->headers, $this->limitKey);
    }

    /**
     * The concurrency limit key within the target's scope: one or two levels separated by `/` (for example `user-42` or
     * `eu/user-42`), each 1 to 36 characters from `[a-zA-Z0-9_.-]`. Restate's concurrency rules match it below the
     * scope; it is not part of the target's identity. Needs a scoped target ({@see IngressClient::scope()}) and
     * Restate 1.8 or newer.
     *
     * @return self<TOutput>
     */
    public function limitKey(string $limitKey): self
    {
        return new self($this->client, $this->target, $this->payload, $this->idempotencyKey, $this->inputSerde, $this->outputSerde, $this->headers, $limitKey);
    }

    /**
     * The serde for both the payload and the result.
     *
     * @return self<mixed>
     */
    public function serde(Serde $serde): self
    {
        return new self($this->client, $this->target, $this->payload, $this->idempotencyKey, $serde, $serde, $this->headers, $this->limitKey);
    }

    /** @return self<TOutput> */
    public function inputSerde(Serde $serde): self
    {
        return new self($this->client, $this->target, $this->payload, $this->idempotencyKey, $serde, $this->outputSerde, $this->headers, $this->limitKey);
    }

    /** @return self<mixed> */
    public function outputSerde(Serde $serde): self
    {
        return new self($this->client, $this->target, $this->payload, $this->idempotencyKey, $this->inputSerde, $serde, $this->headers, $this->limitKey);
    }

    /**
     * Adds a header, replacing a header of the same name (also one the client sends with every request).
     *
     * @return self<TOutput>
     */
    public function header(string $name, string $value): self
    {
        return new self($this->client, $this->target, $this->payload, $this->idempotencyKey, $this->inputSerde, $this->outputSerde, [...$this->headers, $name => $value], $this->limitKey);
    }

    /**
     * Replaces the headers set on this request; the client's headers are still sent.
     *
     * @param array<string, string> $headers
     *
     * @return self<TOutput>
     */
    public function headers(array $headers): self
    {
        return new self($this->client, $this->target, $this->payload, $this->idempotencyKey, $this->inputSerde, $this->outputSerde, $headers, $this->limitKey);
    }

    /**
     * Calls the handler and waits for its result.
     *
     * @return TOutput
     *
     * @throws \Restate\TerminalError the invocation's failure
     * @throws IngressException
     */
    public function call(): mixed
    {
        return $this->client->call($this->target, $this->payload, $this->idempotencyKey, $this->inputSerde, $this->outputSerde, $this->headers, limitKey: $this->limitKey);
    }

    /**
     * Sends the request without waiting for the handler.
     *
     * @return SendResponse<TOutput> attaches to the invocation with this request's output serde
     *
     * @throws IngressException
     */
    public function send(): SendResponse
    {
        /** @var SendResponse<TOutput> */
        return $this->client->send($this->target, $this->payload, $this->idempotencyKey, $this->inputSerde, $this->outputSerde, $this->headers, limitKey: $this->limitKey);
    }

    /**
     * Sends the request; Restate starts it after the delay.
     *
     * @return SendResponse<TOutput> attaches to the invocation with this request's output serde
     *
     * @throws IngressException
     */
    public function sendAfter(int $delayMillis): SendResponse
    {
        /** @var SendResponse<TOutput> */
        return $this->client->sendAfter($this->target, $this->payload, $delayMillis, $this->idempotencyKey, $this->inputSerde, $this->outputSerde, $this->headers, limitKey: $this->limitKey);
    }
}
