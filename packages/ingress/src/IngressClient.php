<?php

declare(strict_types=1);

namespace Restate\Ingress;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Restate\Internal\FlowControlKeys;
use Restate\Ingress\Internal\HttpResult;
use Restate\Ingress\Internal\Transport;
use Restate\JsonSerde;
use Restate\Serde;
use Restate\Target;

/**
 * Invokes Restate services from code that is not a handler (a web controller, a CLI command, a test) through the
 * Restate ingress over HTTP: call and wait for the result, send (optionally delayed), attach to an invocation or read
 * its output, submit and wait for workflows, resolve and reject awakeables.
 *
 * The HTTP client is yours: any PSR-18 client with PSR-17 factories. The client needs neither the Restate extension
 * nor an endpoint.
 *
 * ```php
 * $restate = IngressClient::connect('http://localhost:8080', $psr18Client, $psr17Factory);
 * $greeting = $restate->serviceClient('Greeter')->request('greet', 'Mary')->call();
 * ```
 *
 * Payloads are JSON unless a {@see Serde} says otherwise. A null payload is sent as an empty body (no input), and an
 * empty result decodes to null.
 *
 * A failure of the invocation itself is thrown as its {@see \Restate\TerminalError} (status code, message and
 * metadata), as for a call from a handler; anything else that goes wrong is an {@see IngressException}.
 */
