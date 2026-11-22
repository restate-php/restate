<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Restate\Context;
use Restate\Endpoint;
use Restate\Internal\Backend\CoreLogRecord;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\BackendException;
use Restate\Internal\Backend\RunCompletionStatus;
use Restate\Logging\ReplayAwareLogger;
use Restate\ObjectContext;
use Restate\OnMaxAttempts;
use Restate\PauseError;
use Restate\RetryableError;
use Restate\RunRetryPolicy;
use Restate\Service;
use Restate\TerminalError;
use Restate\Tests\Support\FakeBackend;
use Restate\Tests\Support\FakeIdentityVerifier;
use Restate\Tests\Support\RecordingLogger;
use Restate\Tests\Support\ScriptedInvocation;
use Restate\VirtualObject;

/**
 * What the endpoint logs about its own work to the logger it was built with (docs/decisions/0027-sdk-logging.md).
 */
final class EndpointLoggingTest extends TestCase
{
    private const array SERVER = [
        'REQUEST_URI' => '/invoke/Greeter/greet',
        'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6',
        'HTTP_X_RESTATE_INVOCATION_ID' => 'inv_1',
    ];

    private const array INVOCATION = ['restate.invocation.id' => 'inv_1', 'restate.invocation.target' => 'Greeter/greet'];

    private RecordingLogger $logger;

