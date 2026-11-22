<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\Endpoint;
use Restate\EndpointResponse;
use Restate\Internal\Backend;
use Restate\Internal\Backend\Invocation;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\Backend\ResponseHead;
use Restate\Internal\BackendException;
use Restate\Service;
use Restate\TerminalError;
use Restate\Tests\Support\FakeBackend;

/**
 * `Endpoint::handleRequest()` never throws: a failure after the VM exists is reported to it with `notifyError` and the
 * response carries whatever the VM produced; a failure before that (or while reporting) becomes a plain error response.
 */
final class EndpointFailureTest extends TestCase
{
    private const array SERVER = ['REQUEST_URI' => '/invoke/Greeter/greet', 'CONTENT_TYPE' => 'application/vnd.restate.invocation.v5'];

    /** @var list<string> */
    private array $chunks = [];

    public function testInvocationThatIsNotReadyToExecuteIsReportedToTheVm(): void
    {
        $invocation = $this->invocation(ready: false);
        $invocation->expects(self::never())->method('input');

        $this->assertReportedToVm($invocation, 'Restate invocation did not become ready after request-response input closed');
    }

    public function testNotifyInputFailureIsReportedToTheVm(): void
    {
        $invocation = $this->invocation();
        $invocation->method('notifyInput')->willThrowException(new BackendException('input rejected'));

        $this->assertReportedToVm($invocation, 'input rejected');
    }

    public function testInputFailureIsReportedToTheVm(): void
    {
        $invocation = $this->invocation(input: false);
        $invocation->method('input')->willThrowException(new BackendException('no input'));

        $this->assertReportedToVm($invocation, 'no input');
    }

    public function testWriteOutputSuccessFailureIsReportedToTheVm(): void
    {
        $invocation = $this->invocation();
        $invocation->method('writeOutputSuccess')->willThrowException(new BackendException('output rejected'));
        $invocation->expects(self::never())->method('end');

        $this->assertReportedToVm($invocation, 'output rejected');
    }

    public function testWriteOutputFailureFailureIsReportedToTheVm(): void
    {
        $invocation = $this->invocation();
        $invocation->method('writeOutputFailure')->willThrowException(new BackendException('failure rejected'));
        $invocation->expects(self::never())->method('end');

        $this->assertReportedToVm(
            $invocation,
            'failure rejected',
            static fn (Context $ctx, string $_): never => throw new TerminalError('declined', 402),
        );
    }

    public function testEndFailureIsReportedToTheVm(): void
    {
        $invocation = $this->invocation();
        $invocation->expects(self::once())->method('writeOutputSuccess');
        $invocation->method('end')->willThrowException(new BackendException('end rejected'));

        $this->assertReportedToVm($invocation, 'end rejected');
    }

    public function testNotifyErrorFailureBecomesAnInternalServerError(): void
    {
        $invocation = $this->invocation();
        $invocation->expects(self::once())->method('notifyError')->willThrowException(new BackendException('vm is gone'));

        $response = $this->endpoint($invocation, static fn (Context $ctx, string $_): never => throw new \RuntimeException('boom'))
            ->handleRequest(self::SERVER, 'request-body');

        $this->assertErrorResponse(500, 'vm is gone', $response);
    }

    public function testTakeOutputFailureBecomesAnInternalServerError(): void
    {
        $invocation = $this->invocation(takeOutput: false);
        $invocation->method('takeOutput')->willThrowException(new BackendException('output unavailable'));

        $this->assertErrorResponse(500, 'output unavailable', $this->endpoint($invocation)->handleRequest(self::SERVER, 'request-body'));
    }

    public function testResponseHeadFailureBecomesAnInternalServerError(): void
    {
        $invocation = $this->invocation(head: false);
        $invocation->method('responseHead')->willThrowException(new BackendException('no head'));
        $invocation->expects(self::never())->method('notifyInput');

        $this->assertErrorResponse(500, 'no head', $this->endpoint($invocation)->handleRequest(self::SERVER, 'request-body'));
    }

    public function testInvocationConstructionFailureBecomesAnInternalServerError(): void
    {
        $backend = new FakeBackend(new BackendException('(415) Unsupported protocol version'));
        $endpoint = Endpoint::create($backend, [Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => $name)]);

