<?php

declare(strict_types=1);

use Restate\Context;
use Restate\Endpoint;
use Restate\Service;

require dirname(__DIR__) . '/vendor/autoload.php';

$greeter = Service::new('Greeter')
    ->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}");

// Needs the Restate PHP extension loaded (php -d extension=…); see docs/serving.md.
Endpoint::serve([$greeter]);
