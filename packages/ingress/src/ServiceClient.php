<?php

declare(strict_types=1);

namespace Restate\Ingress;

use Restate\Target;

/** Requests to the handlers of one service, through the ingress. */
final readonly class ServiceClient
{
    /** @param ?string $scope the scope its targets are in ({@see IngressClient::scope()}); null for none */
    public function __construct(
        private IngressClient $client,
        private string $service,
        private ?string $scope = null,
    ) {
    }

    public function request(string $handler, mixed $payload = null): ClientRequest
    {
        $target = Target::service($this->service, $handler);

        return new ClientRequest($this->client, $this->scope === null ? $target : $target->scoped($this->scope), $payload);
    }
}