        $this->assertErrorResponse(500, '(415) Unsupported protocol version', $endpoint->handleRequest(self::SERVER, 'request-body'));
    }

    public function testErrorMessageThatIsNotUtf8StillBecomesAnErrorResponse(): void
    {
        $backend = new FakeBackend(new BackendException("bad header value \xB1"));
        $endpoint = Endpoint::create($backend, [Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => $name)]);

        $this->assertErrorResponse(500, "bad header value \u{FFFD}", $endpoint->handleRequest(self::SERVER, 'request-body'));
    }

    /** @param array<string, string> $server */
    #[DataProvider('requestsWithoutContentType')]
    public function testInvocationWithoutContentTypeIsRejectedWith415BeforeCreatingTheVm(array $server): void
    {
        $backend = new FakeBackend();
        $endpoint = Endpoint::create($backend, [Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => $name)]);

        $this->assertErrorResponse(415, 'Missing content-type header', $endpoint->handleRequest($server, 'request-body'));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function requestsWithoutContentType(): iterable
    {
        yield 'no header' => [['REQUEST_URI' => '/invoke/Greeter/greet']];
        yield 'other headers only' => [['REQUEST_URI' => '/invoke/Greeter/greet', 'HTTP_ACCEPT' => 'application/json']];
        // What php-fpm gets from nginx or Caddy for a request without one.
        yield 'empty, from a FastCGI front door' => [['REQUEST_URI' => '/invoke/Greeter/greet', 'CONTENT_TYPE' => '', 'CONTENT_LENGTH' => '']];
    }

    public function testDiscoveryFailureBecomesAnInternalServerError(): void
    {
        $endpoint = Endpoint::create($this->unusedBackend(), [Service::new('Greeter', documentation: "\xB1")]);

        $response = $endpoint->handleRequest(['REQUEST_URI' => '/discover', 'HTTP_ACCEPT' => 'application/vnd.restate.endpointmanifest.v4+json'], '');

        $this->assertErrorResponse(500, 'Malformed UTF-8 characters, possibly incorrectly encoded', $response);
    }

    private function assertReportedToVm(Invocation&MockObject $invocation, ?string $message, ?\Closure $handler = null): void
    {
        $invocation->expects(self::once())
            ->method('notifyError')
            ->with($message === null ? self::isString() : $message)
            ->willReturnCallback(function (): void {
                $this->chunks[] = 'error-frame';
            });

        $response = $this->endpoint($invocation, $handler)->handleRequest(self::SERVER, 'request-body');

        self::assertSame(200, $response->statusCode);
        self::assertSame([['content-type', 'application/vnd.restate.invocation.v5']], $response->headers);
        self::assertStringEndsWith('error-frame', $response->body);
    }

    private function assertErrorResponse(int $status, string $message, EndpointResponse $response): void
    {
        self::assertSame($status, $response->statusCode);
        self::assertSame([['content-type', 'application/json']], $response->headers);
        self::assertSame(['error' => $message], json_decode($response->body, true, flags: JSON_THROW_ON_ERROR));
    }

    private function endpoint(Invocation $invocation, ?\Closure $handler = null): Endpoint
    {
        $backend = new FakeBackend($invocation);

        return Endpoint::create($backend, [
            Service::new('Greeter')->handler('greet', $handler ?? static fn (Context $ctx, string $name): string => "Hello {$name}"),
        ]);
    }

    private function unusedBackend(): Backend
    {
        return new FakeBackend();
    }

    private function invocation(bool $head = true, bool $ready = true, bool $input = true, bool $takeOutput = true): Invocation&MockObject
    {
        $this->chunks = [];
        $invocation = $this->createMock(Invocation::class);
        if ($head) {
            $invocation->method('responseHead')->willReturn(new ResponseHead(200, [['content-type', 'application/vnd.restate.invocation.v5']]));
        }
        $invocation->method('isReadyToExecute')->willReturn($ready);
        if ($input) {
            $invocation->method('input')->willReturn(new InvocationInput('inv-1', 0, '', [], '"Mary"'));
        }
        $invocation->method('writeOutputSuccess')->willReturnCallback(function (string $bytes): void {
            $this->chunks[] = $bytes;
        });
        if ($takeOutput) {
            $invocation->method('takeOutput')->willReturnCallback(function (): ?string {
                return array_shift($this->chunks);
            });
        }

        return $invocation;
    }
}
