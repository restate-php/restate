<?php

declare(strict_types=1);

use Restate\Attributes as Restate;
use Restate\Context;
use Restate\Endpoint;

require dirname(__DIR__) . '/vendor/autoload.php';

#[Restate\Service('Greeter', documentation: 'Greeter service.', metadata: ['domain' => 'examples'])]
final class AttributeGreeter
{
    #[Restate\Handler('greet', documentation: 'Greets a person.', metadata: ['owner' => 'sdk'])]
    public function greet(Context $ctx, string $name): string
    {
        return "Hello {$name}";
    }
}

Endpoint::serve([new AttributeGreeter()]);
