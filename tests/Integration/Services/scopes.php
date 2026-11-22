<?php

declare(strict_types=1);

use Restate\AttachTarget;
use Restate\Context;
use Restate\ObjectContext;
use Restate\Service;
use Restate\Target;
use Restate\VirtualObject;
use Restate\Workflow;
use Restate\WorkflowContext;

// Scope and limit key on calls, sends and attach (ScopeTest, IngressScopeTest). ScopeInfo, ScopedCounter and
// ScopeWorkflow report the scope and limit key their invocation was called with; Scopes calls them within a scope.

$describe = static fn (Context $ctx): array => [
    'id' => $ctx->request()->id,
    'scope' => $ctx->request()->scope,
    'limitKey' => $ctx->request()->limitKey,
    'idempotencyKey' => $ctx->request()->idempotencyKey,
];

return [
    Service::new('ScopeInfo')
        ->handler('describe', static fn (Context $ctx, mixed $_ = null): array => $describe($ctx))
        ->definition(),

    VirtualObject::new('ScopedCounter')
        ->handler('add', static function (ObjectContext $ctx, int $value) use ($describe): array {
            $count = (int) ($ctx->get('count') ?? 0) + $value;
            $ctx->set('count', $count);

            return ['count' => $count] + $describe($ctx);
        })
        ->definition(),

    Workflow::new('ScopeWorkflow')
        ->run(static fn (WorkflowContext $ctx, mixed $_ = null): array => $describe($ctx))
        ->definition(),

    Service::new('Scopes')
        // A call within a scope, with a limit key.
        ->handler('call', static fn (Context $ctx, array $request): array => $ctx->scope($request['scope'])
            ->serviceClient('ScopeInfo')
            ->request('describe')
            ->limitKey($request['limitKey'])
            ->call())
        // A send within a scope (delayed), then waiting for its output.
        ->handler('send', static fn (Context $ctx, array $request): array => $ctx->scope($request['scope'])
            ->serviceClient('ScopeInfo')
            ->request('describe')
            ->limitKey($request['limitKey'])
            ->sendAfter(10)
            ->output())
        // The same object key and idempotency key in two scopes and outside any: three instances and invocations.
        ->handler('objectPerScope', static function (Context $ctx, array $request): array {
            $add = static fn (?string $scope, int $value): array => $ctx->call(
                $scope === null ? Target::object('ScopedCounter', $request['key'], 'add') : Target::object('ScopedCounter', $request['key'], 'add')->scoped($scope),
                $value,
                'add-once',
            );

            return [
                'first' => $add($request['scopes'][0], 1),
                'second' => $add($request['scopes'][1], 10),
                'unscoped' => $add(null, 100),
                'firstAgain' => $add($request['scopes'][0], 1000),
            ];
        })
        // A sent idempotent request and a workflow run within a scope, attached to by their scoped targets.
        ->handler('attach', static function (Context $ctx, array $request): array {
            $scope = $ctx->scope($request['scope']);
            $scope->serviceClient('ScopeInfo')->request('describe')->idempotencyKey($request['idempotencyKey'])->send();
            $scope->workflowClient('ScopeWorkflow', $request['workflowKey'])->request('run')->send();

            return [
                'idempotent' => $ctx->attach(AttachTarget::idempotency(Target::service('ScopeInfo', 'describe')->scoped($request['scope']), $request['idempotencyKey']))->output(),
                'workflow' => $ctx->attach(AttachTarget::workflow('ScopeWorkflow', $request['workflowKey'], $request['scope']))->output(),
            ];
        })
        ->definition(),
];
