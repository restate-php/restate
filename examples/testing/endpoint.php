<?php

declare(strict_types=1);

/*
 * The service under test: the same entrypoint production serves (here a Greeter and a Counter). The test harness runs
 * it with `php -S` and the Restate extension loaded; see GreeterTest.php next to it.
 */

use Restate\Context;
use Restate\Endpoint;
use Restate\ObjectContext;
use Restate\Service;
use Restate\VirtualObject;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$greeter = Service::new('Greeter')
    ->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}");

$counter = VirtualObject::new('Counter')
    ->handler('add', static function (ObjectContext $ctx, int $amount): int {
        $value = (int) ($ctx->get('value') ?? 0) + $amount;
        $ctx->set('value', $value);

        return $value;
    });

Endpoint::serve([$greeter, $counter]);
