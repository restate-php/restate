<?php

declare(strict_types=1);

use Restate\Context;
use Restate\Service;
use Restate\Tests\Integration\Services\HookRecorder;
use Restate\Tests\Integration\Services\InsufficientFunds;

// Runtime Execution Hooks (HooksTest): a recording interceptor on the service, and handlers that throw the domain
// exception the endpoint's error mapper (tests/Integration/Support/endpoint.php) maps to a terminal 422.

return Service::new('Hooks')
    ->interceptor(new HookRecorder())
    // A run, then a sleep that suspends the first attempt, then a second run in the attempt that resumes.
    ->handler('traced', static function (Context $ctx): string {
        $first = $ctx->run('before-sleep', static fn (): string => 'one');
        $ctx->sleep(100);
        $second = $ctx->run('after-sleep', static fn (): string => 'two');

        return "{$first} {$second}";
    })
    ->handler('failDomain', static fn (Context $ctx, string $account): never => throw new InsufficientFunds($account))
    ->handler('failDomainInRun', static fn (Context $ctx, string $account): string => $ctx->run(
        'charge',
        static fn (): never => throw new InsufficientFunds($account),
    ))
    ->definition();
