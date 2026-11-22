<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\Request;
use Restate\Target;

final class RequestTest extends TestCase
{
    public function testHeaderNamesAreLowercasedWithTheLastValueWinning(): void
    {
        $request = new Request(
            'inv_1',
            Target::service('Greeter', 'greet'),
            ['X-Custom' => 'first', 'x-custom' => 'second', 'X-Other' => 'value'],
            ['Content-Type' => 'application/vnd.restate.invocation.v7'],
            '"Mary"',
        );

        self::assertSame(['x-custom' => 'second', 'x-other' => 'value'], $request->headers);
        self::assertSame(['content-type' => 'application/vnd.restate.invocation.v7'], $request->attemptHeaders);
    }

    public function testHeaderLooksUpAnInvocationHeaderIgnoringCase(): void
    {
        $request = new Request('inv_1', Target::service('Greeter', 'greet'), ['x-custom' => 'value'], ['x-attempt' => 'attempt'], '');

        self::assertSame('value', $request->header('X-Custom'));
        self::assertNull($request->header('x-attempt'));
        self::assertNull($request->header('x-missing'));
    }

    public function testIdempotencyKeyScopeAndLimitKeyDefaultToNull(): void
    {
        $request = new Request('inv_1', Target::service('Greeter', 'greet'), [], [], '');

        self::assertNull($request->idempotencyKey);
        self::assertNull($request->scope);
        self::assertNull($request->limitKey);
    }
}
