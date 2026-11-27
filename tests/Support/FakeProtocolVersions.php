<?php

declare(strict_types=1);

namespace Restate\Tests\Support;

/**
 * The service protocol range the unit tests use where the backend's would go: what `Restate\Extension\protocol_versions()`
 * reports today. Tests that check the range is passed through use a different one on purpose.
 */
final class FakeProtocolVersions
{
    public const array RANGE = ['min' => 7, 'max' => 8];
}
