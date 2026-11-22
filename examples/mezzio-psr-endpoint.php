<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseFactoryInterface;
use Restate\Context;
use Restate\Endpoint;
use Restate\Service;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var object $app Mezzio-style application provided by your bootstrap. */
/** @var ResponseFactoryInterface $responseFactory Provided by your PSR runtime. */

$greeter = Service::new('Greeter')
    ->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}");

$handler = Endpoint::psr([$greeter], $responseFactory);

// Example Mezzio registration shape; keep commented so this file is syntax-checkable without Mezzio.
// $app->any('/{path:.*}', $handler, 'restate.endpoint');
