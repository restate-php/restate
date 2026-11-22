<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Restate\Logging\ReplayAwareLogger;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\RecordingLogger;
use Restate\Tests\Support\ScriptedInvocation;

final class ReplayAwareLoggerTest extends TestCase
{
    /** What the logger adds for the invocation of {@see Contexts::service()}. */
    private const array INVOCATION = ['restate.invocation.id' => 'inv_test', 'restate.invocation.target' => 'TestService/handle'];

    public function testLogForwardsExactlyOnceWhenNotReplayingAndPreservesContext(): void
    {
        $inner = new RecordingLogger();
        $logger = $this->logger(false, $inner);
        $context = ['id' => '1', 'details' => ['attempt' => 2]];

        $logger->log('info', 'hello', $context);

        self::assertSame([['info', 'hello', $context + self::INVOCATION]], $inner->records);
    }

    public function testTheCallersContextWinsOverTheInvocationKeys(): void
    {
        $inner = new RecordingLogger();

        $this->logger(false, $inner)->info('hello', ['restate.invocation.id' => 'mine']);

        self::assertSame([['info', 'hello', ['restate.invocation.id' => 'mine', 'restate.invocation.target' => 'TestService/handle']]], $inner->records);
    }

    public function testAKeyedTargetIncludesTheKey(): void
    {
        $inner = new RecordingLogger();

        new ReplayAwareLogger(Contexts::object(ScriptedInvocation::new(), 'alice'), $inner)->info('hello');

        self::assertSame('TestObject/alice/handle', $inner->records[0][2]['restate.invocation.target'] ?? null);
    }

    public function testLogSuppressesLogsWhenReplaying(): void
    {
        $inner = new RecordingLogger();
        $logger = $this->logger(true, $inner);

        $logger->log('warning', 'hello', ['id' => '1']);

        self::assertSame([], $inner->records);
    }

    #[DataProvider('levelProvider')]
    /** @param \Closure(LoggerInterface, string, array<mixed>): void $log */
    public function testConvenienceMethodsForwardExpectedLevels(\Closure $log, string $level): void
    {
        $inner = new RecordingLogger();
        $logger = $this->logger(false, $inner);

        $log($logger, 'message', ['k' => 'v']);

        self::assertSame([[$level, 'message', ['k' => 'v'] + self::INVOCATION]], $inner->records);
    }

    /** @return iterable<string, array{0: \Closure(LoggerInterface, string, array<mixed>): void, 1: string}> */
    public static function levelProvider(): iterable
    {
        yield 'emergency' => [static fn (LoggerInterface $logger, string $message, array $context) => $logger->emergency($message, $context), 'emergency'];
        yield 'alert' => [static fn (LoggerInterface $logger, string $message, array $context) => $logger->alert($message, $context), 'alert'];
        yield 'critical' => [static fn (LoggerInterface $logger, string $message, array $context) => $logger->critical($message, $context), 'critical'];
        yield 'error' => [static fn (LoggerInterface $logger, string $message, array $context) => $logger->error($message, $context), 'error'];
        yield 'warning' => [static fn (LoggerInterface $logger, string $message, array $context) => $logger->warning($message, $context), 'warning'];
        yield 'notice' => [static fn (LoggerInterface $logger, string $message, array $context) => $logger->notice($message, $context), 'notice'];
        yield 'info' => [static fn (LoggerInterface $logger, string $message, array $context) => $logger->info($message, $context), 'info'];
        yield 'debug' => [static fn (LoggerInterface $logger, string $message, array $context) => $logger->debug($message, $context), 'debug'];
    }

    private function logger(bool $replaying, ?RecordingLogger $inner = null): ReplayAwareLogger
    {
        $operations = ScriptedInvocation::new();
        $operations->replaying = $replaying;

        return new ReplayAwareLogger(
            Contexts::service($operations),
            $inner ?? new RecordingLogger(),
        );
    }
}