final readonly class IngressClient
{
    /** The header the ingress reads a request's limit key from (also the `limit-key` query parameter). */
    private const string LIMIT_KEY_HEADER = 'x-restate-limit-key';

    private function __construct(private Transport $transport)
    {
    }

    /**
     * @param string $url the ingress URL, for example http://localhost:8080; a path prefix is kept
     * @param ?StreamFactoryInterface $streamFactory may be omitted when $requestFactory also implements it
     * @param array<string, string> $headers sent with every request, for example an Authorization header
     * @param ?Serde $serde the default serde for payloads, results and awakeable values; JSON when omitted
     */
    public static function connect(
        string $url,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        ?StreamFactoryInterface $streamFactory = null,
        array $headers = [],
        ?Serde $serde = null,
    ): self {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array(is_string($scheme) ? strtolower($scheme) : null, ['http', 'https'], true) || !is_string(parse_url($url, PHP_URL_HOST))) {
            throw new \InvalidArgumentException("The Restate ingress URL must be an absolute http or https URL, got \"{$url}\".");
        }

        if ($streamFactory === null) {
            if (!$requestFactory instanceof StreamFactoryInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Pass a %s: the request factory (%s) does not implement it.',
                    StreamFactoryInterface::class,
                    $requestFactory::class,
                ));
            }
            $streamFactory = $requestFactory;
        }

        return new self(new Transport(
            rtrim($url, '/'),
            $httpClient,
            $requestFactory,
            $streamFactory,
            self::headerMap($headers),
            $serde ?? JsonSerde::default(),
        ));
    }

    public function serviceClient(string $service): ServiceClient
    {
        return new ServiceClient($this, $service);
    }

    public function objectClient(string $object, string $key): ObjectClient
    {
        return new ObjectClient($this, $object, $key);
    }

    public function workflowClient(string $workflow, string $key): WorkflowClient
    {
        return new WorkflowClient($this, $workflow, $key);
    }

    /**
     * Clients for requests to targets within $scope ({@see Target::scoped()}): a scope is part of the target's identity
     * and what Restate's concurrency rules apply to; a request within a scope may add a limit key
     * ({@see ClientRequest::limitKey()}). Needs Restate 1.8 or newer.
     *
     * ```php
     * $restate->scope('tenant-1')->serviceClient('Billing')->request('charge', $order)->limitKey('user-42')->call();
     * ```
     */
    public function scope(string $scope): Scope
    {
        return new Scope($this, $scope);
    }

    /**
     * Calls a handler and waits for its result.
     *
     * @param array<string, string> $headers
     * @param ?string $limitKey the concurrency limit key within the target's scope ({@see ClientRequest::limitKey()})
     *
     * @throws \Restate\TerminalError the invocation's failure
     * @throws IngressException
     */
    public function call(
        Target $target,
        mixed $payload = null,
        ?string $idempotencyKey = null,
        ?Serde $inputSerde = null,
        ?Serde $outputSerde = null,
        array $headers = [],
        ?string $limitKey = null,
    ): mixed {
        $result = $this->transport->post(
            self::invocationPath($target, false),
            $payload,
            $inputSerde ?? $this->transport->serde,
            true,
            self::requestHeaders($headers, $idempotencyKey, $target, $limitKey),
        );

        return $this->transport->decode($result, $outputSerde ?? $this->transport->serde);
    }

    /**
     * Sends a request without waiting for the handler.
     *
     * @param ?Serde $outputSerde decodes the result when the returned response attaches to the invocation
     * @param array<string, string> $headers
     * @param ?string $limitKey the concurrency limit key within the target's scope ({@see ClientRequest::limitKey()})
     *
     * @throws IngressException
     */
    public function send(
        Target $target,
        mixed $payload = null,
        ?string $idempotencyKey = null,
        ?Serde $inputSerde = null,
        ?Serde $outputSerde = null,
        array $headers = [],
        ?string $limitKey = null,
    ): SendResponse {
        return $this->sendAfter($target, $payload, 0, $idempotencyKey, $inputSerde, $outputSerde, $headers, $limitKey);
    }

    /**
     * Sends a request that Restate starts after the delay.
     *
     * @param int $delayMillis 0 sends at once
     * @param ?Serde $outputSerde decodes the result when the returned response attaches to the invocation
     * @param array<string, string> $headers
     * @param ?string $limitKey the concurrency limit key within the target's scope ({@see ClientRequest::limitKey()})
     *
     * @throws IngressException
     */
    public function sendAfter(
        Target $target,
        mixed $payload,
        int $delayMillis,
        ?string $idempotencyKey = null,
        ?Serde $inputSerde = null,
        ?Serde $outputSerde = null,
        array $headers = [],
        ?string $limitKey = null,
    ): SendResponse {
        if ($delayMillis < 0) {
            throw new \InvalidArgumentException('The send delay must not be negative.');
        }

        $result = $this->transport->post(
            self::invocationPath($target, true) . ($delayMillis > 0 ? "?delay={$delayMillis}ms" : ''),
            $payload,
            $inputSerde ?? $this->transport->serde,
            true,
            self::requestHeaders($headers, $idempotencyKey, $target, $limitKey),
        );
        [$invocationId, $status] = self::sendResult($result);

        return new SendResponse($invocationId, $status, new Invocation($this->transport, '/restate/invocation/' . rawurlencode($invocationId), $outputSerde));
    }

    /** An invocation by its id, for example one a send returned or a handler reported. */
    public function invocation(string $invocationId, ?Serde $serde = null): Invocation
    {
        if ($invocationId === '') {
            throw new \InvalidArgumentException('Invocation ID must not be empty.');
        }

        return new Invocation($this->transport, '/restate/invocation/' . rawurlencode($invocationId), $serde);
    }

    /**
     * The invocation of $target that was called or sent with $idempotencyKey; in the target's scope, if it has one
     * (idempotency keys are per scope). A scoped invocation is found by its id, which Restate computes (one more
     * request, Restate 1.8 or newer).
     */
    public function idempotentInvocation(Target $target, string $idempotencyKey, ?Serde $serde = null): Invocation
    {
        self::assertIdempotencyKey($idempotencyKey);
        $scope = $target->scope();
        if ($scope === null) {
            return new Invocation($this->transport, '/restate/invocation' . self::targetPath($target) . '/' . rawurlencode($idempotencyKey), $serde);
        }

        $lookup = ['target' => 'idempotentInvocation', 'service' => $target->serviceName()];
        if ($target->key() !== null) {
            $lookup['key'] = $target->key();
        }
        $lookup += ['handler' => $target->handlerName(), 'idempotencyKey' => $idempotencyKey, 'scope' => $scope];

        return $this->lookedUp($lookup, $serde);
    }

    /**
     * The run of the workflow with this key; within $scope if it was submitted in one (a workflow key names a different
     * run in each scope). A scoped run is found by its id, which Restate computes (one more request, Restate 1.8 or
     * newer).
     */
    public function workflowInvocation(string $workflow, string $key, ?Serde $serde = null, ?string $scope = null): Invocation
    {
        Target::workflow($workflow, $key, 'run');
        if ($scope === null) {
            return new Invocation($this->transport, '/restate/workflow/' . $workflow . '/' . rawurlencode($key), $serde);
        }
        FlowControlKeys::assertScope($scope);

        return $this->lookedUp(['target' => 'workflow', 'workflowName' => $workflow, 'workflowKey' => $key, 'scope' => $scope], $serde);
    }

    /**
     * Completes the awakeable with a value. Unlike a call, null is a value here: it is sent as JSON null, as
     * {@see \Restate\Context::resolveAwakeable()} does.
     *
     * @param ?Serde $serde encodes the value; must match what the awaiting handler decodes it with
     *
     * @throws IngressException
     */
    public function resolveAwakeable(string $id, mixed $value = null, ?Serde $serde = null): void
    {
        $this->transport->post(self::awakeablePath($id) . '/resolve', $value, $serde ?? $this->transport->serde, false);
    }

    /**
     * Fails the awakeable: the awaiting handler gets a {@see \Restate\TerminalError} with the reason as its message.
     *
     * @throws IngressException
     */
    public function rejectAwakeable(string $id, string $reason): void
    {
        $this->transport->postText(self::awakeablePath($id) . '/reject', $reason);
    }

    /** @param array<string, string> $lookup */
    private function lookedUp(array $lookup, ?Serde $serde): Invocation
    {
        $transport = $this->transport;

        return new Invocation($transport, static fn (): string => '/restate/invocation/' . rawurlencode($transport->lookup($lookup)), $serde);
    }

    /**
     * Where a call or send to $target goes: `/{service}[/{key}]/{handler}[/send]`, or within a scope
     * `/restate/scope/{scope}/{call|send}/{service}[/{key}]/{handler}`.
     */
    private static function invocationPath(Target $target, bool $send): string
    {
        $scope = $target->scope();
        if ($scope === null) {
            return self::targetPath($target) . ($send ? '/send' : '');
        }

        return '/restate/scope/' . rawurlencode($scope) . ($send ? '/send' : '/call') . self::targetPath($target);
    }

    /** `/{service}[/{key}]/{handler}`; the names are validated by {@see Target}, the key is one encoded segment. */
    private static function targetPath(Target $target): string
    {
        $key = $target->key();

        return '/' . $target->serviceName() . ($key === null ? '' : '/' . rawurlencode($key)) . '/' . $target->handlerName();
    }

    private static function awakeablePath(string $id): string
    {
        if ($id === '') {
            throw new \InvalidArgumentException('Awakeable ID must not be empty.');
        }

        return '/restate/awakeables/' . rawurlencode($id);
    }

    /**
     * @param array<array-key, mixed> $headers
     * @return array<string, string>
     */
    private static function headerMap(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value)) {
                throw new \InvalidArgumentException('Headers must be a map of header names to string values.');
            }
        }

        /** @var array<string, string> $headers */
        return $headers;
    }

    /**
     * @param array<array-key, mixed> $headers
     * @return array<string, string>
     */
    private static function requestHeaders(array $headers, ?string $idempotencyKey, Target $target, ?string $limitKey): array
    {
        $headers = self::headerMap($headers);
        if ($idempotencyKey !== null) {
            self::assertIdempotencyKey($idempotencyKey);
            $headers['idempotency-key'] = $idempotencyKey;
        }
        FlowControlKeys::assertLimitKeyFor($target->scope(), $limitKey);
        if ($limitKey !== null) {
            $headers[self::LIMIT_KEY_HEADER] = $limitKey;
        }

        return $headers;
    }

    private static function assertIdempotencyKey(string $idempotencyKey): void
    {
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('Idempotency key must not be empty.');
        }
    }

    /** @return array{string, SendStatus} */
    private static function sendResult(HttpResult $result): array
    {
        try {
            $decoded = json_decode($result->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw $result->exception("returned a send response that is not JSON: {$e->getMessage()}", previous: $e);
        }

        $invocationId = is_array($decoded) && is_string($decoded['invocationId'] ?? null) && $decoded['invocationId'] !== '' ? $decoded['invocationId'] : null;
        $status = is_array($decoded) && is_string($decoded['status'] ?? null) ? SendStatus::tryFrom($decoded['status']) : null;
        if ($invocationId === null || $status === null) {
            throw $result->exception('returned a send response without an invocation id and a known status');
        }

        return [$invocationId, $status];
    }
}
