<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\Endpoint;
use Restate\ErrorMapper;
use Restate\HandlerExecution;
use Restate\HandlerInterceptor;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\Invocation;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\Backend\ResponseHead;
use Restate\Internal\ReplayStateProvider;
use Restate\Internal\SuspensionException;
use Restate\ObjectContext;
use Restate\Reflection\ServiceReflector;
use Restate\RetryableError;
use Restate\RunExecution;
use Restate\RunInterceptor;
use Restate\Service;
use Restate\ServiceDefinition;
use Restate\Suspension;
use Restate\TerminalError;
use Restate\Tests\Support\FakeBackend;
use Restate\VirtualObject;
use Restate\Workflow;
use Restate\WorkflowContext;

/**
 * Handler interceptors and error mappers as an endpoint runs them (run interceptors: {@see RunInterceptorTest}).
 */
final class RuntimeExecutionHooksTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];

    public function testInterceptorsRunEndpointFirstThenServiceInRegistrationOrder(): void
    {
        $invocation = $this->invocation();
        $invocation->expects(self::once())->method('writeOutputSuccess')->with('"Hello Mary"');

        $service = Service::new('Greeter')
            ->interceptor($this->recorder('service-1'), $this->recorder('service-2'))
            ->handler('greet', function (Context $ctx, string $name): string {
                $this->events[] = 'handler';

                return "Hello {$name}";
            });

        $this->invoke($invocation, $service, '/invoke/Greeter/greet', interceptors: [$this->recorder('endpoint-1'), $this->recorder('endpoint-2')]);

        self::assertSame([
            'endpoint-1 before', 'endpoint-2 before', 'service-1 before', 'service-2 before',
            'handler',
            'service-2 after', 'service-1 after', 'endpoint-2 after', 'endpoint-1 after',
        ], $this->events);
    }

    public function testInterceptorSeesTheRequestAndTheReplayState(): void
    {
        $invocation = self::createStubForIntersectionOfInterfaces([Invocation::class, ReplayStateProvider::class]);
        $invocation->method('responseHead')->willReturn(new ResponseHead(200, []));
        $invocation->method('isReadyToExecute')->willReturn(true);
        $invocation->method('input')->willReturn(new InvocationInput('inv-1', 0, 'alice', [], '"Mary"'));
        $invocation->method('takeOutput')->willReturn(null);
        $invocation->method('isReplaying')->willReturnOnConsecutiveCalls(true, false);
        $seen = [];
        $interceptor = $this->interceptor(static function (\Closure $next, HandlerExecution $execution) use (&$seen): void {
            $request = $execution->request;
            $seen[] = [$request->id, $request->target->serviceName(), $request->target->handlerName(), $request->target->key(), $request->body, $request->attemptHeaders['x-attempt'] ?? null];
            $seen[] = $execution->isReplaying();
            $next();
            $seen[] = $execution->isReplaying();
        });

        $this->invoke($invocation, VirtualObject::new('Counter')->handler('get', static fn (ObjectContext $ctx, mixed $_): int => 1), '/invoke/Counter/alice/get', interceptors: [$interceptor], headers: ['HTTP_X_ATTEMPT' => 'one']);

        self::assertSame([['inv-1', 'Counter', 'get', 'alice', '"Mary"', 'one'], true, false], $seen);
    }

    public function testInterceptorCannotSwallowAHandlerFailure(): void
    {
        $invocation = $this->invocation();
        $invocation->expects(self::never())->method('writeOutputSuccess');
        $invocation->expects(self::once())->method('notifyError')->with('database down');

        $swallow = $this->interceptor(static function (\Closure $next): void {
            try {
                $next();
            } catch (\Throwable) {
            }
        });

        $this->invoke($invocation, $this->failing(new \RuntimeException('database down')), '/invoke/Failing/fail', interceptors: [$swallow]);
    }

    public function testInterceptorMayReplaceAHandlerFailure(): void
    {
        $invocation = $this->invocation();
        $invocation->expects(self::never())->method('notifyError');
        $invocation->expects(self::once())->method('writeOutputFailure')->with(422, 'rejected', ['cause' => 'database down']);

        $replace = $this->interceptor(static function (\Closure $next): void {
            try {
                $next();
            } catch (\RuntimeException $e) {
                throw new TerminalError('rejected', 422, ['cause' => $e->getMessage()]);
            }
        });

        $this->invoke($invocation, $this->failing(new \RuntimeException('database down')), '/invoke/Failing/fail', interceptors: [$replace]);
    }

    public function testInterceptorThatThrowsAfterASuccessfulHandlerFailsTheAttempt(): void
    {
        $invocation = $this->invocation();
        $invocation->expects(self::never())->method('writeOutputSuccess');
        $invocation->expects(self::once())->method('notifyError')->with('after');

        $this->invoke($invocation, $this->greeter(), '/invoke/Greeter/greet', interceptors: [$this->interceptor(static function (\Closure $next): void {
            $next();

            throw new \RuntimeException('after');
        })]);
    }

    public function testInterceptorThatDoesNotCallNextFailsTheAttemptWithoutRunningTheHandler(): void
    {
        $invocation = $this->invocation();
        $invocation->expects(self::never())->method('writeOutputSuccess');
        $invocation->expects(self::once())->method('notifyError')->with(self::stringContains('returned without calling $next'));

        $this->invoke($invocation, $this->greeter(), '/invoke/Greeter/greet', interceptors: [$this->interceptor(static function (\Closure $next): void {
        })]);

        self::assertNotContains('handler', $this->events);
    }

    public function testInterceptorThatCallsNextTwiceFailsTheAttemptAndRunsTheHandlerOnce(): void
    {
        $invocation = $this->invocation();
        $invocation->expects(self::never())->method('writeOutputSuccess');
        $invocation->expects(self::once())->method('notifyError')->with(self::stringContains('called $next more than once'));

        $this->invoke($invocation, $this->greeter(), '/invoke/Greeter/greet', interceptors: [$this->interceptor(static function (\Closure $next): void {
            $next();
            $next();
        })]);

        self::assertSame(['handler'], $this->events);
    }

    public function testInterceptorThatCatchesThrowableCannotSwallowASuspension(): void
    {
        $invocation = $this->suspendingInvocation();
        $invocation->expects(self::never())->method('writeOutputSuccess');
        $invocation->expects(self::never())->method('writeOutputFailure');
        $invocation->expects(self::never())->method('notifyError');
        $invocation->expects(self::never())->method('end');

        $swallow = $this->interceptor(function (\Closure $next): void {
            try {
                $next();
            } catch (\Throwable $e) {
                $this->events[] = Suspension::is($e) ? 'suspension' : 'failure';
            }
        });

        $this->invoke($invocation, $this->sleeper(), '/invoke/Sleeper/nap', interceptors: [$swallow]);

        self::assertSame(['suspension'], $this->events);
    }

    public function testInterceptorThatReplacesASuspensionWithAnErrorDoesNotChangeTheOutcome(): void
    {
        $invocation = $this->suspendingInvocation();
        $invocation->expects(self::never())->method('writeOutputFailure');
        $invocation->expects(self::never())->method('notifyError');
        $invocation->expects(self::never())->method('end');

        $replace = $this->interceptor(static function (\Closure $next): void {
            try {
                $next();
            } catch (\Throwable) {
                throw new TerminalError('not suspended', 400);
            }
        });
        $observe = $this->interceptor(function (\Closure $next): void {
            try {
                $next();
            } catch (\Throwable $e) {
                $this->events[] = $e::class;

                throw $e;
            }
        });

        $this->invoke($invocation, $this->sleeper(), '/invoke/Sleeper/nap', interceptors: [$observe, $replace]);

        self::assertSame([SuspensionException::class], $this->events);
    }

    public function testInterceptorAroundAHandlerThatSwallowedTheSuspensionStillEndsAsASuspension(): void
    {
        $invocation = $this->suspendingInvocation();
        $invocation->expects(self::never())->method('writeOutputSuccess');
        $invocation->expects(self::never())->method('notifyError');

        $observe = $this->interceptor(function (\Closure $next): void {
            try {
                $next();
                $this->events[] = 'returned';
            } catch (\Throwable $e) {
                $this->events[] = Suspension::is($e) ? 'suspension' : 'failure';

                throw $e;
            }
        });

        $this->invoke($invocation, Service::new('Sleeper')->handler('nap', static function (Context $ctx, mixed $_): string {
            try {
                $ctx->sleep(1000);
            } catch (\Throwable) {
            }

            return 'swallowed';
        }), '/invoke/Sleeper/nap', interceptors: [$observe]);

        self::assertSame(['suspension'], $this->events);
    }

    public function testErrorMapperTurnsADomainExceptionIntoATerminalError(): void
    {
        $invocation = $this->invocation();
        $invocation->expects(self::never())->method('notifyError');
        $invocation->expects(self::once())->method('writeOutputFailure')->with(422, 'insufficient funds', ['account' => 'a-1']);
        $invocation->expects(self::once())->method('end');

        $this->invoke($invocation, $this->failing(new \DomainException('insufficient funds')), '/invoke/Failing/fail', errorMapper: static fn (\Throwable $e): ?TerminalError => $e instanceof \DomainException ? new TerminalError($e->getMessage(), 422, ['account' => 'a-1'], $e) : null);
    }

    public function testErrorMapperMayAskForARetryAfterADelay(): void
    {
        $invocation = $this->invocation();
        $invocation->expects(self::never())->method('writeOutputFailure');
        $invocation->expects(self::once())->method('notifyError')->with('slow down', self::anything(), 2000);

        $this->invoke($invocation, $this->failing(new \OverflowException('rate limited')), '/invoke/Failing/fail', errorMapper: static fn (\Throwable $e): RetryableError => new RetryableError('slow down', 2000, $e));
    }

    public function testErrorMapperReturningNullKeepsTheDefault(): void
    {
        $invocation = $this->invocation();
        $invocation->expects(self::never())->method('writeOutputFailure');
        $invocation->expects(self::once())->method('notifyError')->with('database down', self::anything(), null);

        $this->invoke($invocation, $this->failing(new \RuntimeException('database down')), '/invoke/Failing/fail', errorMapper: static fn (\Throwable $e): null => null);
    }

    public function testErrorMapperDoesNotSeeTerminalOrRetryableErrors(): void
    {
        $mapped = [];
        $mapper = static function (\Throwable $e) use (&$mapped): TerminalError {
            $mapped[] = $e;

            return new TerminalError('mapped', 400);
        };

        $terminal = $this->invocation();
        $terminal->expects(self::once())->method('writeOutputFailure')->with(409, 'conflict');
        $this->invoke($terminal, $this->failing(new TerminalError('conflict', 409)), '/invoke/Failing/fail', errorMapper: $mapper);

        $retryable = $this->invocation();
        $retryable->expects(self::once())->method('notifyError')->with('later', self::anything(), 10);
        $this->invoke($retryable, $this->failing(new RetryableError('later', 10)), '/invoke/Failing/fail', errorMapper: $mapper);

        self::assertSame([], $mapped);
    }

    public function testErrorMapperIsNotAskedAboutASuspension(): void
    {
        $invocation = $this->suspendingInvocation();
        $invocation->expects(self::never())->method('writeOutputFailure');
        $invocation->expects(self::never())->method('notifyError');

        $this->invoke($invocation, $this->sleeper(), '/invoke/Sleeper/nap', errorMapper: static fn (\Throwable $e): never => throw new \LogicException('The mapper saw ' . $e::class));
    }

    public function testErrorMapperSeesWhatTheInterceptorsThrow(): void
    {
        $invocation = $this->invocation();
        $invocation->expects(self::once())->method('writeOutputFailure')->with(400, 'from interceptor');

        $observe = $this->interceptor(function (\Closure $next): void {
            try {
                $next();
            } catch (\Throwable $e) {
                $this->events[] = $e::class;

                throw new \InvalidArgumentException('from interceptor', previous: $e);
            }
        });

        $this->invoke(
            $invocation,
            $this->failing(new \DomainException('from handler')),
            '/invoke/Failing/fail',
            interceptors: [$observe],
            errorMapper: static fn (\Throwable $e): TerminalError => new TerminalError($e->getMessage(), 400),
        );

        self::assertSame([\DomainException::class], $this->events);
    }

    public function testServiceErrorMapperIsAskedBeforeTheEndpointErrorMapper(): void
    {
        $service = Service::new('Failing')
            ->errorMapper(static fn (\Throwable $e): ?TerminalError => $e instanceof \DomainException ? new TerminalError('service', 422) : null)
            ->handler('domain', static fn (Context $ctx, mixed $_): never => throw new \DomainException('domain'))
            ->handler('other', static fn (Context $ctx, mixed $_): never => throw new \RuntimeException('other'));
        $endpointMapper = static fn (\Throwable $e): TerminalError => new TerminalError('endpoint', 400);

        $domain = $this->invocation();
        $domain->expects(self::once())->method('writeOutputFailure')->with(422, 'service');
        $this->invoke($domain, $service, '/invoke/Failing/domain', errorMapper: $endpointMapper);

        $other = $this->invocation();
        $other->expects(self::once())->method('writeOutputFailure')->with(400, 'endpoint');
        $this->invoke($other, $service, '/invoke/Failing/other', errorMapper: $endpointMapper);
    }

    public function testErrorMapperObjectIsAccepted(): void
    {
        $invocation = $this->invocation();
        $invocation->expects(self::once())->method('writeOutputFailure')->with(404, 'missing');

        $mapper = new class () implements ErrorMapper {
            public function map(\Throwable $error): ?TerminalError
            {
                return $error instanceof \OutOfBoundsException ? new TerminalError('missing', 404) : null;
            }
        };

        $this->invoke($invocation, $this->failing(new \OutOfBoundsException('nope'))->errorMapper($mapper), '/invoke/Failing/fail');
    }

    public function testVirtualObjectsWorkflowsAndReflectedServicesTakeHooks(): void
    {
        $object = VirtualObject::new('Counter')
            ->interceptor($this->recorder('object'))
            ->errorMapper(static fn (\Throwable $e): TerminalError => new TerminalError('object', 400))
            ->handler('add', static fn (ObjectContext $ctx, mixed $_): never => throw new \RuntimeException('x'));
        $invocation = $this->invocation();
        $invocation->expects(self::once())->method('writeOutputFailure')->with(400, 'object');
        $this->invoke($invocation, $object, '/invoke/Counter/alice/add');

        $workflow = Workflow::new('Checkout')
            ->interceptor($this->recorder('workflow'))
            ->errorMapper(static fn (\Throwable $e): TerminalError => new TerminalError('workflow', 400))
            ->run(static fn (WorkflowContext $ctx, mixed $_): never => throw new \RuntimeException('x'));
        $invocation = $this->invocation();
        $invocation->expects(self::once())->method('writeOutputFailure')->with(400, 'workflow');
        $this->invoke($invocation, $workflow, '/invoke/Checkout/alice/run');

        $reflected = ServiceReflector::fromObject(new HookedGreeter())
            ->withInterceptors($this->recorder('reflected'))
            ->withErrorMapper(static fn (\Throwable $e): TerminalError => new TerminalError('reflected', 400));
        $invocation = $this->invocation();
        $invocation->expects(self::once())->method('writeOutputFailure')->with(400, 'reflected');
        $this->invoke($invocation, $reflected, '/invoke/HookedGreeter/greet');

        self::assertSame(['object before', 'workflow before', 'reflected before'], $this->events);
    }

    public function testServiceDefinitionRejectsAnInterceptorOfAnotherType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore argument.type (deliberately not an interceptor) */
        new ServiceDefinition('Greeter', [], interceptors: [new \stdClass()]);
    }

    public function testSuspensionRecognisesTheSuspensionAndWhatWrapsIt(): void
    {
        $suspension = new SuspensionException('Sleep suspended.');

        self::assertTrue(Suspension::is($suspension));
        self::assertTrue(Suspension::is(new \RuntimeException('wrapped', previous: new \LogicException('inner', previous: $suspension))));
        self::assertFalse(Suspension::is(new \RuntimeException('failure')));
        self::assertFalse(Suspension::is(new \Error('error')));
    }

    /**
     * @param list<HandlerInterceptor|RunInterceptor> $interceptors
     * @param array<string, string> $headers
     * @param ErrorMapper|\Closure(\Throwable): (TerminalError|RetryableError|null)|null $errorMapper
     */
    private function invoke(Invocation $invocation, Service|VirtualObject|Workflow|ServiceDefinition $service, string $uri, array $interceptors = [], ErrorMapper|\Closure|null $errorMapper = null, array $headers = []): void
    {
        Endpoint::create(new FakeBackend($invocation), [$service], interceptors: $interceptors, errorMapper: $errorMapper)
            ->handleRequest(['REQUEST_URI' => $uri, 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6', ...$headers], '');
    }

    private function recorder(string $name): HandlerInterceptor
    {
        return $this->interceptor(function (\Closure $next) use ($name): void {
            $this->events[] = "{$name} before";
            $next();
            $this->events[] = "{$name} after";
        });
    }

    /** @param \Closure(\Closure(): void, HandlerExecution): void $intercept */
    private function interceptor(\Closure $intercept): HandlerInterceptor
    {
        return new class ($intercept) implements HandlerInterceptor {
            /** @param \Closure(\Closure(): void, HandlerExecution): void $intercept */
            public function __construct(private readonly \Closure $intercept)
            {
            }

            public function interceptHandler(HandlerExecution $execution, \Closure $next): void
            {
                ($this->intercept)($next, $execution);
            }
        };
    }

    private function greeter(): Service
    {
        return Service::new('Greeter')->handler('greet', function (Context $ctx, string $name): string {
            $this->events[] = 'handler';

            return "Hello {$name}";
        });
    }

    private function sleeper(): Service
    {
        return Service::new('Sleeper')->handler('nap', static function (Context $ctx, mixed $_): string {
            $ctx->sleep(1000);

            return 'slept';
        });
    }

    private function failing(\Throwable $error): Service
    {
        return Service::new('Failing')->handler('fail', static fn (Context $ctx, mixed $_): never => throw $error);
    }

    /** An invocation whose every await reports that the invocation suspended. */
    private function suspendingInvocation(): Invocation&MockObject
    {
        $invocation = $this->invocation();
        $invocation->method('doAwait')->willReturn(AwaitResult::suspended());

        return $invocation;
    }

    private function invocation(): Invocation&MockObject
    {
        $invocation = $this->createMock(Invocation::class);
        $invocation->method('responseHead')->willReturn(new ResponseHead(200, []));
        $invocation->method('isReadyToExecute')->willReturn(true);
        $invocation->method('input')->willReturn(new InvocationInput('inv-1', 0, 'alice', [], '"Mary"'));
        $invocation->method('takeOutput')->willReturn(null);

        return $invocation;
    }
}

#[\Restate\Attributes\Service('HookedGreeter')]
final class HookedGreeter
{
    #[\Restate\Attributes\Handler]
    public function greet(Context $ctx, string $name): never
    {
        throw new \RuntimeException("no greeting for {$name}");
    }
}
