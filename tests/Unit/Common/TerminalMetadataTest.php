<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Common;

use PHPUnit\Framework\TestCase;
use Restate\Internal\TerminalMetadata;

final class TerminalMetadataTest extends TestCase
{
    public function testForBackendSubstitutesBytesThatAreNotUtf8InKeysAndValues(): void
    {
        self::assertSame(
            ['raison' => "vol\u{FFFD}e", "cl\u{FFFD}" => 'x', 404 => "\u{2603}"],
            TerminalMetadata::forBackend(['raison' => "vol\xE9e", "cl\xE9" => 'x', '404' => "\u{2603}"]),
        );
    }
}
