<?php

declare(strict_types=1);

namespace Restate\Tests\Extension;

use Psr\Log\LogLevel;
use Restate\Context;
use Restate\Endpoint;
use Restate\Extension\ExtensionException;
use Restate\Extension\Vm;
use Restate\Internal\Backend\Extension\Backend;
use Restate\Internal\BackendErrorCategory;
use Restate\Internal\BackendException;
use Restate\Internal\CoreLogForwarder;
use Restate\Service;
use Restate\Tests\Support\Protocol\FrameCodec;
use Restate\Tests\Support\RecordingLogger;

/**
 * shared-core's own log events reach the endpoint's logger: the extension captures them per VM at the level asked for
 * (`Vm::__construct($headers, $log_level)`, `Vm::takeLogs()`), and `Extension\Invocation` forwards them after every
 * call (docs/decisions/0027-sdk-logging.md).
 */
final class SharedCoreLogsTest extends ExtensionTestCase
{
    public function testAJournalMismatchReachesTheLoggerAsTheCallFails(): void
    {
        $logger = new RecordingLogger();
        $invocation = self::running(self::sleptJournal(), coreLogs: new CoreLogForwarder($logger, 'debug', ['restate.invocation.id' => self::INVOCATION_ID]));

        try {
            $invocation->sysGetState('count');
            self::fail('Expected a journal mismatch.');
        } catch (BackendException $e) {
            self::assertSame(BackendErrorCategory::JournalMismatch, $e->category());
        }

        $mismatch = array_values(array_filter($logger->records, static fn (array $record): bool => str_starts_with((string) $record[1], 'Failed with error')));
        self::assertCount(1, $mismatch, (string) json_encode($logger->messages()));
        [$level, $message, $context] = $mismatch[0];
        self::assertSame(LogLevel::DEBUG, $level);
        self::assertStringContainsString('Found a mismatch between the code paths taken during the previous execution', (string) $message);
        self::assertSame(self::INVOCATION_ID, $context['restate.invocation.id'] ?? null);
        self::assertIsString($context['restate.core.target'] ?? null);
        self::assertStringStartsWith('restate_sdk_shared_core', $context['restate.core.target']);
    }

    public function testRecordsAreForwardedAfterTheCallThatLoggedThem(): void
    {
        $logger = new RecordingLogger();
        $invocation = self::running(coreLogs: new CoreLogForwarder($logger, 'debug'));
        self::assertSame([[LogLevel::DEBUG, 'Start invocation']], $logger->messages());

        $handle = $invocation->sysSleep(60_000);
        $invocation->doAwait($handle);

        self::assertContains([LogLevel::DEBUG, 'Suspending'], $logger->messages());
    }

    public function testALevelAboveDebugForwardsNoneOfTheDebugEvents(): void
    {
        $logger = new RecordingLogger();
        $invocation = self::running(self::sleptJournal(), coreLogs: new CoreLogForwarder($logger, 'info'));

        try {
            $invocation->sysGetState('count');
        } catch (BackendException) {
        }

        self::assertSame([], $logger->records);
    }

    public function testAVmWithoutALogLevelCapturesNothing(): void
    {
        $vm = self::runningVm(self::sleptJournal());

        try {
            $vm->sysGetState('count');
        } catch (ExtensionException) {
        }

        self::assertSame([], $vm->takeLogs());
    }

    public function testTakingTheLogsDrainsThem(): void
    {
        $vm = self::runningVm(logLevel: 'debug');

        self::assertNotSame([], $vm->takeLogs());
        self::assertSame([], $vm->takeLogs());
    }

    public function testAnUnknownLogLevelIsRejected(): void
    {
        try {
            // @phpstan-ignore argument.type (an unknown level is the point: the extension must reject it)
            new Vm([['content-type', self::V5]], 'verbose');
            self::fail('Expected a ExtensionException.');
        } catch (ExtensionException $e) {
            self::assertSame('invalid_argument', $e->category);
            self::assertStringContainsString("Unknown log level 'verbose'", $e->getMessage());
        }
    }

    /**
     * The whole path: an endpoint on the extension with a logger at debug runs a handler that diverges from the
     * journal. shared-core's record of the mismatch arrives before the SDK's warning about the failed attempt, both
     * with the invocation's keys.
     */
    public function testAnEndpointForwardsTheMismatchBeforeItsOwnWarning(): void
    {
        $logger = new RecordingLogger();
        $endpoint = Endpoint::create(
            new Backend(),
            [Service::new('Greeter')->handler('greet', static fn (Context $ctx): string => $ctx->run('step', static fn (): string => 'done'))],
            logger: $logger,
            coreLogLevel: LogLevel::DEBUG,
        );
        $body = FrameCodec::startFrame(self::INVOCATION_ID, 3) . FrameCodec::inputCommandFrame('"Mary"') . implode('', self::sleptJournal());

        $response = $endpoint->handleRequest(['REQUEST_URI' => '/invoke/Greeter/greet', 'CONTENT_TYPE' => self::V5], $body);

        self::assertSame(200, $response->statusCode);
        self::assertSame([FrameCodec::ERROR], FrameCodec::messageTypes($response->body));
        $messages = array_map(static fn (array $record): string => (string) $record[1], $logger->records);
        $mismatch = array_key_first(array_filter($messages, static fn (string $message): bool => str_starts_with($message, 'Failed with error')));
        $warning = array_search('Invocation attempt failed; Restate retries it', $messages, true);
        self::assertIsInt($mismatch, (string) json_encode($messages));
        self::assertIsInt($warning, (string) json_encode($messages));
        self::assertLessThan($warning, $mismatch);
        self::assertSame(['restate.invocation.id' => self::INVOCATION_ID, 'restate.invocation.target' => 'Greeter/greet'], array_intersect_key($logger->records[$mismatch][2], ['restate.invocation.id' => 1, 'restate.invocation.target' => 1]));
    }

    /**
     * A journal that recorded a sleep, for a handler that does something else.
     *
     * @return list<string>
     */
    private static function sleptJournal(): array
    {
        return [FrameCodec::sleepCommandFrame(1, 1), FrameCodec::completionFrame(FrameCodec::SLEEP_COMPLETION, 1)];
    }
}
