<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Restate\Context;
use Restate\Endpoint;
use Restate\Service;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var object $app Slim-style app/router provided by your application. */
/** @var ResponseFactoryInterface $responseFactory Provided by your PSR runtime. */

$greeter = Service::new('Greeter')
    ->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}");

$handler = Endpoint::psr([$greeter], $responseFactory);

$route = function (ServerRequestInterface $request) use ($handler): ResponseInterface {
    return $handler->handle($request);
};

// Example Slim registration shape; keep commented so this file is syntax-checkable without Slim.
// $app->any('/{path:.*}', $route);
