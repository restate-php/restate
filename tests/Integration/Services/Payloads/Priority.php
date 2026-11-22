<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Services\Payloads;

enum Priority: string
{
    case Normal = 'normal';
    case Express = 'express';
}
