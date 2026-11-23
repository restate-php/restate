<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Restate\Attributes as Restate;
use Restate\Context;
use Restate\Endpoint;
use Restate\EndpointBuilder;
use Restate\HandlerDefinition;
use Restate\HandlerExecution;
use Restate\HandlerInterceptor;
use Restate\Internal\Backend\Invocation;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\Backend\ResponseHead;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Payload;
use Restate\Reflection\ServiceReflector;
use Restate\Request;
use Restate\ServiceDefinition;
use Restate\TerminalError;
use Restate\Tests\Support\FakeBackend;
use Restate\ServiceType;
use Restate\HandlerType;

/**
 * Service classes instantiated per invocation attempt, by a factory or a PSR-11 container, and described without an
 * instance.
 */
final class HandlerFactoryTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        FactoryGreeter::$constructed = 0;
    }

    public function testDefinitionComesFromTheClassWithoutAnInstance(): void
    {
        $definition = ServiceReflector::fromClass(FactoryGreeter::class, static fn (): never => throw new \LogicException('The factory must not run to describe the service.'));

        self::assertSame('FactoryGreeter', $definition->name);
        self::assertSame(ServiceType::Service, $definition->type);
        self::assertSame(['greet', 'shout'], array_map(static fn (HandlerDefinition $h): string => $h->name, $definition->handlers));
        $greet = $definition->handlers[0];
        self::assertSame('Greets.', $greet->documentation);
        self::assertEquals(Payload::json(), $greet->inputPayload);
        self::assertSame('string', (string) $greet->inputType);
        self::assertSame('array', (string) $greet->outputType);
        self::assertSame('greet', $greet->signature()->getName());
        self::assertSame(0, FactoryGreeter::$constructed);
    }

    public function testDiscoveryDoesNotInstantiateTheService(): void
    {
        $endpoint = Endpoint::create(new FakeBackend(), [ServiceReflector::fromClass(FactoryGreeter::class, static fn (): FactoryGreeter => new FactoryGreeter('x'))]);

        $response = $endpoint->handleRequest(['REQUEST_URI' => '/discover', 'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v3+json'], '');

        self::assertSame(200, $response->statusCode, $response->body);
        self::assertStringContainsString('"FactoryGreeter"', $response->body);
        self::assertStringContainsString('"shout"', $response->body);
        self::assertSame(0, FactoryGreeter::$constructed);
    }

    public function testFactoryCreatesAnInstanceForEveryInvocationAndGetsItsRequest(): void
    {
        $requests = [];
        $definition = ServiceReflector::fromClass(FactoryGreeter::class, static function (Request $request) use (&$requests): FactoryGreeter {
            $requests[] = $request;

            return new FactoryGreeter($request->header('x-tenant') ?? 'none');
        });
        $endpoint = static fn (Invocation $invocation): Endpoint => Endpoint::create(new FakeBackend($invocation), [$definition]);

        $first = $this->invocation('"Mary"', ['x-tenant' => 'acme']);
        $first->expects(self::once())->method('writeOutputSuccess')->with('{"tenant":"acme","instance":1,"greeting":"Hello Mary"}');
        $endpoint($first)->handleRequest(self::server('/invoke/FactoryGreeter/greet'), '');

        $second = $this->invocation('"Bob"', ['x-tenant' => 'globex']);
        $second->expects(self::once())->method('writeOutputSuccess')->with('{"tenant":"globex","instance":2,"greeting":"Hello Bob"}');
        $endpoint($second)->handleRequest(self::server('/invoke/FactoryGreeter/greet'), '');

        self::assertSame(2, FactoryGreeter::$constructed);
        self::assertCount(2, $requests);
        self::assertSame(['FactoryGreeter', 'greet'], [$requests[0]->target->serviceName(), $requests[0]->target->handlerName()]);
    }

    public function testHandlerWithoutAnInputGetsAnInstanceToo(): void
    {
        $invocation = $this->invocation('', ['x-tenant' => 'acme']);
        $invocation->expects(self::once())->method('writeOutputSuccess')->with('"ACME"');

        Endpoint::create(new FakeBackend($invocation), [ServiceReflector::fromClass(FactoryGreeter::class, static fn (Request $r): FactoryGreeter => new FactoryGreeter($r->header('x-tenant') ?? 'none'))])
            ->handleRequest(self::server('/invoke/FactoryGreeter/shout'), '');
    }

    public function testContainerProvidesTheInstanceForEveryInvocation(): void
    {
        $container = new FactoryTestContainer([FactoryGreeter::class => static fn (): FactoryGreeter => new FactoryGreeter('from-container')]);
        $definition = ServiceReflector::fromClass(FactoryGreeter::class, $container);
        self::assertSame([], $container->gets);

        foreach (['"Mary"', '"Bob"'] as $input) {
            $invocation = $this->invocation($input);
            $invocation->expects(self::once())->method('writeOutputSuccess');
            Endpoint::create(new FakeBackend($invocation), [$definition])->handleRequest(self::server('/invoke/FactoryGreeter/greet'), '');
        }

        self::assertSame([FactoryGreeter::class, FactoryGreeter::class], $container->gets);
        self::assertSame(2, FactoryGreeter::$constructed);
    }

    public function testContainerWithoutTheServiceIsRejectedUpFront(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(FactoryGreeter::class);

        ServiceReflector::fromClass(FactoryGreeter::class, new FactoryTestContainer([]));
    }

    public function testFactoryReturningAnotherObjectFailsTheAttempt(): void
    {
        $invocation = $this->invocation('"Mary"');
        $invocation->expects(self::never())->method('writeOutputSuccess');
        $invocation->expects(self::once())->method('notifyError')->with(self::stringContains(FactoryGreeter::class));

        Endpoint::create(new FakeBackend($invocation), [ServiceReflector::fromClass(FactoryGreeter::class, static fn (): \stdClass => new \stdClass())])
            ->handleRequest(self::server('/invoke/FactoryGreeter/greet'), '');
    }

    public function testInstanceIsCreatedInsideTheInterceptorsAndItsFailureIsMapped(): void
    {
        $record = function (string $event): void {
            $this->events[] = $event;
        };
        $interceptor = new class ($record) implements HandlerInterceptor {
            /** @param \Closure(string): void $record */
            public function __construct(private readonly \Closure $record)
            {
            }

            public function interceptHandler(HandlerExecution $execution, \Closure $next): void
            {
                ($this->record)('before');
                try {
                    $next();
                } finally {
                    ($this->record)('after');
                }
            }
        };
        $definition = ServiceReflector::fromClass(FactoryGreeter::class, static function () use ($record): never {
            $record('factory');

            throw new \OutOfRangeException('no tenant');
        })->withInterceptors($interceptor)->withErrorMapper(static fn (\Throwable $e): ?TerminalError => $e instanceof \OutOfRangeException ? new TerminalError($e->getMessage(), 403) : null);

        $invocation = $this->invocation('"Mary"');
        $invocation->expects(self::once())->method('writeOutputFailure')->with(403, 'no tenant');
        Endpoint::create(new FakeBackend($invocation), [$definition])->handleRequest(self::server('/invoke/FactoryGreeter/greet'), '');

        self::assertSame(['before', 'factory', 'after'], $this->events);
    }

    public function testHandlerKindAndContextTypeComeFromTheMethods(): void
    {
        $definition = ServiceReflector::fromClass(FactoryCounter::class, static fn (): FactoryCounter => new FactoryCounter());

        self::assertSame(ServiceType::VirtualObject, $definition->type);
        self::assertSame(['add' => HandlerType::Exclusive, 'get' => HandlerType::Shared], array_column(array_map(static fn (HandlerDefinition $h): array => [$h->name, $h->type], $definition->handlers), 1, 0));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('declares context Restate\ObjectContext');
        ServiceReflector::fromClass(FactoryWrongContext::class, static fn (): FactoryWrongContext => new FactoryWrongContext());
    }

    public function testClassWithoutAServiceAttributeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ServiceReflector::fromClass(\stdClass::class, static fn (): \stdClass => new \stdClass());
    }

    public function testBuilderRejectsAServiceClassWithoutAFactoryOrContainer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(FactoryGreeter::class);

        new EndpointBuilder()->bind(FactoryGreeter::class)->build();
    }

    public function testBuilderRejectsAFactoryForAnInstance(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EndpointBuilder()->bind(new FactoryGreeter('x'), static fn (): FactoryGreeter => new FactoryGreeter('y'));
    }

    /** @return array<string, mixed> */
    private static function server(string $uri): array
    {
        return ['REQUEST_URI' => $uri, 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6'];
    }

    /** @param array<string, string> $headers */
    private function invocation(string $input, array $headers = []): Invocation&MockObject
    {
        $invocation = $this->createMock(Invocation::class);
        $invocation->method('responseHead')->willReturn(new ResponseHead(200, []));
        $invocation->method('isReadyToExecute')->willReturn(true);
        $pairs = [];
        foreach ($headers as $name => $value) {
            $pairs[] = [$name, $value];
        }
        $invocation->method('input')->willReturn(new InvocationInput('inv-1', 0, '', $pairs, $input));
        $invocation->method('takeOutput')->willReturn(null);

        return $invocation;
    }
}

