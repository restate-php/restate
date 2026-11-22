<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\Endpoint;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\Invocation;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\Backend\ResponseHead;
use Restate\Internal\BackendException;
use Restate\Service;
use Restate\TerminalError;
use Restate\Tests\Support\FakeBackend;

/**
 * `Endpoint::handleRequest()` releases the invocation (and so its extension VM) before it returns, whatever happened,
 * instead of leaving it to PHP's destructors: a long-lived worker frees it promptly.
 */
final class EndpointInvocationLifetimeTest extends TestCase
{
    private const array SERVER = ['REQUEST_URI' => '/invoke/Greeter/greet', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v5'];

    /** @var list<string> */
    private array $calls = [];

    /**
     * @return iterable<string, array{\Closure(Context, string): mixed, \Closure(Invocation&MockObject): void, int}>
     */
    public static function outcomes(): iterable
    {
        $none = static function (Invocation&MockObject $invocation): void {
        };

        yield 'success' => [static fn (Context $ctx, string $name): string => "Hello {$name}", $none, 200];
        yield 'terminal failure' => [static fn (Context $ctx, string $_): never => throw new TerminalError('declined', 402), $none, 200];
        yield 'retryable exception' => [static fn (Context $ctx, string $_): never => throw new \RuntimeException('boom'), $none, 200];
        yield 'suspension' => [
            static function (Context $ctx, string $_): string {
                $ctx->sleep(1000);

                return 'unreachable';
            },
            static function (Invocation&MockObject $invocation): void {
                $invocation->method('sysSleep')->willReturn(1);
                $invocation->method('doAwait')->willReturn(AwaitResult::suspended());
            },
            200,
        ];
        yield 'failure while reporting an error' => [
            static fn (Context $ctx, string $_): never => throw new \RuntimeException('boom'),
            static function (Invocation&MockObject $invocation): void {
                $invocation->method('notifyError')->willThrowException(new BackendException('vm is gone'));
            },
            500,
        ];
        yield 'failure reading the response head' => [
            static fn (Context $ctx, string $name): string => $name,
            static function (Invocation&MockObject $invocation): void {
                $invocation->method('responseHead')->willThrowException(new BackendException('no head'));
            },
            500,
        ];
    }

    /**
     * @param \Closure(Context, string): mixed $handler
     * @param \Closure(Invocation&MockObject): void $configure
     */
    #[DataProvider('outcomes')]
    public function testInvocationIsFreedOnceAfterTheResponseIsBuilt(\Closure $handler, \Closure $configure, int $status): void
    {
        $invocation = $this->invocation($configure);
        $invocation->expects(self::once())->method('free')->willReturnCallback(function (): void {
            $this->calls[] = 'free';
        });

        $response = $this->endpoint($invocation, $handler)->handleRequest(self::SERVER, 'request-body');

        self::assertSame($status, $response->statusCode, $response->body);
        self::assertSame(['free'], array_slice($this->calls, -1), 'free() is the last call on the invocation');
    }

    public function testInvocationIsFreedWhenDrainingTheOutputFails(): void
    {
        $invocation = $this->invocation(static function (Invocation&MockObject $invocation): void {
        }, takeOutput: false);
        $invocation->method('takeOutput')->willThrowException(new BackendException('output unavailable'));
        $invocation->expects(self::once())->method('free');

        $response = $this->endpoint($invocation, static fn (Context $ctx, string $name): string => $name)->handleRequest(self::SERVER, 'request-body');

        self::assertSame(500, $response->statusCode);
    }

    /** @param \Closure(Invocation&MockObject): void $configure */
    private function invocation(\Closure $configure, bool $takeOutput = true): Invocation&MockObject
    {
        $invocation = $this->createMock(Invocation::class);
        $configure($invocation);
        $invocation->method('responseHead')->willReturn(new ResponseHead(200, []));
        $invocation->method('isReadyToExecute')->willReturn(true);
        $invocation->method('input')->willReturn(new InvocationInput('inv-1', 0, '', [], '"Mary"'));
        if ($takeOutput) {
            $invocation->method('takeOutput')->willReturnCallback(function (): ?string {
                $this->calls[] = 'takeOutput';

                return null;
            });
        }

        return $invocation;
    }

    /** @param \Closure(Context, string): mixed $handler */
    private function endpoint(Invocation $invocation, \Closure $handler): Endpoint
    {
        $backend = new FakeBackend($invocation);

        return Endpoint::create($backend, [Service::new('Greeter')->handler('greet', $handler)]);
    }
}
