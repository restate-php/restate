<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use Restate\Tests\Integration\Support\IntegrationTestCase;

final class AwakeableTest extends IntegrationTestCase
{
    public function testResolvedAwakeable(): void
    {
        self::assertInvocationReturns(
            '{"status":"ok","count":3}',
            'Communicator/awaitAwakeable',
            '{"value":{"status":"ok","count":3}}',
            timeout: 20.0,
        );
    }

    public function testRejectedAwakeable(): void
    {
        self::assertInvocationReturns(
            '{"code":409,"message":"denied"}',
            'Communicator/awaitRejectedAwakeable',
            '{"code":409,"message":"denied"}',
            timeout: 20.0,
        );
    }
}
