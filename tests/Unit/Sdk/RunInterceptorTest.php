<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\ErrorMapper;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\ClosureErrorMapper;
use Restate\Internal\ExecutionHooks;
use Restate\Internal\SuspensionException;
use Restate\RetryableError;
use Restate\RunExecution;
use Restate\RunInterceptor;
use Restate\RunRetryPolicy;
use Restate\Suspension;
use Restate\TerminalError;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;

/**
 * Run interceptors and the error mapper around `Context::run()` callbacks.
 */
final class RunInterceptorTest extends TestCase
{
    private const int RUN = 1;

    /** @var list<string> */
    private array $events = [];

    public function testInterceptorsWrapAnExecutedRunOutermostFirst(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = $this->context($invocation, [$this->recorder('a'), $this->recorder('b')]);

        $result = $ctx->run('charge', function (): string {
            $this->events[] = 'callback';

            return 'charged';
        });

        self::assertSame('charged', $result);
        self::assertSame(['a before charge', 'b before charge', 'callback', 'b after charge', 'a after charge'], $this->events);
        self::assertEquals([[self::RUN, '"charged"', RunRetryPolicy::infinite()]], array_map(static fn (array $call): array => [$call[0], $call[1], $call[2]], $invocation->calls('proposeRunCompletionSuccess')));
    }

    public function testInterceptorSeesTheRequestAndTheRunName(): void
    {
        $seen = [];
        $ctx = $this->context(ScriptedInvocation::new(), [$this->interceptor(static function (\Closure $next, RunExecution $run) use (&$seen): void {
            $seen[] = [$run->name, $run->request->id, $run->request->target->serviceName()];
            $next();
        })]);

        $ctx->run('charge', static fn (): int => 1);

        self::assertSame([['charge', 'inv_test', 'TestService']], $seen);
    }

    public function testInterceptorIsNotCalledWhenTheRunIsReplayed(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysRun', NotificationResult::success('"from journal"'));
        $ctx = $this->context($invocation, [$this->recorder('a')]);

        $result = $ctx->run('charge', static fn (): never => throw new \LogicException('The callback must not run on replay.'));

        self::assertSame('from journal', $result);
        self::assertSame([], $this->events);
    }

    public function testNowIsTheRunDateNow(): void
    {
        $ctx = $this->context(ScriptedInvocation::new(), [$this->recorder('a')]);

        $ctx->now();

        self::assertSame(['a before date.now', 'a after date.now'], $this->events);
    }

    public function testInterceptorCannotSwallowACallbackFailure(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = $this->context($invocation, [$this->interceptor(static function (\Closure $next): void {
            try {
                $next();
            } catch (\Throwable) {
            }
        })]);

        try {
            $ctx->run('charge', static fn (): never => throw new \RuntimeException('card declined'), RunRetryPolicy::fixedDelay(100));
            self::fail('Expected the scheduled retry to suspend the attempt.');
        } catch (SuspensionException) {
        }

        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame(['card declined'], array_map(static fn (array $call): mixed => $call[1], $invocation->calls('proposeRunCompletionRetryable')));
    }

    public function testInterceptorMayFailTheRunTerminally(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = $this->context($invocation, [$this->interceptor(static function (\Closure $next): void {
            try {
                $next();
            } catch (\RuntimeException $e) {
                throw new TerminalError('declined', 402, previous: $e);
            }
        })]);

        try {
            $ctx->run('charge', static fn (): never => throw new \RuntimeException('card declined'));
            self::fail('Expected a terminal failure.');
        } catch (TerminalError $e) {
            self::assertSame(402, $e->statusCode());
        }

        self::assertSame([[self::RUN, 402, 'declined']], array_map(static fn (array $call): array => [$call[0], $call[1], $call[2]], $invocation->calls('proposeRunCompletionFailure')));
    }

    public function testInterceptorThatDoesNotCallNextFailsTheRunAttempt(): void
    {
        $invocation = ScriptedInvocation::new();
        $ran = false;
        $ctx = $this->context($invocation, [$this->interceptor(static function (\Closure $next): void {
        })]);

        try {
            $ctx->run('charge', static function () use (&$ran): int {
                $ran = true;

                return 1;
            });
            self::fail('Expected the scheduled retry to suspend the attempt.');
        } catch (SuspensionException) {
        }

        self::assertFalse($ran);
        self::assertStringContainsString('returned without calling $next', (string) $invocation->calls('proposeRunCompletionRetryable')[0][1]);
    }

