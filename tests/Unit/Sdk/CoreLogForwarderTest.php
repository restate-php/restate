<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Restate\Internal\Backend\CoreLogRecord;
use Restate\Internal\CoreLogForwarder;
use Restate\Tests\Support\RecordingLogger;

final class CoreLogForwarderTest extends TestCase
{
    public function testRecordsCarryTheContextTheTargetAndTheFields(): void
    {
        $logger = new RecordingLogger();
        $forwarder = new CoreLogForwarder($logger, 'debug', ['restate.invocation.id' => 'inv_1']);

        $forwarder->forward([new CoreLogRecord('debug', 'restate_sdk_shared_core::vm', 'Failed with error 570', ['handle' => '3'])]);

        self::assertSame([[LogLevel::DEBUG, 'Failed with error 570', [
            'restate.invocation.id' => 'inv_1',
            'restate.core.target' => 'restate_sdk_shared_core::vm',
            'restate.core.handle' => '3',
        ]]], $logger->records);
    }

    public function testTheContextCanBeReplacedOnceTheInputNamesTheInvocation(): void
    {
        $logger = new RecordingLogger();
        $forwarder = new CoreLogForwarder($logger, 'debug', ['restate.invocation.id' => 'inv_1', 'restate.invocation.target' => 'Counter/add']);

        $forwarder->setContext(['restate.invocation.id' => 'inv_1', 'restate.invocation.target' => 'Counter/alice/add']);
        $forwarder->forward([new CoreLogRecord('warn', 'restate_sdk_shared_core', 'careful')]);

        self::assertSame('Counter/alice/add', $logger->records[0][2]['restate.invocation.target'] ?? null);
    }

    /** @return iterable<string, array{string, string}> */
    public static function coreToPsr(): iterable
    {
        yield 'error' => ['error', LogLevel::ERROR];
        yield 'warn' => ['warn', LogLevel::WARNING];
        yield 'info' => ['info', LogLevel::INFO];
        yield 'debug' => ['debug', LogLevel::DEBUG];
        yield 'trace' => ['trace', LogLevel::DEBUG];
    }

    /** @param 'error'|'warn'|'info'|'debug'|'trace' $core */
    #[DataProvider('coreToPsr')]
    public function testCoreLevelsMapToPsrLevels(string $core, string $psr): void
    {
        $logger = new RecordingLogger();

        new CoreLogForwarder($logger, 'debug')->forward([new CoreLogRecord($core, 't', 'm')]);

        self::assertSame($psr, $logger->records[0][0]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function psrToCore(): iterable
    {
        yield 'emergency' => [LogLevel::EMERGENCY, 'error'];
        yield 'alert' => [LogLevel::ALERT, 'error'];
        yield 'critical' => [LogLevel::CRITICAL, 'error'];
        yield 'error' => [LogLevel::ERROR, 'error'];
        yield 'warning' => [LogLevel::WARNING, 'warn'];
        yield 'notice' => [LogLevel::NOTICE, 'info'];
        yield 'info' => [LogLevel::INFO, 'info'];
        yield 'debug' => [LogLevel::DEBUG, 'debug'];
    }

    #[DataProvider('psrToCore')]
    public function testAPsrLevelCapturesTheMatchingCoreLevel(string $psr, string $core): void
    {
        self::assertSame($core, CoreLogForwarder::coreLevel($psr));
    }

    public function testAnUnknownLevelIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown PSR-3 log level 'trace'");

        CoreLogForwarder::coreLevel('trace');
    }

    public function testALoggerThatThrowsLosesTheRecordButDoesNotFail(): void
    {
        $logger = new class () implements LoggerInterface {
            use LoggerTrait;

            /** @param array<mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('disk full');
            }
        };

        new CoreLogForwarder($logger, 'debug')->forward([new CoreLogRecord('warn', 't', 'm')]);

        $this->addToAssertionCount(1);
    }
}
