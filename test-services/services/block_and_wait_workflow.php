<?php

declare(strict_types=1);

use Restate\Payload;
use Restate\TerminalError;
use Restate\Workflow;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

return Workflow::new('BlockAndWaitWorkflow')
    ->run(static function (WorkflowContext $ctx, string $input): string {
        $ctx->set('state', $input);
        $output = $ctx->promise('unblock')->value();

        return is_string($output) ? $output : '';
    })
    ->sharedHandler('unblock', static function (WorkflowSharedContext $ctx, string $output): null {
        $ctx->promise('unblock')->resolve($output);

        return null;
    }, output: Payload::empty())
    ->sharedHandler('reject', static function (WorkflowSharedContext $ctx, array $failure): null {
        $metadata = $failure['metadata'] ?? [];
        $ctx->promise('unblock')->reject(new TerminalError(
            is_string($failure['message'] ?? null) ? $failure['message'] : 'Promise rejected',
            is_int($failure['code'] ?? null) ? $failure['code'] : 500,
            is_array($metadata) ? $metadata : [],
        ));

        return null;
    }, output: Payload::empty())
    ->sharedHandler('getState', static function (WorkflowSharedContext $ctx, mixed $_ = null): ?string {
        $state = $ctx->get('state');

        return is_string($state) ? $state : null;
    }, Payload::empty())
    ->definition();