    public function testSuspensionInsideTheCallbackPassesThroughAnInterceptorThatCatchesThrowable(): void
    {
        $invocation = ScriptedInvocation::new();
        $invocation->onNext('sysSleep', AwaitState::Suspended);
        $ctx = $this->context($invocation, [$this->interceptor(function (\Closure $next): void {
            try {
                $next();
            } catch (\Throwable $e) {
                $this->events[] = Suspension::is($e) ? 'suspension' : 'failure';

                throw new \RuntimeException('replaced');
            }
        })]);

        try {
            $ctx->run('nap', static function () use ($ctx): int {
                $ctx->sleep(1000);

                return 1;
            });
            self::fail('Expected the suspension.');
        } catch (SuspensionException) {
        }

        self::assertSame(['suspension'], $this->events);
        self::assertSame([], $invocation->calls('proposeRunCompletionSuccess'));
        self::assertSame([], $invocation->calls('proposeRunCompletionRetryable'));
        self::assertSame([], $invocation->calls('proposeRunCompletionFailure'));
    }

    public function testErrorMapperFailsTheRunTerminally(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = $this->context($invocation, errorMapper: static fn (\Throwable $e): ?TerminalError => $e instanceof \DomainException ? new TerminalError($e->getMessage(), 422, ['account' => 'a-1'], $e) : null);

        try {
            $ctx->run('charge', static fn (): never => throw new \DomainException('insufficient funds'));
            self::fail('Expected a terminal failure.');
        } catch (TerminalError $e) {
            self::assertSame([422, 'insufficient funds', ['account' => 'a-1']], [$e->statusCode(), $e->getMessage(), $e->metadata()]);
        }

        self::assertEquals([[self::RUN, 422, 'insufficient funds', RunRetryPolicy::infinite(), ['account' => 'a-1']]], $invocation->calls('proposeRunCompletionFailure'));
        self::assertSame([], $invocation->calls('proposeRunCompletionRetryable'));
    }

    public function testErrorMapperMayClassifyTheRunFailureAsRetryable(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = $this->context($invocation, errorMapper: static fn (\Throwable $e): RetryableError => new RetryableError('slow down', 250, $e, 503, ['provider' => 'acme']));

        try {
            $ctx->run('charge', static fn (): never => throw new \OverflowException('rate limited'), RunRetryPolicy::fixedDelay(100));
            self::fail('Expected the scheduled retry to suspend the attempt.');
        } catch (SuspensionException) {
        }

        $call = $invocation->calls('proposeRunCompletionRetryable')[0];
        self::assertSame([self::RUN, 'slow down', 250, 503, ['provider' => 'acme']], [$call[0], $call[1], $call[4], $call[5], $call[6]]);
    }

    public function testErrorMapperReturningNullKeepsTheDefault(): void
    {
        $invocation = ScriptedInvocation::new();
        $ctx = $this->context($invocation, errorMapper: static fn (\Throwable $e): null => null);

        try {
            $ctx->run('charge', static fn (): never => throw new \RuntimeException('boom'), RunRetryPolicy::fixedDelay(100));
            self::fail('Expected the scheduled retry to suspend the attempt.');
        } catch (SuspensionException) {
        }

        $call = $invocation->calls('proposeRunCompletionRetryable')[0];
        self::assertSame([self::RUN, 'boom', null, 500, []], [$call[0], $call[1], $call[4], $call[5], $call[6]]);
    }

    public function testErrorMapperDoesNotSeeRetryableOrTerminalErrors(): void
    {
        $mapped = [];
        $invocation = ScriptedInvocation::new();
        $ctx = $this->context($invocation, errorMapper: static function (\Throwable $e) use (&$mapped): TerminalError {
            $mapped[] = $e;

            return new TerminalError('mapped', 400);
        });

        try {
            $ctx->run('a', static fn (): never => throw new RetryableError('later'), RunRetryPolicy::fixedDelay(100));
        } catch (SuspensionException) {
        }
        try {
            $ctx->run('b', static fn (): never => throw new TerminalError('no', 409));
        } catch (TerminalError $e) {
            self::assertSame(409, $e->statusCode());
        }

        self::assertSame([], $mapped);
    }

    /**
     * @param list<RunInterceptor> $interceptors
     * @param ErrorMapper|\Closure(\Throwable): (TerminalError|RetryableError|null)|null $errorMapper
     */
    private function context(ScriptedInvocation $invocation, array $interceptors = [], ErrorMapper|\Closure|null $errorMapper = null): Context
    {
        return Contexts::service($invocation, hooks: ExecutionHooks::of($interceptors, $errorMapper === null ? null : ClosureErrorMapper::of($errorMapper)));
    }

    private function recorder(string $name): RunInterceptor
    {
        return $this->interceptor(function (\Closure $next, RunExecution $run) use ($name): void {
            $this->events[] = "{$name} before {$run->name}";
            $next();
            $this->events[] = "{$name} after {$run->name}";
        });
    }

    /** @param \Closure(\Closure(): void, RunExecution): void $intercept */
    private function interceptor(\Closure $intercept): RunInterceptor
    {
        return new class ($intercept) implements RunInterceptor {
            /** @param \Closure(\Closure(): void, RunExecution): void $intercept */
            public function __construct(private readonly \Closure $intercept)
            {
            }

            public function interceptRun(RunExecution $execution, \Closure $next): void
            {
                ($this->intercept)($next, $execution);
            }
        };
    }
}
