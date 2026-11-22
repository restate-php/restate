<?php

declare(strict_types=1);

namespace Restate;

use InvalidArgumentException;
use Restate\Internal\FlowControlKeys;

/**
 * The handler a call or send goes to: a service handler, or the handler of one virtual object or workflow (by key),
 * optionally within a scope ({@see Target::scoped()}).
 */
final readonly class Target
{
    /** @param 'service'|'object'|'workflow' $kind */
    private function __construct(
        private readonly string $kind,
        private readonly string $service,
        private readonly string $handler,
        private readonly ?string $key,
        private readonly ?string $scope = null,
    ) {
        self::assertNotEmpty($service, 'Target service name must not be empty.');
        self::assertNotEmpty($handler, 'Target handler name must not be empty.');
        self::assertValidServiceName($service);
        self::assertValidHandlerName($handler);
        if ($kind !== 'service') {
            self::assertNotEmpty($key ?? '', 'Target key must not be empty.');
        }
        if ($scope !== null) {
            FlowControlKeys::assertScope($scope);
        }
    }

    public static function service(string $service, string $handler): self
    {
        return new self('service', $service, $handler, null);
    }

    public static function object(string $object, string $key, string $handler): self
    {
        return new self('object', $object, $handler, $key);
    }

    public static function workflow(string $workflow, string $key, string $handler): self
    {
        return new self('workflow', $workflow, $handler, $key);
    }

    /**
     * The same handler within $scope. A scope is part of the target's identity: an object or workflow key, and an
     * idempotency key, name a different instance in each scope (and outside any scope), and Restate co-locates
     * everything in one scope. Restate's concurrency rules match the scope, and the limit key a call or send gives
     * within it (`limitKey:`), so a scope is also what concurrency limits apply to.
     *
     * A scope is 1 to 36 characters from `[a-zA-Z0-9_.-]`. Calls and sends to a scoped target need service protocol v7
     * (Restate 1.8 or newer); on an older version the attempt fails and Restate retries it.
     */
    public function scoped(string $scope): self
    {
        return new self($this->kind, $this->service, $this->handler, $this->key, $scope);
    }

    public function serviceName(): string
    {
        return $this->service;
    }

    public function handlerName(): string
    {
        return $this->handler;
    }

    /** The virtual object or workflow key; null for a service. */
    public function key(): ?string
    {
        return $this->key;
    }

    /** The scope the target is addressed in ({@see Target::scoped()}); null when unscoped. */
    public function scope(): ?string
    {
        return $this->scope;
    }

    /** @return array{kind: 'service'|'object'|'workflow', service: string, handler: string, key: ?string, scope: ?string} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'service' => $this->service,
            'handler' => $this->handler,
            'key' => $this->key,
            'scope' => $this->scope,
        ];
    }

    private static function assertNotEmpty(string $value, string $message): void
    {
        if ($value === '') {
            throw new InvalidArgumentException($message);
        }
    }

    private static function assertValidServiceName(string $name): void
    {
        if (preg_match('/^([a-zA-Z]|_[a-zA-Z0-9])[a-zA-Z0-9._-]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid Restate service name {$name}.");
        }
    }

    private static function assertValidHandlerName(string $name): void
    {
        if (preg_match('/^([a-zA-Z]|_[a-zA-Z0-9])[a-zA-Z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid Restate handler name {$name}.");
        }
    }
}
