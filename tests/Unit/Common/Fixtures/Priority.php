<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Common\Fixtures;

enum Priority: string
{
    case Normal = 'normal';
    case Express = 'express';
}
