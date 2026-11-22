<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\Internal\HeaderMap;

final class HeaderMapTest extends TestCase
{
    public function testCollectsContentTypeAndHttpHeaders(): void
    {
        $headers = HeaderMap::fromServer([
            'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6',
            'CONTENT_LENGTH' => '42',
            'HTTP_X_RESTATE_ID' => 'inv_123',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertContains(['content-type', 'application/vnd.restate.invocation.v6'], $headers);
        self::assertContains(['content-length', '42'], $headers);
        self::assertContains(['x-restate-id', 'inv_123'], $headers);
        self::assertContains(['accept', 'application/json'], $headers);
    }

    public function testSubstitutesBytesThatAreNotUtf8InHeaderNamesAndValues(): void
    {
        $headers = HeaderMap::fromServer([
            'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6',
            'HTTP_X_GREETING' => "caf\xE9 \u{2603}",
            "HTTP_X_CL\xC9" => 'value',
        ]);

        self::assertSame([
            ['content-type', 'application/vnd.restate.invocation.v6'],
            ['x-greeting', "caf\u{FFFD} \u{2603}"],
            ["x-cl\u{FFFD}", 'value'],
        ], $headers);
    }

    public function testContentTypeAndLengthUseCanonicalSapiValuesOnly(): void
    {
        $headers = HeaderMap::fromServer([
            'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6',
            'HTTP_CONTENT_TYPE' => 'application/vnd.restate.invocation.v6',
            'CONTENT_LENGTH' => '42',
            'HTTP_CONTENT_LENGTH' => '42',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertSame([
            ['content-type', 'application/vnd.restate.invocation.v6'],
            ['content-length', '42'],
            ['accept', 'application/json'],
        ], $headers);
    }

    /** FastCGI front doors (nginx's fastcgi_params, Caddy php_fastcgi) pass both, empty when the request had none. */
    public function testEmptyContentTypeAndLengthAreNoHeaders(): void
    {
        $headers = HeaderMap::fromServer([
            'CONTENT_TYPE' => '',
            'CONTENT_LENGTH' => '',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertSame([['accept', 'application/json']], $headers);
    }
}
