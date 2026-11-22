<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

final class PsrTestResponseFactory implements ResponseFactoryInterface
{
    /** @var list<int> */
    public array $statusCodes = [];

    #[\Override]
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        $this->statusCodes[] = $code;

        return new PsrTestResponse($code, reasonPhrase: $reasonPhrase);
    }
}
