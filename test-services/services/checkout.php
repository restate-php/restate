<?php

declare(strict_types=1);

use Restate\Workflow;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

return Workflow::new('Checkout')
    ->run(static function (WorkflowContext $ctx, array $order): string {
        $approval = $ctx->promise('approval')->value();
        return $approval['status'];
    })
    ->sharedHandler('approve', static function (WorkflowSharedContext $ctx, array $approval): null {
        $ctx->promise('approval')->resolve($approval);
        return null;
    })
    ->sharedHandler('peekApproval', static fn (WorkflowSharedContext $ctx): mixed => $ctx->promise('approval')->peek())
    ->sharedHandler('peekThenApprove', static function (WorkflowSharedContext $ctx, array $approval): array {
        $before = $ctx->promise('approval')->peek();
        $ctx->promise('approval')->resolve($approval);
        $after = $ctx->promise('approval')->peek();

        return ['before' => $before, 'after' => $after];
    })
    ->definition();
