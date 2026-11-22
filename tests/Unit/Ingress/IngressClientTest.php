<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Ingress;

use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Restate\Ingress\IngressClient;
use Restate\Ingress\IngressException;
use Restate\Ingress\Output;
use Restate\Ingress\SendStatus;
use Restate\Serde;
use Restate\Target;
use Restate\TerminalError;

final class IngressClientTest extends TestCase
{
    private RecordingHttpClient $http;

    #[\Override]
    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
    }

    public function testCallsAServiceHandlerWithAJsonPayloadAndDecodesTheResult(): void
    {
        $this->http->respondJson(200, '"Hello Mary"');

        $result = $this->client()->serviceClient('Greeter')->request('greet', 'Mary')->call();

        self::assertSame('Hello Mary', $result);
        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('http://restate:8080/Greeter/greet', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('content-type'));
        self::assertSame('"Mary"', (string) $request->getBody());
    }

    public function testCallsAnObjectHandlerWithTheKeyEncodedAsOnePathSegment(): void
    {
        $this->http->respondJson(200, '{"oldValue":0,"newValue":1}');

        $result = $this->client()->objectClient('Counter', 'a/b c?')->request('add', 1)->call();

        self::assertSame(['oldValue' => 0, 'newValue' => 1], $result);
        self::assertSame('http://restate:8080/Counter/a%2Fb%20c%3F/add', (string) $this->http->lastRequest()->getUri());
    }

    public function testABaseUrlWithAPathPrefixAndTrailingSlashIsKept(): void
    {
        $this->http->respondJson(200, '1');

        IngressClient::connect('https://example.com/restate/', $this->http, new HttpFactory())->serviceClient('Greeter')->request('greet')->call();

        self::assertSame('https://example.com/restate/Greeter/greet', (string) $this->http->lastRequest()->getUri());
    }

    /** Restate rejects a content type on the empty body of a handler without input, also one the headers set. */
    public function testANullPayloadIsSentAsAnEmptyBodyWithoutContentTypeAndAnEmptyResultIsNull(): void
    {
        $this->http->respond(200)->respondJson(202, '{"invocationId":"inv_1abc","status":"Accepted"}');
        $client = $this->client(['Content-Type' => 'text/plain']);

        $result = $client->objectClient('Counter', 'alice')->request('reset')->call();
        $client->objectClient('Counter', 'alice')->request('reset')->header('content-type', 'application/json')->send();

        self::assertNull($result);
        foreach ($this->http->requests as $request) {
            self::assertSame('', (string) $request->getBody());
            self::assertFalse($request->hasHeader('content-type'));
        }
    }

    public function testConnectionHeadersAreSentWithEveryRequestAndRequestHeadersOverrideThem(): void
    {
        $this->http->respondJson(200, '1')->respondJson(200, '2');
        $client = $this->client(['Authorization' => 'Bearer secret', 'x-tenant' => 'default']);

        $client->serviceClient('Greeter')->request('greet', 'a')->call();
        $client->serviceClient('Greeter')->request('greet', 'b')->headers(['x-trace' => 't1'])->header('X-Tenant', 'acme')->call();

        [$first, $second] = $this->http->requests;
        self::assertSame('Bearer secret', $first->getHeaderLine('authorization'));
        self::assertSame('default', $first->getHeaderLine('x-tenant'));
        self::assertSame('Bearer secret', $second->getHeaderLine('authorization'));
        self::assertSame('acme', $second->getHeaderLine('x-tenant'));
        self::assertSame('t1', $second->getHeaderLine('x-trace'));
    }

    public function testTheIdempotencyKeyIsSentAsAHeader(): void
    {
        $this->http->respondJson(200, '1');

        $this->client()->serviceClient('Greeter')->request('greet', 'Mary')->idempotencyKey('greet-mary')->call();

        self::assertSame('greet-mary', $this->http->lastRequest()->getHeaderLine('idempotency-key'));
    }

    public function testAnEmptyIdempotencyKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client()->serviceClient('Greeter')->request('greet')->idempotencyKey('');
    }

    public function testInvalidServiceAndHandlerNamesAreRejectedBeforeAnyRequest(): void
    {
        try {
            $this->client()->serviceClient('Bad Name')->request('greet')->call();
            self::fail('Expected an invalid service name to be rejected.');
        } catch (\InvalidArgumentException) {
        }

        try {
            $this->client()->objectClient('Counter', '')->request('add');
            self::fail('Expected an empty key to be rejected.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], $this->http->requests);
    }

    public function testSendReturnsTheInvocationIdAndStatus(): void
    {
        $this->http
            ->respondJson(202, '{"invocationId":"inv_1abc","status":"Accepted"}')
            ->respondJson(202, '{"invocationId":"inv_1abc","status":"PreviouslyAccepted"}');
        $request = $this->client()->objectClient('Counter', 'alice')->request('add', 1)->idempotencyKey('add-1');

        $first = $request->send();
        $second = $request->send();

        self::assertSame('inv_1abc', $first->invocationId());
        self::assertSame(SendStatus::Accepted, $first->status());
        self::assertSame(SendStatus::PreviouslyAccepted, $second->status());
        $sent = $this->http->lastRequest();
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('http://restate:8080/Counter/alice/add/send', (string) $sent->getUri());
        self::assertSame('1', (string) $sent->getBody());
        self::assertSame('add-1', $sent->getHeaderLine('idempotency-key'));
    }

    public function testSendAfterDelaysTheInvocation(): void
    {
        $this->http->respondJson(202, '{"invocationId":"inv_1abc","executionTime":"2026-11-16T16:27:43.137000000Z","status":"Accepted"}');

        $response = $this->client()->serviceClient('Greeter')->request('greet', 'Mary')->sendAfter(1500);

        self::assertSame('inv_1abc', $response->invocationId());
        self::assertSame('http://restate:8080/Greeter/greet/send?delay=1500ms', (string) $this->http->lastRequest()->getUri());
    }

    public function testSendAfterZeroSendsAtOnceAndANegativeDelayIsRejected(): void
    {
        $this->http->respondJson(202, '{"invocationId":"inv_1abc","status":"Accepted"}');
        $request = $this->client()->serviceClient('Greeter')->request('greet', 'Mary');

        $request->sendAfter(0);
        self::assertSame('http://restate:8080/Greeter/greet/send', (string) $this->http->lastRequest()->getUri());

        $this->expectException(\InvalidArgumentException::class);
        $request->sendAfter(-1);
    }

    public function testTheGenericMethodsTakeATarget(): void
    {
        $this->http
            ->respondJson(200, '"Hello Mary"')
            ->respondJson(202, '{"invocationId":"inv_1abc","status":"Accepted"}')
            ->respondJson(202, '{"invocationId":"inv_1def","status":"Accepted"}');
        $client = $this->client();

        self::assertSame('Hello Mary', $client->call(Target::service('Greeter', 'greet'), 'Mary', idempotencyKey: 'k', headers: ['x-a' => 'b']));
        self::assertSame('inv_1abc', $client->send(Target::object('Counter', 'alice', 'add'), 1)->invocationId());
        self::assertSame('inv_1def', $client->sendAfter(Target::workflow('Checkout', 'o-1', 'run'), [], 10)->invocationId());

        [$call, $send, $sendAfter] = $this->http->requests;
        self::assertSame('http://restate:8080/Greeter/greet', (string) $call->getUri());
        self::assertSame('k', $call->getHeaderLine('idempotency-key'));
        self::assertSame('b', $call->getHeaderLine('x-a'));
        self::assertSame('http://restate:8080/Counter/alice/add/send', (string) $send->getUri());
        self::assertSame('http://restate:8080/Checkout/o-1/run/send?delay=10ms', (string) $sendAfter->getUri());
    }

    public function testRequestSerdesEncodeTheInputAndDecodeTheOutput(): void
    {
        $this->http->respond(200, 'RESULT', ['content-type' => 'text/plain']);

        $result = $this->client()->serviceClient('Echo')->request('upper', 'input')
            ->inputSerde(new UpperCaseSerde())
            ->outputSerde(new LowerCaseSerde())
            ->call();

        self::assertSame('result', $result);
        $request = $this->http->lastRequest();
        self::assertSame('INPUT', (string) $request->getBody());
        self::assertSame('text/x-upper', $request->getHeaderLine('content-type'));
    }

    public function testOneSerdeForInputAndOutputAndAConnectionDefault(): void
    {
        $this->http->respond(200, 'ONE')->respond(200, 'TWO');

        $client = IngressClient::connect('http://restate:8080', $this->http, new HttpFactory(), serde: new UpperCaseSerde());
        self::assertSame('ONE', $client->serviceClient('Echo')->request('echo', 'one')->call());
        self::assertSame('two', $client->serviceClient('Echo')->request('echo', 'two')->serde(new LowerCaseSerde())->call());

        [$first, $second] = $this->http->requests;
        self::assertSame('ONE', (string) $first->getBody());
        self::assertSame('two', (string) $second->getBody());
        self::assertSame('text/x-lower', $second->getHeaderLine('content-type'));
    }

    public function testAttachesToAnInvocationById(): void
    {
        $this->http->respondJson(200, '"done"');

        $result = $this->client()->invocation('inv_1abc')->attach();

        self::assertSame('done', $result);
        $request = $this->http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('http://restate:8080/restate/invocation/inv_1abc/attach', (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
    }

    public function testOutputIsReadyOnceTheInvocationFinishedAndNotReadyWhileItRuns(): void
    {
        $this->http
            ->respondJson(470, '{"code":470,"message":"the invocation exists but has not completed yet","source":"ingress"}')
            ->respondJson(200, '"done"')
            ->respond(200);
        $invocation = $this->client()->invocation('inv_1abc');

        $running = $invocation->output();
        $finished = $invocation->output();
        $finishedWithNull = $invocation->output();

        self::assertFalse($running->isReady());
        self::assertTrue($finished->isReady());
        self::assertSame('done', $finished->value());
        self::assertTrue($finishedWithNull->isReady());
        self::assertNull($finishedWithNull->value());
        self::assertSame('http://restate:8080/restate/invocation/inv_1abc/output', (string) $this->http->lastRequest()->getUri());

        $this->expectException(\LogicException::class);
        $running->value();
    }

    public function testOutputValues(): void
    {
        self::assertSame(['a' => 1], Output::ready(['a' => 1])->value());
        self::assertFalse(Output::notReady()->isReady());
    }

    public function testASendResponseAttachesWithTheOutputSerdeOfItsRequest(): void
    {
        $this->http
            ->respondJson(202, '{"invocationId":"inv_1abc","status":"Accepted"}')
            ->respond(200, 'DONE')
            ->respond(200, 'DONE')
            ->respond(200, 'Done');

        $response = $this->client()->serviceClient('Echo')->request('upper', 'x')->outputSerde(new LowerCaseSerde())->send();

        self::assertSame('done', $response->attach());
        self::assertSame('done', $response->output()->value());
        self::assertSame('http://restate:8080/restate/invocation/inv_1abc/output', (string) $this->http->lastRequest()->getUri());
        self::assertSame('DONE', $response->invocation()->attach(new UpperCaseSerde()));
    }

    public function testAttachesToAnIdempotentInvocationByItsTargetAndKey(): void
    {
        $this->http->respondJson(200, '1')->respondJson(200, '2');
        $client = $this->client();

        $client->idempotentInvocation(Target::service('Greeter', 'greet'), 'key/1')->attach();
        $client->idempotentInvocation(Target::object('Counter', 'a b', 'add'), 'k')->output();

        [$service, $object] = $this->http->requests;
        self::assertSame('http://restate:8080/restate/invocation/Greeter/greet/key%2F1/attach', (string) $service->getUri());
        self::assertSame('http://restate:8080/restate/invocation/Counter/a%20b/add/k/output', (string) $object->getUri());
    }

    public function testWorkflowSubmitAttachOutputAndSharedHandlers(): void
    {
        $this->http
            ->respondJson(202, '{"invocationId":"inv_1wf","status":"Accepted"}')
            ->respondJson(470, '{"code":470,"message":"the invocation exists but has not completed yet","source":"ingress"}')
            ->respond(200)
            ->respondJson(200, '"approved"')
            ->respondJson(200, '"approved"');
        $workflow = $this->client()->workflowClient('Checkout', 'order 1');

        $submission = $workflow->submit(['item' => 'book']);
        $output = $workflow->output();
        $workflow->request('approve', ['status' => 'approved'])->call();
        $result = $workflow->attach();
        $finished = $workflow->output();

        self::assertSame('inv_1wf', $submission->invocationId());
        self::assertFalse($output->isReady());
        self::assertSame('approved', $result);
        self::assertSame('approved', $finished->value());
        [$submit, $peek, $approve, $attach] = $this->http->requests;
        self::assertSame('http://restate:8080/Checkout/order%201/run/send', (string) $submit->getUri());
        self::assertSame('{"item":"book"}', (string) $submit->getBody());
        self::assertSame('GET', $peek->getMethod());
        self::assertSame('http://restate:8080/restate/workflow/Checkout/order%201/output', (string) $peek->getUri());
        self::assertSame('http://restate:8080/Checkout/order%201/approve', (string) $approve->getUri());
        self::assertSame('http://restate:8080/restate/workflow/Checkout/order%201/attach', (string) $attach->getUri());
    }

    public function testResolvesAnAwakeableWithAValue(): void
    {
        $this->http->respond(202)->respond(202)->respond(202);
        $client = $this->client(['authorization' => 'Bearer secret']);

        $client->resolveAwakeable('sign_1/x', ['approved' => true]);
        $client->resolveAwakeable('sign_1abc');
        $client->resolveAwakeable('sign_1abc', 'raw', new UpperCaseSerde());

        [$value, $null, $custom] = $this->http->requests;
        self::assertSame('POST', $value->getMethod());
        self::assertSame('http://restate:8080/restate/awakeables/sign_1%2Fx/resolve', (string) $value->getUri());
        self::assertSame('{"approved":true}', (string) $value->getBody());
        self::assertSame('application/json', $value->getHeaderLine('content-type'));
        self::assertSame('Bearer secret', $value->getHeaderLine('authorization'));
        // An awakeable always completes with a value: null is JSON null, as with Context::resolveAwakeable().
        self::assertSame('null', (string) $null->getBody());
        self::assertSame('RAW', (string) $custom->getBody());
        self::assertSame('text/x-upper', $custom->getHeaderLine('content-type'));
    }

    public function testRejectsAnAwakeableWithAReason(): void
    {
        $this->http->respond(202);

        $this->client()->rejectAwakeable('sign_1abc', 'denied');

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('http://restate:8080/restate/awakeables/sign_1abc/reject', (string) $request->getUri());
        self::assertSame('text/plain', $request->getHeaderLine('content-type'));
        self::assertSame('denied', (string) $request->getBody());
    }

    public function testAnInvocationThatFailedTerminallyThrowsItsTerminalError(): void
    {
        $this->http->respond(402, '{"code":402,"message":"Card reported lost","metadata":{"reason":"lost","404":"x"},"source":"invocation"}', [
            'content-type' => 'application/json',
            'x-restate-error-source' => 'invocation',
        ]);

        try {
            $this->client()->serviceClient('Payments')->request('charge', ['amount' => 1])->call();
            self::fail('Expected a TerminalError.');
        } catch (TerminalError $e) {
            self::assertSame(402, $e->code());
            self::assertSame('Card reported lost', $e->getMessage());
            self::assertSame(['reason' => 'lost', '404' => 'x'], $e->metadata());
        }
    }

    public function testAttachingToAFailedInvocationThrowsItsTerminalError(): void
    {
        $this->http
            ->respond(400, '{"code":400,"message":"Payment declined","source":"invocation"}', ['x-restate-error-source' => 'invocation'])
            ->respond(400, '{"code":400,"message":"Payment declined","source":"invocation"}', ['x-restate-error-source' => 'invocation']);
        $invocation = $this->client()->invocation('inv_1abc');

        foreach ([static fn () => $invocation->attach(), static fn () => $invocation->output()] as $read) {
            try {
                $read();
                self::fail('Expected a TerminalError.');
            } catch (TerminalError $e) {
                self::assertSame(400, $e->code());
                self::assertSame('Payment declined', $e->getMessage());
                self::assertSame([], $e->metadata());
            }
        }
    }

    public function testAnErrorOfTheIngressThrowsAnIngressException(): void
    {
        $body = '{"code":404,"message":"service \'Nope\' not found, make sure to register the service before calling it.","source":"ingress"}';
        $this->http->respond(404, $body, ['content-type' => 'application/json', 'x-restate-error-source' => 'ingress']);

        try {
            $this->client()->serviceClient('Nope')->request('handler', 1)->call();
            self::fail('Expected an IngressException.');
        } catch (IngressException $e) {
            self::assertSame(404, $e->statusCode());
            self::assertSame("service 'Nope' not found, make sure to register the service before calling it.", $e->errorMessage());
            self::assertSame('ingress', $e->errorSource());
            self::assertSame($body, $e->responseBody());
            self::assertSame('POST', $e->requestMethod());
            self::assertSame('http://restate:8080/Nope/handler', $e->requestUri());
            self::assertStringContainsString('404', $e->getMessage());
            self::assertStringContainsString('not found', $e->getMessage());
        }
    }

    public function testAnErrorWithoutAnErrorSourceIsAnIngressException(): void
    {
        // A proxy between the client and Restate may strip the header; the status alone does not tell whose error it is.
        $this->http->respondJson(400, '{"code":400,"message":"Payment declined"}');

        try {
            $this->client()->serviceClient('Payments')->request('charge', 1)->call();
            self::fail('Expected an IngressException.');
        } catch (IngressException $e) {
            self::assertSame(400, $e->statusCode());
            self::assertSame('Payment declined', $e->errorMessage());
            self::assertNull($e->errorSource());
        }
    }

    public function testTheErrorSourceInTheBodyIsUsedWhenTheHeaderIsMissing(): void
    {
        $this->http->respondJson(409, '{"code":409,"message":"the workflow method was already invoked","source":"invocation"}');

        $this->expectException(TerminalError::class);
        $this->expectExceptionCode(409);

        $this->client()->workflowClient('Checkout', 'o-1')->request('run', [])->call();
    }

    public function testAnErrorResponseThatIsNotJsonIsAnIngressException(): void
    {
        $this->http->respond(502, '<html>Bad gateway</html>', ['content-type' => 'text/html']);

        try {
            $this->client()->serviceClient('Greeter')->request('greet', 'Mary')->call();
            self::fail('Expected an IngressException.');
        } catch (IngressException $e) {
            self::assertSame(502, $e->statusCode());
            self::assertNull($e->errorMessage());
            self::assertSame('<html>Bad gateway</html>', $e->responseBody());
        }
    }

    public function testATransportFailureIsAnIngressExceptionWithoutAStatus(): void
    {
        $failure = new TransportFailure('Connection refused');
        $this->http->fail($failure);

        try {
            $this->client()->serviceClient('Greeter')->request('greet', 'Mary')->call();
            self::fail('Expected an IngressException.');
        } catch (IngressException $e) {
            self::assertNull($e->statusCode());
            self::assertNull($e->responseBody());
            self::assertSame($failure, $e->getPrevious());
            self::assertStringContainsString('Connection refused', $e->getMessage());
        }
    }

    public function testAResponseThatCannotBeDecodedIsAnIngressException(): void
    {
        $this->http->respondJson(200, '{not json');

        try {
            $this->client()->serviceClient('Greeter')->request('greet', 'Mary')->call();
            self::fail('Expected an IngressException.');
        } catch (IngressException $e) {
            self::assertSame(200, $e->statusCode());
            self::assertSame('{not json', $e->responseBody());
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    public function testAMalformedSendResponseIsAnIngressException(): void
    {
        $this->http->respondJson(202, '{"status":"Accepted"}')->respondJson(202, '{"invocationId":"inv_1abc","status":"Unknown"}');
        $request = $this->client()->serviceClient('Greeter')->request('greet', 'Mary');

        foreach ([1, 2] as $_) {
            try {
                $request->send();
                self::fail('Expected an IngressException.');
            } catch (IngressException $e) {
                self::assertSame(202, $e->statusCode());
            }
        }
    }

    public function testTheStreamFactoryDefaultsToTheRequestFactoryWhenItIsOne(): void
    {
        $requestFactory = new class () implements RequestFactoryInterface {
            #[\Override]
            public function createRequest(string $method, $uri): RequestInterface
            {
                return new HttpFactory()->createRequest($method, $uri);
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('StreamFactoryInterface');

        IngressClient::connect('http://restate:8080', $this->http, $requestFactory);
    }

    public function testTheUrlMustBeAnAbsoluteHttpUrl(): void
    {
        foreach (['', 'restate:8080', 'ftp://restate', '/relative'] as $url) {
            try {
                IngressClient::connect($url, $this->http, new HttpFactory());
                self::fail("Expected {$url} to be rejected.");
            } catch (\InvalidArgumentException) {
            }
        }

        $this->expectNotToPerformAssertions();
    }

    /** @param array<string, string> $headers */
    private function client(array $headers = []): IngressClient
    {
        return IngressClient::connect('http://restate:8080', $this->http, new HttpFactory(), headers: $headers);
    }
}

final class UpperCaseSerde implements Serde
{
    #[\Override]
    public function serialize(mixed $value): string
    {
        return strtoupper((string) (is_scalar($value) ? $value : ''));
    }

    #[\Override]
    public function deserialize(string $bytes): mixed
    {
        return strtoupper($bytes);
    }

    #[\Override]
    public function contentType(): string
    {
        return 'text/x-upper';
    }
}

final class LowerCaseSerde implements Serde
{
    #[\Override]
    public function serialize(mixed $value): string
    {
        return strtolower((string) (is_scalar($value) ? $value : ''));
    }

    #[\Override]
    public function deserialize(string $bytes): mixed
    {
        return strtolower($bytes);
    }

    #[\Override]
    public function contentType(): string
    {
        return 'text/x-lower';
    }
}

final class TransportFailure extends \RuntimeException implements ClientExceptionInterface
{
}
