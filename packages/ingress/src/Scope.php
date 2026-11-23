<?php

declare(strict_types=1);

namespace Restate\Ingress;

use Restate\Internal\FlowControlKeys;

/**
 * Clients for requests to targets within one scope, through the ingress ({@see IngressClient::scope()}): every
 * target they build is {@see \Restate\Target::scoped()} to it, and a workflow's attach and output find the run in the
 * scope.
 */
final readonly class Scope
{
    /**
     * @internal built by {@see IngressClient::scope()}
     */
    public function __construct(
        private IngressClient $client,
        private string $scope,
    ) {
        FlowControlKeys::assertScope($scope);
    }

    /** The scope's name. */
    public function name(): string
    {
        return $this->scope;
    }

    public function serviceClient(string $service): ServiceClient
    {
        return new ServiceClient($this->client, $service, $this->scope);
    }

    public function objectClient(string $object, string $key): ObjectClient
    {
        return new ObjectClient($this->client, $object, $key, $this->scope);
    }

    public function workflowClient(string $workflow, string $key): WorkflowClient
    {
        return new WorkflowClient($this->client, $workflow, $key, $this->scope);
    }
}
