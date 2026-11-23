<?php

declare(strict_types=1);

namespace Restate\Ingress;

use Restate\Internal\FlowControlKeys;
use Restate\Serde;
use Restate\Target;

/**
 * One workflow run (one key), through the ingress: submit its `run` handler, wait for or read its result, and call its
 * shared handlers.
 */
final readonly class WorkflowClient
{
    /**
     * @param ?string $scope the scope the run is in ({@see IngressClient::scope()}); null for none
     *
     * @internal built by {@see IngressClient::workflowClient()} and {@see Scope::workflowClient()}
     */
    public function __construct(
        private IngressClient $client,
        private string $workflow,
        private string $key,
        private ?string $scope = null,
    ) {
        Target::workflow($workflow, $key, 'run');
        if ($scope !== null) {
            FlowControlKeys::assertScope($scope);
        }
    }

    /**
     * Starts the workflow's `run` handler without waiting for it. A workflow runs once per key: submitting a key again
     * returns the existing run with {@see SendStatus::PreviouslyAccepted}. For headers, a delay or another serde, use
     * `request('run', $input)` and send that.
     *
     * @throws IngressException
     */
    public function submit(mixed $input = null): SendResponse
    {
        return $this->request('run', $input)->send();
    }

    /**
     * Waits for the workflow's `run` to complete and returns its result.
     *
     * @throws \Restate\TerminalError the workflow's failure
     * @throws IngressException
     */
    public function attach(?Serde $serde = null): mixed
    {
        return $this->client->workflowInvocation($this->workflow, $this->key, scope: $this->scope)->attach($serde);
    }

    /**
     * Reads the result of the workflow's `run` without waiting: not ready while it runs.
     *
     * @throws \Restate\TerminalError the workflow's failure
     * @throws IngressException
     */
    public function output(?Serde $serde = null): Output
    {
        return $this->client->workflowInvocation($this->workflow, $this->key, scope: $this->scope)->output($serde);
    }

    /** A request to one of the workflow's handlers: `run`, or a shared handler such as a signal or a query. */
    public function request(string $handler, mixed $payload = null): ClientRequest
    {
        $target = Target::workflow($this->workflow, $this->key, $handler);

        return new ClientRequest($this->client, $this->scope === null ? $target : $target->scoped($this->scope), $payload);
    }
}
