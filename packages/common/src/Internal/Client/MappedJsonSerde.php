<?php

declare(strict_types=1);

namespace Restate\Internal\Client;

use Restate\JsonSerde;
use Restate\JsonValueMapper;
use Restate\JsonValueMappingContext;
use Restate\PayloadRole;
use Restate\Reflection\BuiltinJsonValueMapper;
use Restate\Serde;

/**
 * JSON for the caller of a handler, with the JSON Value Mapping the endpoint applies to the handler's payload: the
 * caller normalizes the handler's input before encoding it (the endpoint hydrates it) and hydrates the handler's output
 * after decoding it (the endpoint normalized it). Mirrors {@see \Restate\Internal\EffectiveHandlerPayload}: the
 * endpoint's value mapper when it supports the type, else the built-in one, else the plain JSON value.
 *
 * An empty body (a handler without output) decodes to null.
 *
 * @internal
 */
final readonly class MappedJsonSerde implements Serde
{
    /** @param PayloadRole $role whether this is the handler's input or its output */
    public function __construct(
        private string $serviceName,
        private string $handlerName,
        private ?\ReflectionType $type,
        private PayloadRole $role,
        private ?JsonValueMapper $valueMapper = null,
    ) {
    }

    #[\Override]
    public function serialize(mixed $value): string
    {
        $context = $this->context();
        if ($this->valueMapper !== null && $this->valueMapper->supports($context)) {
            $value = $this->valueMapper->normalize($value, $context);
        } else {
            $builtIn = new BuiltinJsonValueMapper();
            if ($builtIn->supports($context)) {
                $value = $builtIn->normalize($value, $context);
            } elseif (BuiltinJsonValueMapper::requiresMapping($context->type)) {
                throw new \LogicException("DTO mapping is not available for {$this->serviceName}/{$this->handlerName} {$this->role->value}.");
            }
        }

        return JsonSerde::default()->serialize($value);
    }

    #[\Override]
    public function deserialize(string $bytes): mixed
    {
        if ($bytes === '') {
            return null;
        }

        $context = $this->context();
        if ($this->valueMapper !== null && $this->valueMapper->supports($context)) {
            return $this->valueMapper->hydrate(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR), $context);
        }

        $builtIn = new BuiltinJsonValueMapper();
        if ($builtIn->supports($context)) {
            // As the endpoint does: objects stay objects, so an empty JSON object is not taken for a list.
            return $builtIn->hydrate(json_decode($bytes, false, flags: JSON_THROW_ON_ERROR), $context);
        }

        if (BuiltinJsonValueMapper::requiresMapping($context->type)) {
            throw new \LogicException("DTO mapping is not available for {$this->serviceName}/{$this->handlerName} {$this->role->value}.");
        }

        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    }

    #[\Override]
    public function contentType(): string
    {
        return 'application/json';
    }

    private function context(): JsonValueMappingContext
    {
        return new JsonValueMappingContext($this->type, $this->role, $this->serviceName, $this->handlerName);
    }
}
