<?php

declare(strict_types=1);

namespace Restate;

/**
 * Which payload of a handler a JSON Value Mapping or JSON Schema Metadata request is about.
 */
enum PayloadRole: string
{
    /** The handler's input: the request body it is invoked with. */
    case Input = 'input';

    /** The handler's output: the value it returns. */
    case Output = 'output';
}
