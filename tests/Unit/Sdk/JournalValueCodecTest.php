<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\Internal\InvocationAttempt;
use Restate\JournalValueCodec;
use Restate\Payload;
use Restate\Service;
use Restate\Internal\ServiceRegistry;
use Restate\Tests\Support\ScriptedInvocation;

final class JournalValueCodecTest extends TestCase
{
    public function testJournalValueCodecWrapsJsonHandlerInputAndOutputBytes(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler(
                'echo',
                static fn (Context $ctx, array $payload): array => ['seen' => $payload['name']],
            ),
        ], journalValueCodec: new JournalValueCodecTestPrefixCodec('codec'));

        self::assertSame('codec:{"seen":"Ada"}', $registry->invoke('Users', 'echo', 'codec:{"name":"Ada"}', $this->context()));
    }

    public function testJournalValueCodecDoesNotWrapExplicitBinaryPayloads(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Files')->handler(
                'echo',
                static fn (Context $ctx, string $payload): string => $payload,
                Payload::binary(),
                Payload::binary(),
            ),
        ], journalValueCodec: new JournalValueCodecTestPrefixCodec('codec'));

        self::assertSame('raw-bytes', $registry->invoke('Files', 'echo', 'raw-bytes', $this->context()));
    }

    private function context(): InvocationAttempt
    {
        return new InvocationAttempt(ScriptedInvocation::unused());
    }
}

final readonly class JournalValueCodecTestPrefixCodec implements JournalValueCodec
{
    public function __construct(private string $prefix)
    {
    }

    #[\Override]
    public function encode(string $bytes): string
    {
        return $this->prefix . ':' . $bytes;
    }

    #[\Override]
    public function decode(string $bytes): string
    {
        $prefix = $this->prefix . ':';
        if (!str_starts_with($bytes, $prefix)) {
            throw new \InvalidArgumentException("Expected {$this->prefix} encoded bytes.");
        }

        return substr($bytes, strlen($prefix));
    }
}
