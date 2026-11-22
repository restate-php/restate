<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\JsonSerde;
use Restate\Payload;
use Restate\Serde;

final class SerdeTest extends TestCase
{
    public function testJsonSerdeRoundTripsValues(): void
    {
        $serde = JsonSerde::default();

        self::assertSame('{"name":"Mary"}', $serde->serialize(['name' => 'Mary']));
        self::assertSame(['name' => 'Mary'], $serde->deserialize('{"name":"Mary"}'));
        self::assertSame('application/json', $serde->contentType());
    }

    public function testPayloadJsonExposesRuntimeSerde(): void
    {
        self::assertInstanceOf(Serde::class, Payload::json()->serde());
    }
}