#[Restate\Service('FactoryGreeter')]
final class FactoryGreeter
{
    public static int $constructed = 0;

    private readonly int $instance;

    public function __construct(private readonly string $tenant)
    {
        $this->instance = ++self::$constructed;
    }

    /** @return array{tenant: string, instance: int, greeting: string} */
    #[Restate\Handler(documentation: 'Greets.')]
    public function greet(Context $ctx, string $name): array
    {
        return ['tenant' => $this->tenant, 'instance' => $this->instance, 'greeting' => "Hello {$name}"];
    }

    #[Restate\Handler]
    public function shout(Context $ctx): string
    {
        return strtoupper($this->tenant);
    }

    public function notAHandler(): void
    {
    }
}

#[Restate\VirtualObject('FactoryCounter')]
final class FactoryCounter
{
    #[Restate\Handler]
    public function add(ObjectContext $ctx, int $value): void
    {
    }

    #[Restate\Handler]
    #[Restate\Shared]
    public function get(ObjectSharedContext $ctx): int
    {
        return 0;
    }
}

#[Restate\VirtualObject('FactoryWrongContext')]
final class FactoryWrongContext
{
    #[Restate\Handler]
    #[Restate\Shared]
    public function get(ObjectContext $ctx): int
    {
        return 0;
    }
}

/** A minimal PSR-11 container that records get() calls. */
final class FactoryTestContainer implements ContainerInterface
{
    /** @var list<string> */
    public array $gets = [];

    /** @param array<string, \Closure(): object> $factories */
    public function __construct(private readonly array $factories)
    {
    }

    public function get(string $id): object
    {
        $this->gets[] = $id;
        $factory = $this->factories[$id] ?? throw new class ("No entry {$id}") extends \RuntimeException implements NotFoundExceptionInterface {};

        return $factory();
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }
}
