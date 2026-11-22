<?php

declare(strict_types=1);

namespace Restate\Ingress\Internal;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Restate\Ingress\IngressException;
use Restate\Ingress\Output;
use Restate\JsonSerde;
use Restate\Serde;
use Restate\TerminalError;

/**
 * Sends requests to the Restate ingress through the user's PSR-18 client, encodes and decodes payloads, and turns
 * error responses into exceptions: the invocation's own failure into its {@see TerminalError}, anything else into an
 * {@see IngressException}.
 *
 * @internal
 */
final readonly class Transport
{
    /** Restate's status for "the invocation exists but has not completed yet" on an `/output` request. */
    private const int NOT_READY = 470;

    /**
     * @param string $baseUrl the ingress URL without a trailing slash
     * @param array<string, string> $headers sent with every request
     */
    public function __construct(
        private string $baseUrl,
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private array $headers,
        public Serde $serde,
    ) {
    }

    /**
     * POSTs a payload; null is no body when $nullIsEmpty and the serde is JSON.
     *
     * @param array<string, string> $headers
     */
    public function post(string $path, mixed $payload, Serde $serde, bool $nullIsEmpty, array $headers = []): HttpResult
    {
        [$body, $contentType] = self::encode($payload, $serde, $nullIsEmpty);
        if ($contentType !== null) {
            $headers['content-type'] = $contentType;
        }

        return $this->send('POST', $path, $body, $headers, withoutContentType: $contentType === null);
    }

    /**
     * The id of the invocation a workflow or idempotency target names, from Restate's `POST /restate/lookup` (Restate
     * computes it; the invocation need not exist). Used for scoped targets, which have no attach or output route of
     * their own.
     *
     * @param array<string, string> $target the lookup target, for example
     *                                     `['target' => 'workflow', 'workflowName' => …, 'workflowKey' => …, 'scope' => …]`
     */
    public function lookup(array $target): string
    {
        $result = $this->post('/restate/lookup', $target, JsonSerde::default(), false);
        try {
            $decoded = json_decode($result->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw $result->exception("returned a lookup response that is not JSON: {$e->getMessage()}", previous: $e);
        }
        $invocationId = is_array($decoded) ? ($decoded['invocationId'] ?? null) : null;
        if (!is_string($invocationId) || $invocationId === '') {
            throw $result->exception('returned a lookup response without an invocation id');
        }

        return $invocationId;
    }

    /** POSTs plain text. */
    public function postText(string $path, string $text): HttpResult
    {
        return $this->send('POST', $path, $text, ['content-type' => 'text/plain']);
    }

    /** GETs the result of an invocation (`…/attach`) and decodes it. */
    public function attach(string $path, Serde $serde): mixed
    {
        return $this->decode($this->send('GET', $path . '/attach'), $serde);
    }

    /** GETs the output of an invocation (`…/output`): not ready while it runs. */
    public function output(string $path, Serde $serde): Output
    {
        $result = $this->send('GET', $path . '/output', notReadyIsResult: true);

        return $result->status === self::NOT_READY ? Output::notReady() : Output::ready($this->decode($result, $serde));
    }

    public function decode(HttpResult $result, Serde $serde): mixed
    {
        if ($result->body === '' && $serde instanceof JsonSerde) {
            return null;
        }

        try {
            return $serde->deserialize($result->body);
        } catch (\Exception $e) {
            throw $result->exception("could not be decoded: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @param array<string, string> $headers
     * @param bool $withoutContentType drops any content type the headers set: Restate rejects one on the empty body
     *                                 of a handler without input (a PSR-18 client that adds one to every bodiless
     *                                 POST, as Symfony HttpClient does, cannot call such handlers)
     */
    private function send(string $method, string $path, string $body = '', array $headers = [], bool $notReadyIsResult = false, bool $withoutContentType = false): HttpResult
    {
        $uri = $this->baseUrl . $path;
        $request = $this->requestFactory->createRequest($method, $uri);
        foreach ([...$this->headers, ...$headers] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($withoutContentType) {
            $request = $request->withoutHeader('content-type');
        }
        if ($body !== '') {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new IngressException(
                sprintf('Restate ingress request %s %s failed: %s', $method, $uri, $e->getMessage()),
                $method,
                $uri,
                previous: $e,
            );
        }

        $result = new HttpResult($method, $uri, $response->getStatusCode(), (string) $response->getBody(), $response->getHeaderLine('x-restate-error-source'));
        if (($result->status >= 200 && $result->status < 300) || ($notReadyIsResult && $result->status === self::NOT_READY)) {
            return $result;
        }

        throw $this->failure($result);
    }

    /**
     * The invocation's terminal failure when Restate attributes the error to the invocation (the
     * `x-restate-error-source` header, or the `source` field of the error body if a proxy dropped the header);
     * otherwise an error of the ingress, of something in between, or of the request.
     */
    private function failure(HttpResult $result): TerminalError|IngressException
    {
        $error = self::errorBody($result->body);
        $message = is_string($error['message'] ?? null) ? $error['message'] : null;
        $source = $result->errorSource !== '' ? $result->errorSource : (is_string($error['source'] ?? null) ? $error['source'] : null);
        $code = is_int($error['code'] ?? null) ? $error['code'] : $result->status;

        if ($source === 'invocation' && $message !== null && $code >= 400 && $code <= 599) {
            $metadata = is_array($error['metadata'] ?? null) ? array_filter($error['metadata'], is_string(...)) : [];

            return new TerminalError($message, $code, $metadata);
        }

        return $result->exception(
            sprintf('failed with HTTP %d%s', $result->status, $message === null ? '' : ": {$message}"),
            $message,
            $source,
        );
    }

    /** @return array<array-key, mixed> */
    private static function errorBody(string $body): array
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{string, ?string} the body and its content type (none for no payload) */
    private static function encode(mixed $payload, Serde $serde, bool $nullIsEmpty): array
    {
        if ($payload === null && $nullIsEmpty && $serde instanceof JsonSerde) {
            return ['', null];
        }

        $contentType = $serde->contentType();

        return [$serde->serialize($payload), $contentType === '' ? null : $contentType];
    }
}