    private ScriptedInvocation $invocation;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->invocation = ScriptedInvocation::new();
        $this->invocation->input = new InvocationInput('inv_1', 0, '', [], '"Mary"');
    }

    public function testASuccessfulAttemptLogsItsStartAndEndAtDebug(): void
    {
        $this->handle(static fn (Context $ctx, string $name): string => "Hello {$name}");

        self::assertSame([
            [LogLevel::DEBUG, 'Starting invocation', self::INVOCATION],
            [LogLevel::DEBUG, 'Invocation completed', self::INVOCATION],
        ], $this->logger->records);
    }

    public function testAnAttemptThatReplaysSaysSo(): void
    {
        $this->invocation->replaying = true;

        $this->handle(static fn (Context $ctx, string $name): string => "Hello {$name}");

        self::assertSame([LogLevel::DEBUG, 'Replaying invocation', self::INVOCATION], $this->logger->records[0]);
    }

    public function testAKeyedTargetIncludesTheKey(): void
    {
        $this->invocation->input = new InvocationInput('inv_1', 0, 'alice', [], '');
        $endpoint = Endpoint::create(new FakeBackend($this->invocation), [VirtualObject::new('Counter')->handler('get', static fn (ObjectContext $ctx): int => 1)], logger: $this->logger);

        $endpoint->handleRequest(['REQUEST_URI' => '/invoke/Counter/get', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'], '');

        self::assertSame(['restate.invocation.id' => 'inv_1', 'restate.invocation.target' => 'Counter/alice/get'], $this->logger->records[0][2]);
    }

    public function testAFailedAttemptIsAWarningWithTheException(): void
    {
        $failure = new \RuntimeException('database down');

        $this->handle(static fn (Context $ctx, string $name): never => throw $failure);

        self::assertSame([LogLevel::WARNING, 'Invocation attempt failed; Restate retries it', self::INVOCATION + ['exception' => $failure]], $this->logger->record('Invocation attempt failed; Restate retries it'));
        self::assertSame([[LogLevel::DEBUG, 'Starting invocation'], [LogLevel::WARNING, 'Invocation attempt failed; Restate retries it']], $this->logger->messages());
        self::assertCount(1, $this->invocation->calls('notifyError'));
    }

    public function testARetryableErrorIsAWarningToo(): void
    {
        $failure = new RetryableError('rate limited', retryAfterMillis: 500);

        $this->handle(static fn (Context $ctx, string $name): never => throw $failure);

        self::assertSame([LogLevel::WARNING, 'Invocation attempt failed; Restate retries it', self::INVOCATION + ['exception' => $failure]], $this->logger->record('Invocation attempt failed; Restate retries it'));
    }

    public function testABackendFailureDuringTheHandlerIsAWarning(): void
    {
        $failure = new BackendException('Found a mismatch between the code paths taken during the previous execution and the paths taken during this execution.', 570);

        $this->handle(static function (Context $ctx) use ($failure): never {
            throw $failure;
        });

        self::assertSame(LogLevel::WARNING, $this->logger->record('Invocation attempt failed; Restate retries it')[0]);
    }

    public function testATerminalErrorIsInfo(): void
    {
        $failure = new TerminalError('declined', 402);

        $this->handle(static fn (Context $ctx, string $name): never => throw $failure);

        self::assertSame([
            [LogLevel::DEBUG, 'Starting invocation', self::INVOCATION],
            [LogLevel::INFO, 'Invocation failed with a terminal error', self::INVOCATION + ['exception' => $failure]],
        ], $this->logger->records);
    }

    public function testAPauseIsAWarning(): void
    {
        $pause = new PauseError('approval needed');

        $this->handle(static fn (Context $ctx, string $name): never => throw $pause);

        self::assertSame([LogLevel::WARNING, 'Invocation paused; it waits until it is resumed', self::INVOCATION + ['exception' => $pause]], $this->logger->record('Invocation paused; it waits until it is resumed'));
        self::assertCount(1, $this->invocation->calls('notifyPause'));
    }

    public function testASuspensionIsDebug(): void
    {
        $this->invocation->onNext('sysSleep');

        $this->handle(static function (Context $ctx): string {
            $ctx->sleep(1000);

            return 'done';
        });

        self::assertSame([
            [LogLevel::DEBUG, 'Starting invocation', self::INVOCATION],
            [LogLevel::DEBUG, 'Invocation suspended', self::INVOCATION],
        ], $this->logger->records);
    }

    public function testAFailedRunIsAWarningWithTheRunName(): void
    {
        $failure = new \RuntimeException('gateway timeout');

        $this->handle(static fn (Context $ctx): mixed => $ctx->run('charge', static fn (): never => throw $failure));

        self::assertSame([
            [LogLevel::DEBUG, 'Starting invocation', self::INVOCATION],
            [LogLevel::WARNING, "Run 'charge' failed; Restate retries it", self::INVOCATION + ['restate.run.name' => 'charge', 'exception' => $failure]],
            [LogLevel::DEBUG, 'Invocation suspended', self::INVOCATION],
        ], $this->logger->records);
    }

    public function testAFailedRunUnderAPausingPolicyMayPause(): void
    {
        $this->handle(static fn (Context $ctx): mixed => $ctx->run('charge', static fn (): never => throw new \RuntimeException('down'), RunRetryPolicy::fixedDelay(100, 2, onMaxAttempts: OnMaxAttempts::Pause)));

        self::assertSame(LogLevel::WARNING, $this->logger->record("Run 'charge' failed; Restate retries it, or pauses the invocation once the run retry policy is exhausted")[0]);
    }

    public function testARunWhosePolicyIsExhaustedIsAWarningAndItsTerminalFailureInfo(): void
    {
        $this->invocation->retryableRunStatus = RunCompletionStatus::Completed;

        $this->handle(static fn (Context $ctx): mixed => $ctx->run('charge', static fn (): never => throw new \RuntimeException('down'), RunRetryPolicy::none()));

        self::assertSame([
            [LogLevel::DEBUG, 'Starting invocation'],
            [LogLevel::WARNING, "Run 'charge' failed and its retry policy is exhausted; the failure is its result"],
            [LogLevel::INFO, 'Invocation failed with a terminal error'],
        ], $this->logger->messages());
    }

    public function testAPausingRunIsAWarning(): void
    {
        $pause = new PauseError('approval needed');

        $this->handle(static fn (Context $ctx): mixed => $ctx->run('approve', static fn (): never => throw $pause));

        self::assertSame([LogLevel::WARNING, "Run 'approve' paused the invocation; it waits until it is resumed", self::INVOCATION + ['restate.run.name' => 'approve', 'exception' => $pause]], $this->logger->record("Run 'approve' paused the invocation; it waits until it is resumed"));
    }

    public function testATerminalErrorFromARunIsNotLoggedByTheRun(): void
    {
        $this->handle(static fn (Context $ctx): mixed => $ctx->run('charge', static fn (): never => throw new TerminalError('declined', 402)));

        self::assertSame([
            [LogLevel::DEBUG, 'Starting invocation'],
            [LogLevel::INFO, 'Invocation failed with a terminal error'],
        ], $this->logger->messages());
    }

    public function testAReplayedRunLogsNothingAgain(): void
    {
        $this->invocation->replaying = true;
        $this->invocation->onNext('sysRun', NotificationResult::failure(500, 'down'));

        $this->handle(static function (Context $ctx): string {
            try {
                $ctx->run('charge', static fn (): never => throw new \RuntimeException('down'));
            } catch (TerminalError) {
            }

            return 'done';
        });

        self::assertSame([[LogLevel::DEBUG, 'Replaying invocation'], [LogLevel::DEBUG, 'Invocation completed']], $this->logger->messages());
    }

    public function testTheHandlersLoggerIsTheEndpointsLoggerWithTheInvocationKeys(): void
    {
        $this->handle(static function (Context $ctx): string {
            self::assertInstanceOf(ReplayAwareLogger::class, $ctx->logger());
            $ctx->logger()->notice('charging', ['amount' => 5]);

            return 'done';
        });

        self::assertSame([LogLevel::NOTICE, 'charging', ['amount' => 5] + self::INVOCATION], $this->logger->record('charging'));
    }

    public function testTheHandlersLoggerIsSilentWhileReplaying(): void
    {
        $this->invocation->replaying = true;

        $this->handle(static function (Context $ctx): string {
            $ctx->logger()->notice('charging');

            return 'done';
        });

        self::assertSame([[LogLevel::DEBUG, 'Replaying invocation'], [LogLevel::DEBUG, 'Invocation completed']], $this->logger->messages());
    }

    public function testWithoutALoggerNothingIsLoggedAndTheHandlersLoggerStillWorks(): void
    {
        $endpoint = Endpoint::create(new FakeBackend($this->invocation), [Service::new('Greeter')->handler('greet', static function (Context $ctx): string {
            $ctx->logger()->warning('nobody listens');

            throw new \RuntimeException('boom');
        })]);

        $response = $endpoint->handleRequest(self::SERVER, '');

        self::assertSame(200, $response->statusCode);
        self::assertCount(1, $this->invocation->calls('notifyError'));
    }

    public function testAnInternalServerErrorIsAnErrorWithTheException(): void
    {
        $failure = new BackendException('(415) Unsupported protocol version');
        $endpoint = Endpoint::create(new FakeBackend($failure), [Service::new('Greeter')->handler('greet', static fn (Context $ctx): string => 'x')], logger: $this->logger);

        $response = $endpoint->handleRequest(self::SERVER, '');

        self::assertSame(500, $response->statusCode);
        self::assertSame([
            [LogLevel::ERROR, 'Failed to handle the request', ['restate.invocation.id' => 'inv_1', 'restate.request.path' => '/invoke/Greeter/greet', 'exception' => $failure]],
        ], $this->logger->records);
    }

    public function testARequestWithoutAValidIdentityIsAnError(): void
    {
        $identityKey = 'publickeyv1_ChjENKeMvCtRnqG2mrBK1HmPKufgFUc98K8B3ononQvp';
        $endpoint = Endpoint::create(new FakeBackend(), [Service::new('Greeter')], identityKeys: [$identityKey], logger: $this->logger);

        $response = $endpoint->handleRequest(self::SERVER, '');

        self::assertSame(401, $response->statusCode);
        self::assertSame([
            [LogLevel::ERROR, 'Rejected a request without a valid Restate request identity', ['restate.invocation.id' => 'inv_1', 'restate.request.path' => '/invoke/Greeter/greet', 'restate.identity.error' => FakeIdentityVerifier::REJECTION]],
        ], $this->logger->records);
    }

    public function testAMissingContentTypeIsAWarning(): void
    {
        $this->endpoint()->handleRequest(['REQUEST_URI' => '/invoke/Greeter/greet'], '');

        self::assertSame([[LogLevel::WARNING, 'Missing content-type header', ['restate.request.path' => '/invoke/Greeter/greet']]], $this->logger->records);
    }

    public function testDiscoveryWithoutASupportedVersionIsAWarning(): void
    {
        $endpoint = $this->endpoint();

        $endpoint->handleRequest(['REQUEST_URI' => '/discover'], '');
        $endpoint->handleRequest(['REQUEST_URI' => '/discover', 'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v1+json'], '');

        self::assertSame([
            [LogLevel::WARNING, 'Missing accept header', ['restate.request.path' => '/discover']],
            [LogLevel::WARNING, "Unsupported service discovery protocol version 'application/vnd.restate.endpointmanifest.v1+json'", ['restate.request.path' => '/discover']],
        ], $this->logger->records);
    }

    public function testDiscoveryAndHealthLogNothing(): void
    {
        $endpoint = $this->endpoint();

        self::assertSame(200, $endpoint->handleRequest(['REQUEST_URI' => '/discover', 'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v3+json'], '')->statusCode);
        self::assertSame(200, $endpoint->handleRequest(['REQUEST_URI' => '/health'], '')->statusCode);
        self::assertSame(404, $endpoint->handleRequest(['REQUEST_URI' => '/nowhere'], '')->statusCode);

        self::assertSame([], $this->logger->records);
    }

    public function testWithALoggerTheBackendCapturesCoreLogsAtInfo(): void
    {
        $backend = new FakeBackend($this->invocation);
        Endpoint::create($backend, [Service::new('Greeter')->handler('greet', static fn (Context $ctx): string => 'x')], logger: $this->logger)
            ->handleRequest(self::SERVER, '');

        self::assertSame('info', $backend->coreLogs?->level);
    }

    public function testCoreLogsCarryTheInvocationKeysOnceTheInputIsRead(): void
    {
        $backend = new FakeBackend($this->invocation);
        $endpoint = Endpoint::create($backend, [VirtualObject::new('Counter')->handler('get', static fn (ObjectContext $ctx): int => 1)], logger: $this->logger, coreLogLevel: LogLevel::DEBUG);
        $this->invocation->input = new InvocationInput('inv_1', 0, 'alice', [], '');

        $endpoint->handleRequest(['REQUEST_URI' => '/invoke/Counter/get', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6', 'HTTP_X_RESTATE_INVOCATION_ID' => 'inv_1'], '');
        self::assertSame('debug', $backend->coreLogs?->level);
        $backend->coreLogs->forward([new CoreLogRecord('debug', 'restate_sdk_shared_core::vm', 'Start invocation')]);

        self::assertSame([LogLevel::DEBUG, 'Start invocation', ['restate.invocation.id' => 'inv_1', 'restate.invocation.target' => 'Counter/alice/get', 'restate.core.target' => 'restate_sdk_shared_core::vm']], $this->logger->record('Start invocation'));
    }

    public function testBeforeTheInputCoreLogsCarryWhatTheRequestSaid(): void
    {
        $backend = new FakeBackend(new BackendException('no VM'));
        Endpoint::create($backend, [Service::new('Greeter')->handler('greet', static fn (Context $ctx): string => 'x')], logger: $this->logger)
            ->handleRequest(self::SERVER, '');

        $backend->coreLogs?->forward([new CoreLogRecord('warn', 'restate_sdk_shared_core', 'early')]);

        self::assertSame(['restate.invocation.id' => 'inv_1', 'restate.invocation.target' => 'Greeter/greet', 'restate.core.target' => 'restate_sdk_shared_core'], $this->logger->record('early')[2]);
    }

    public function testCoreLogsAreNotCapturedWithoutALevel(): void
    {
        $backend = new FakeBackend($this->invocation);
        Endpoint::create($backend, [Service::new('Greeter')->handler('greet', static fn (Context $ctx): string => 'x')], logger: $this->logger, coreLogLevel: null)
            ->handleRequest(self::SERVER, '');

        self::assertNull($backend->coreLogs);
    }

    public function testCoreLogsAreNotCapturedWithoutALogger(): void
    {
        $backend = new FakeBackend($this->invocation);
        Endpoint::create($backend, [Service::new('Greeter')->handler('greet', static fn (Context $ctx): string => 'x')], coreLogLevel: LogLevel::DEBUG)
            ->handleRequest(self::SERVER, '');

        self::assertNull($backend->coreLogs);
    }

    public function testTheBuilderRejectsACoreLogLevelPsr3DoesNotDefine(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Endpoint::builder()->logger($this->logger, coreLogLevel: 'trace');
    }

    private function endpoint(?\Closure $handler = null, ?LoggerInterface $logger = null): Endpoint
    {
        return Endpoint::create(
            new FakeBackend($this->invocation),
            [Service::new('Greeter')->handler('greet', $handler ?? static fn (Context $ctx, string $name): string => $name)],
            logger: $logger ?? $this->logger,
        );
    }

    private function handle(\Closure $handler): void
    {
        $this->endpoint($handler)->handleRequest(self::SERVER, '');
    }
}
