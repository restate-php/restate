<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Services\Payloads;

use Restate\Attributes\Handler;
use Restate\Attributes\Shared;
use Restate\Attributes\Workflow;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

/** A workflow whose `run` takes and returns DTOs (PayloadMappingTest). */
#[Workflow('PayloadCheckout')]
final class PayloadCheckout
{
    #[Handler]
    public function run(WorkflowContext $ctx, Order $order): Receipt
    {
        $approver = $ctx->promise('approval')->value();

        return Receipt::for($order, is_string($approver) ? $approver : null);
    }

    #[Handler]
    #[Shared]
    public function approve(WorkflowSharedContext $ctx, string $approver): void
    {
        $ctx->promise('approval')->resolve($approver);
    }
}
