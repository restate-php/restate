<?php

declare(strict_types=1);

namespace Restate\Ingress;

/** Whether Restate accepted a send now, or had already accepted it (the same idempotency key, or a workflow key). */
enum SendStatus: string
{
    case Accepted = 'Accepted';
    case PreviouslyAccepted = 'PreviouslyAccepted';
}
