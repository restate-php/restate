<?php

declare(strict_types=1);

namespace Restate;

/**
 * JSON Value Mapping between decoded JSON and the PHP types of a handler's payloads. The endpoint hydrates a handler's
 * input and normalizes its output; a caller ({@see Reflection\HandlerSerdes}) normalizes the input it sends and
 * hydrates the output it receives. The context's role says whose payload it is, not the direction.
 */
interface JsonValueMapper
{
    public function supports(JsonValueMappingContext $context): bool;

    /** Decoded JSON to the context's PHP type. */
    public function hydrate(mixed $value, JsonValueMappingContext $context): mixed;

    /** A value of the context's PHP type to JSON-encodable data. */
    public function normalize(mixed $value, JsonValueMappingContext $context): mixed;
}
