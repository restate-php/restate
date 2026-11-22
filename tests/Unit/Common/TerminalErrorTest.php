<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Common;

use PHPUnit\Framework\TestCase;
use Restate\TerminalError;

final class TerminalErrorTest extends TestCase
{
    public function testCarriesTerminalStatusCodeAndMessage(): void
    {
        $exception = new TerminalError('Payment declined', 400);

        self::assertSame('Payment declined', $exception->getMessage());
        self::assertSame(400, $exception->statusCode());
    }

    public function testDefaultStatusCodeIsInternalError(): void
    {
        $exception = new TerminalError('Nope');

        self::assertSame(500, $exception->statusCode());
    }

    public function testMetadataDefaultsToEmptyArray(): void
    {
        $exception = new TerminalError('Payment declined', 400);

        self::assertSame([], $exception->metadata());
    }

    public function testMetadataReturnsStringMap(): void
    {
        $exception = new TerminalError('Denied', 403, ['key1' => 'value1', 'key2' => 'value2']);

        self::assertSame(['key1' => 'value1', 'key2' => 'value2'], $exception->metadata());
    }

    public function testMetadataAcceptsNumericStringKeys(): void
    {
        // PHP stores the key '404' as the int 404; it is still the string key "404" on the wire.
        $exception = new TerminalError('Not found', 404, ['404' => 'not found', '0' => 'first', 'reason' => 'gone']);

        self::assertSame([404 => 'not found', 0 => 'first', 'reason' => 'gone'], $exception->metadata());
    }

    public function testMetadataRejectsNonStringValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Terminal metadata must be a string-to-string map.');

        new TerminalError('Denied', 403, ['key1' => 1]);
    }

    public function testRejectsStatusCodesOutsideTerminalRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Terminal status code must be between 400 and 599.');

        new TerminalError('Bad status', 70000);
    }
}
