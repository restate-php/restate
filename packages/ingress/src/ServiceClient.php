<?php

declare(strict_types=1);

namespace Restate\Ingress;

use Restate\Internal\FlowControlKeys;
use Restate\Target;

/** Requests to the handlers of one service, through the ingress. */
final readonly class ServiceClient
{
    /**
     * @param ?string $scope the scope its targets are in ({@see IngressClient::scope()}); null for none
     *
     * @internal built by {@see IngressClient::serviceClient()} and {@see Scope::serviceClient()}
     */
    public function __construct(
        private IngressClient $client,
        private string $service,
        private ?string $scope = null,
    ) {
        if ($scope !== null) {
            FlowControlKeys::assertScope($scope);
        }
    }

    public function request(string $handler, mixed $payload = null): ClientRequest
    {
        $target = Target::service($this->service, $handler);

        return new ClientRequest($this->client, $this->scope === null ? $target : $target->scoped($this->scope), $payload);
    }
}
