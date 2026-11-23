<?php

declare(strict_types=1);

namespace App;

/**
 * The input of Greeter/greet: the SDK maps the request's JSON to it and publishes its JSON Schema to Restate.
 */
final readonly class Greeting
{
    public function __construct(public string $name)
    {
    }
}
