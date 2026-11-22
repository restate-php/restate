<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Restate\Context;
use Restate\Endpoint;
use Restate\Service;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var ServerRequestInterface $serverRequest Provided by your framework/runtime. */
/** @var ResponseFactoryInterface $responseFactory Provided by your framework/runtime. */

$greeter = Service::new('Greeter')
    ->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}");

$handler = Endpoint::psr([$greeter], $responseFactory);
$response = $handler->handle($serverRequest);
