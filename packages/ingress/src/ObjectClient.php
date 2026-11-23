<?php

declare(strict_types=1);

namespace Restate\Ingress;

use Restate\Internal\FlowControlKeys;
use Restate\Target;

/** Requests to the handlers of one virtual object (one key), through the ingress. */
final readonly class ObjectClient
{
    /**
     * @param ?string $scope the scope its targets are in ({@see IngressClient::scope()}); null for none
     *
     * @internal built by {@see IngressClient::objectClient()} and {@see Scope::objectClient()}
     */
    public function __construct(
        private IngressClient $client,
        private string $object,
        private string $key,
        private ?string $scope = null,
    ) {
        Target::object($object, $key, 'request');
        if ($scope !== null) {
            FlowControlKeys::assertScope($scope);
        }
    }

    public function request(string $handler, mixed $payload = null): ClientRequest
    {
        $target = Target::object($this->object, $this->key, $handler);

        return new ClientRequest($this->client, $this->scope === null ? $target : $target->scoped($this->scope), $payload);
    }
}
