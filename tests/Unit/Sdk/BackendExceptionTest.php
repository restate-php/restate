<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\Internal\BackendErrorCategory;
use Restate\Internal\BackendException;

final class BackendExceptionTest extends TestCase
{
    public function testCategoryDefaultsToInternal(): void
    {
        $e = new BackendException('State keys returned no keys.');

        self::assertSame(BackendErrorCategory::Internal, $e->category());
        self::assertSame(0, $e->getCode());
    }

    public function testCarriesCategoryCodeMessageAndPrevious(): void
    {
        $previous = new \RuntimeException('extension');
        $e = new BackendException('(570) Journal mismatch', 570, $previous, BackendErrorCategory::JournalMismatch);

        self::assertSame(BackendErrorCategory::JournalMismatch, $e->category());
        self::assertSame(570, $e->getCode());
        self::assertSame('(570) Journal mismatch', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }

    public function testCategoryNamesAreTheOnesTheRustCoreProduces(): void
    {
        self::assertSame(
            ['invalid_argument', 'suspended', 'protocol', 'journal_mismatch', 'closed', 'internal'],
            array_map(static fn (BackendErrorCategory $category): string => $category->value, BackendErrorCategory::cases()),
        );
    }

    public function testUnknownExtensionCategoryIsInternal(): void
    {
        self::assertSame(BackendErrorCategory::Protocol, BackendErrorCategory::fromExtension('protocol'));
        self::assertSame(BackendErrorCategory::Internal, BackendErrorCategory::fromExtension('something_new'));
    }
}
