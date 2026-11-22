<?php

declare(strict_types=1);

namespace Restate\Reflection;

use Restate\Internal\Client\BytesSerde;
use Restate\Internal\Client\MappedJsonSerde;
use Restate\JsonSerde;
use Restate\JsonValueMapper;
use Restate\JsonValueMappingContext;
use Restate\Serde;

/**
 * How a caller writes the input of, and reads the output of, one handler, so that both match what the endpoint serving
 * the handler expects and returns. A typed client of a service (a class with one method per handler that declares each
 * request's output type) sets these on each request, built from the handler's payload configuration as its service
 * class declares it; they can also be set on a request made with a handler name.
 *
 * Per payload:
 *
 * - JSON (the default): JSON with the endpoint's JSON Value Mapping for the handler's declared PHP type, so DTOs and
 *   backed enums are normalized on the way in and hydrated on the way out, and scalars are checked against the type.
 *   The types are read from a signature closure that mirrors the handler without its context. Give the endpoint's
 *   {@see JsonValueMapper} (a JSON Payload Adapter) when it has one; the built-in mapping is used otherwise.
 * - A Custom Serializer (`#[Input(serde: …)]` / `#[Output(serde: …)]`): that serializer, given as `$input` or `$output`.
 * - Binary: {@see self::bytes()}, given as `$input` or `$output`.
 * - No input: JSON, so a null payload is sent as no payload. No output: an empty body decodes to null.
 *
 * An endpoint-wide default serializer (`EndpointBuilder::defaultSerde()`) is configuration of the endpoint, not of the
 * handler, so it is not applied here; set the serde on the request instead.
 */
final readonly class HandlerSerdes
{
    public function __construct(
        /** Writes the handler's input. */
        public Serde $input,
        /** Reads the handler's output. */
        public Serde $output,
    ) {
    }

    /**
     * ```php
     * HandlerSerdes::of('Checkout', 'run', static fn (Order $order): Receipt => throw new \LogicException());
     * ```
     *
     * @param string $service the Restate service name
     * @param string $handler the Restate handler name
     * @param \Closure $signature the handler's payload types, never called: a closure with the handler's payload
     *                           parameter (none when the handler takes no input) and its return type (none when it has
     *                           no output)
     * @param ?JsonValueMapper $valueMapper the JSON value mapper of the endpoint serving the handler, when it has one
     * @param ?Serde $input the input's serde when the input is not JSON: a Custom Serializer, or {@see self::bytes()}
     * @param ?Serde $output the output's serde when the output is not JSON: a Custom Serializer, or {@see self::bytes()}
     *
     * @throws \InvalidArgumentException when the signature declares more than one parameter
     */
    public static function of(
        string $service,
        string $handler,
        \Closure $signature,
        ?JsonValueMapper $valueMapper = null,
        ?Serde $input = null,
        ?Serde $output = null,
    ): self {
        $reflection = new \ReflectionFunction($signature);
        $parameters = $reflection->getParameters();
        if (count($parameters) > 1) {
            throw new \InvalidArgumentException("The signature of {$service}/{$handler} must declare at most one parameter, the handler's payload.");
        }

        $input ??= $parameters === []
            ? JsonSerde::default()
            : new MappedJsonSerde($service, $handler, $parameters[0]->getType(), JsonValueMappingContext::ROLE_INPUT, $valueMapper);
        $output ??= new MappedJsonSerde($service, $handler, $reflection->getReturnType(), JsonValueMappingContext::ROLE_OUTPUT, $valueMapper);

        return new self($input, $output);
    }

    /** A binary payload: the bytes as a string, unchanged, sent with `$contentType`. */
    public static function bytes(string $contentType = 'application/octet-stream'): Serde
    {
        if ($contentType === '') {
            throw new \InvalidArgumentException('Payload content type must not be empty.');
        }

        return new BytesSerde($contentType);
    }
}
