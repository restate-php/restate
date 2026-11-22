<?php

declare(strict_types=1);

use Restate\Workflow;
use Restate\WorkflowContext;

return Workflow::new('EchoWorkflow')
    ->run(static fn (WorkflowContext $ctx, string $value): string => "workflow {$value}")
    ->definition();
