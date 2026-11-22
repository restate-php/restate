<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Attributes\Handler;
use Restate\Attributes\Shared;
use Restate\Attributes\Workflow as WorkflowAttribute;
use Restate\Context;
use Restate\Internal\InvocationAttempt;
use Restate\JournalValueCodec;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Payload;
use Restate\Reflection\ServiceReflector;
use Restate\Request;
use Restate\Serde;
use Restate\Service;
use Restate\Internal\ServiceRegistry;
use Restate\TerminalError;
use Restate\Tests\Support\Contexts;
use Restate\Tests\Support\ScriptedInvocation;
use Restate\VirtualObject;
use Restate\Workflow;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

final class ServiceRegistryTestEnvelopeSerde implements Serde
{
    public function serialize(mixed $value): string
    {
        return 'encoded:' . json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function deserialize(string $bytes): mixed
    {
        if (!str_starts_with($bytes, 'encoded:')) {
            throw new \InvalidArgumentException('Expected encoded payload.');
        }

        return json_decode(substr($bytes, 8), true, flags: JSON_THROW_ON_ERROR);
    }

    public function contentType(): string
    {
        return 'application/x-envelope';
    }
}

final readonly class ServiceRegistryTestPrefixCodec implements JournalValueCodec
{
    public function __construct(private string $prefix)
    {
    }

    public function encode(string $bytes): string
    {
        return $this->prefix . ':' . $bytes;
    }

    public function decode(string $bytes): string
    {
        return substr($bytes, strlen($this->prefix) + 1);
    }
}

#[WorkflowAttribute('ReflectedCheckout')]
final class ServiceRegistryReflectedWorkflowFixture
{
    /** @return array<string, string> */
    #[Handler]
    public function run(WorkflowContext $ctx, string $orderId): array
    {
        return ['context' => Contexts::interfaceOf($ctx), 'key' => $ctx->key(), 'orderId' => $orderId];
    }

    /** @return array<string, string> */
    #[Handler('approve')]
    #[Shared]
    public function approve(WorkflowSharedContext $ctx, string $orderId): array
    {
        return ['context' => Contexts::interfaceOf($ctx), 'key' => $ctx->key(), 'orderId' => $orderId];
    }
}

final class ServiceRegistryTest extends TestCase
{
    public function testInvokesRegisteredHandler(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}"),
        ]);

        $output = $registry->invoke('Greeter', 'greet', '"Mary"', $this->context());

        self::assertSame('"Hello Mary"', $output);
    }

    public function testEmptyInputPayloadDoesNotRequireJsonBody(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Utility')->handler(
                'empty',
                static fn (Context $ctx, mixed $input): array => ['input' => $input],
                Payload::empty(),
            ),
        ]);

        $output = $registry->invoke('Utility', 'empty', '', $this->context());

        self::assertSame(['input' => null], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testBinaryInputAndOutputPayloadUsesRawBytes(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Utility')->handler(
                'raw',
                static fn (Context $ctx, string $input): string => $input,
                Payload::binary('application/octet-stream'),
                Payload::binary('application/octet-stream'),
            ),
        ]);
        $bytes = "raw\0bytes";

        self::assertSame($bytes, $registry->invoke('Utility', 'raw', $bytes, $this->context()));
    }

    public function testCustomSerdeInputAndOutputPayloadsUseSerde(): void
    {
        $serde = new ServiceRegistryTestEnvelopeSerde();
        $registry = new ServiceRegistry([
            Service::new('Codec')->handler(
                'echo',
                static fn (Context $ctx, array $input): array => ['seen' => $input['name']],
                Payload::custom($serde),
                Payload::custom($serde),
            ),
        ]);

        self::assertSame('encoded:{"seen":"Mary"}', $registry->invoke('Codec', 'echo', 'encoded:{"name":"Mary"}', $this->context()));
    }

    public function testEmptyOutputPayloadReturnsEmptyString(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Utility')->handler(
                'emptyOutput',
                static fn (Context $ctx, mixed $input): string => 'ignored',
                output: Payload::empty(),
            ),
        ]);

        self::assertSame('', $registry->invoke('Utility', 'emptyOutput', 'null', $this->context()));
    }

    public function testNullReturnUsesJsonOutputPayloadWithoutStoredSchemaInference(): void
    {
        $service = Service::new('Utility')->handler(
            'nullOutput',
            static fn (Context $ctx, mixed $input): null => null,
        );
        $registry = new ServiceRegistry([$service]);

        self::assertSame(
            ['setContentTypeIfEmpty' => false, 'contentType' => 'application/json', 'jsonSchema' => true],
            $service->definition()->handlers[0]->outputPayload->outputManifest(),
        );
        self::assertSame('null', $registry->invoke('Utility', 'nullOutput', 'null', $this->context()));
    }

    public function testExplicitEmptyPayloadKeepsNullReturnEmpty(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Utility')->handler(
                'nullOutput',
                static fn (Context $ctx, mixed $input): null => null,
                output: Payload::empty(),
            ),
        ]);

        self::assertSame('', $registry->invoke('Utility', 'nullOutput', 'null', $this->context()));
    }

    public function testBinaryOutputPayloadRequiresStringResult(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Utility')->handler(
                'raw',
                static fn (Context $ctx, string $input): array => ['not' => 'bytes'],
                Payload::binary('application/octet-stream'),
                Payload::binary('application/octet-stream'),
            ),
        ]);

        try {
            $registry->invoke('Utility', 'raw', 'bytes', $this->context());
            self::fail('Expected invalid binary output to throw.');
        } catch (TerminalError $e) {
            self::assertSame('Binary output handlers must return a string.', $e->getMessage());
            self::assertSame(500, $e->statusCode());
        }
    }

    public function testServiceHandlerReceivesRootRandomSeed(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler('seed', static fn (Context $ctx, mixed $input): int => $ctx->randomSeed()),
        ]);

        self::assertSame('123', $registry->invoke('Greeter', 'seed', 'null', $this->context(123)));
    }

    /** @return iterable<string, array{string, ?string, array{service: string, handler: string, key: ?string}}> */
    public static function requestHandlers(): iterable
    {
        yield 'service handler' => ['Greeter', null, ['service' => 'Greeter', 'handler' => 'request', 'key' => null]];
        yield 'exclusive object handler' => ['Counter', 'counter-1', ['service' => 'Counter', 'handler' => 'request', 'key' => 'counter-1']];
        yield 'shared object handler' => ['Counter', 'counter-1', ['service' => 'Counter', 'handler' => 'sharedRequest', 'key' => 'counter-1']];
        yield 'workflow run handler' => ['Checkout', 'checkout-1', ['service' => 'Checkout', 'handler' => 'run', 'key' => 'checkout-1']];
        yield 'shared workflow handler' => ['Checkout', 'checkout-1', ['service' => 'Checkout', 'handler' => 'sharedRequest', 'key' => 'checkout-1']];
    }

    /** @param array{service: string, handler: string, key: ?string} $target */
    #[DataProvider('requestHandlers')]
    public function testHandlerContextExposesTheRequestOfTheAttempt(string $service, ?string $key, array $target): void
    {
        $describe = static fn (Context $ctx): array => self::describeRequest($ctx->request());
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler('request', static fn (Context $ctx, mixed $_): array => $describe($ctx)),
            VirtualObject::new('Counter')
                ->handler('request', static fn (ObjectContext $ctx, mixed $_): array => $describe($ctx))
                ->sharedHandler('sharedRequest', static fn (ObjectSharedContext $ctx, mixed $_): array => $describe($ctx)),
            Workflow::new('Checkout')
                ->run(static fn (WorkflowContext $ctx, mixed $_): array => $describe($ctx))
                ->sharedHandler('sharedRequest', static fn (WorkflowSharedContext $ctx, mixed $_): array => $describe($ctx)),
        ]);
        $attempt = new InvocationAttempt(
            ScriptedInvocation::unused(),
            invocationId: 'inv_1abc',
            headers: [['X-My-Header', 'first'], ['x-my-header', 'second'], ['x-other', 'value']],
            attemptHeaders: [['content-type', 'application/vnd.restate.invocation.v7'], ['x-restate-attempt', '2']],
            idempotencyKey: 'idem-1',
            scope: 'tenant-a',
            limitKey: 'limit-1',
        );

        $output = $registry->invoke($service, $target['handler'], '{"name":"Mary"}', $attempt, $key);

        self::assertSame([
            'id' => 'inv_1abc',
            'target' => $target,
            'headers' => ['x-my-header' => 'second', 'x-other' => 'value'],
            'attemptHeaders' => ['content-type' => 'application/vnd.restate.invocation.v7', 'x-restate-attempt' => '2'],
            'body' => '{"name":"Mary"}',
            'idempotencyKey' => 'idem-1',
            'scope' => 'tenant-a',
            'limitKey' => 'limit-1',
        ], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testRequestWithoutIdempotencyKeyScopeOrLimitKeyHasNone(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler('request', static fn (Context $ctx, mixed $_): array => self::describeRequest($ctx->request())),
        ]);

        $output = json_decode($registry->invoke('Greeter', 'request', 'null', $this->context()), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($output);
        self::assertSame([null, null, null], [$output['idempotencyKey'], $output['scope'], $output['limitKey']]);
    }

    public function testRequestBodyIsTheInputAsRestateSentIt(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler('body', static fn (Context $ctx, string $name): array => ['name' => $name, 'body' => $ctx->request()->body]),
        ], journalValueCodec: new ServiceRegistryTestPrefixCodec('codec'));

        self::assertSame(
            'codec:{"name":"Mary","body":"codec:\"Mary\""}',
            $registry->invoke('Greeter', 'body', 'codec:"Mary"', $this->context()),
        );
    }

    /** @return array<string, mixed> */
    private static function describeRequest(Request $request): array
    {
        return [
            'id' => $request->id,
            'target' => ['service' => $request->target->serviceName(), 'handler' => $request->target->handlerName(), 'key' => $request->target->key()],
            'headers' => $request->headers,
            'attemptHeaders' => $request->attemptHeaders,
            'body' => $request->body,
            'idempotencyKey' => $request->idempotencyKey,
            'scope' => $request->scope,
            'limitKey' => $request->limitKey,
        ];
    }

    public function testUnknownServiceIsRejected(): void
    {
        $registry = new ServiceRegistry([]);

        $this->expectException(TerminalError::class);
        $this->expectExceptionMessage('Unknown service Missing');

        $registry->invoke('Missing', 'greet', '"Mary"', $this->context());
    }

    public function testHandlerContextRunsOnTheOperationsOfTheAttempt(): void
    {
        $operations = ScriptedInvocation::new();
        $awakeableId = static fn (Context $ctx): string => $ctx->awakeable()->id();
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler('awake', static fn (Context $ctx, mixed $_): string => $awakeableId($ctx)),
            VirtualObject::new('Counter')
                ->handler('awake', static fn (ObjectContext $ctx, mixed $_): string => $awakeableId($ctx))
                ->sharedHandler('sharedAwake', static fn (ObjectSharedContext $ctx, mixed $_): string => $awakeableId($ctx)),
            Workflow::new('Checkout')
                ->run(static fn (WorkflowContext $ctx, mixed $_): string => $awakeableId($ctx))
                ->sharedHandler('sharedAwake', static fn (WorkflowSharedContext $ctx, mixed $_): string => $awakeableId($ctx)),
        ]);
        $attempt = new InvocationAttempt($operations);

        self::assertSame('"awakeable-1"', $registry->invoke('Greeter', 'awake', 'null', $attempt));
        self::assertSame('"awakeable-2"', $registry->invoke('Counter', 'awake', 'null', $attempt, 'counter-1'));
        self::assertSame('"awakeable-3"', $registry->invoke('Counter', 'sharedAwake', 'null', $attempt, 'counter-1'));
        self::assertSame('"awakeable-4"', $registry->invoke('Checkout', 'run', 'null', $attempt, 'checkout-1'));
        self::assertSame('"awakeable-5"', $registry->invoke('Checkout', 'sharedAwake', 'null', $attempt, 'checkout-1'));
        self::assertCount(5, $operations->calls('sysAwakeable'));
    }

    public function testUnknownHandlerIsRejected(): void
    {
        $registry = new ServiceRegistry([Service::new('Greeter')]);

        $this->expectException(TerminalError::class);
        $this->expectExceptionMessage('Unknown handler Greeter/greet');

        $registry->invoke('Greeter', 'greet', '"Mary"', $this->context());
    }

    public function testVirtualObjectExclusiveHandlerReceivesObjectContextWithInvocationKey(): void
    {
        $registry = new ServiceRegistry([
            VirtualObject::new('Counter')->handler('increment', static function (ObjectContext $ctx, int $amount): array {
                return ['context' => Contexts::interfaceOf($ctx), 'key' => $ctx->key(), 'amount' => $amount];
            }),
        ]);

        $output = $registry->invoke('Counter', 'increment', '3', $this->context(), 'counter-1');

        self::assertSame([
            'context' => ObjectContext::class,
            'key' => 'counter-1',
            'amount' => 3,
        ], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testVirtualObjectHandlerReceivesRootRandomSeed(): void
    {
        $registry = new ServiceRegistry([
            VirtualObject::new('Counter')->handler('seed', static fn (ObjectContext $ctx, mixed $input): int => $ctx->randomSeed()),
        ]);

        self::assertSame('456', $registry->invoke('Counter', 'seed', 'null', $this->context(456), 'counter-1'));
    }

    public function testVirtualObjectSharedHandlerReceivesObjectSharedContextWithInvocationKey(): void
    {
        $registry = new ServiceRegistry([
            VirtualObject::new('Counter')->sharedHandler('get', static function (ObjectSharedContext $ctx): array {
                return ['context' => Contexts::interfaceOf($ctx), 'key' => $ctx->key()];
            }),
        ]);

        $output = $registry->invoke('Counter', 'get', 'null', $this->context(), 'counter-1');

        self::assertSame([
            'context' => ObjectSharedContext::class,
            'key' => 'counter-1',
        ], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testVirtualObjectSharedHandlerReceivesRootRandomSeed(): void
    {
        $registry = new ServiceRegistry([
            VirtualObject::new('Counter')->sharedHandler('seed', static fn (ObjectSharedContext $ctx, mixed $input): int => $ctx->randomSeed()),
        ]);

        self::assertSame('777', $registry->invoke('Counter', 'seed', 'null', $this->context(777), 'counter-1'));
    }

    public function testVirtualObjectInvocationRequiresKey(): void
    {
        $registry = new ServiceRegistry([
            VirtualObject::new('Counter')->handler('increment', static fn (ObjectContext $ctx, int $amount): int => $amount + 1),
        ]);

        try {
            $registry->invoke('Counter', 'increment', '3', $this->context(), '');
            self::fail('Expected missing Virtual Object key to throw.');
        } catch (TerminalError $e) {
            self::assertSame('Virtual Object invocation key is required', $e->getMessage());
            self::assertSame(400, $e->statusCode());
        }
    }

    public function testWorkflowRunHandlerReceivesWorkflowContextWithInvocationKey(): void
    {
        $registry = new ServiceRegistry([
            Workflow::new('Checkout')->run(static function (WorkflowContext $ctx, string $orderId): array {
                return ['context' => Contexts::interfaceOf($ctx), 'key' => $ctx->key(), 'orderId' => $orderId];
            }),
        ]);

        $output = $registry->invoke('Checkout', 'run', '"order-123"', $this->context(), 'checkout-1');

        self::assertSame([
            'context' => WorkflowContext::class,
            'key' => 'checkout-1',
            'orderId' => 'order-123',
        ], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testWorkflowHandlersReceiveRootRandomSeed(): void
    {
        $registry = new ServiceRegistry([
            Workflow::new('Checkout')
                ->run(static fn (WorkflowContext $ctx, mixed $input): int => $ctx->randomSeed())
                ->sharedHandler('seed', static fn (WorkflowSharedContext $ctx, mixed $input): int => $ctx->randomSeed()),
        ]);

        self::assertSame('789', $registry->invoke('Checkout', 'run', 'null', $this->context(789), 'checkout-1'));
        self::assertSame('789', $registry->invoke('Checkout', 'seed', 'null', $this->context(789), 'checkout-1'));
    }

    public function testWorkflowSharedHandlerReceivesWorkflowSharedContextWithInvocationKey(): void
    {
        $registry = new ServiceRegistry([
            Workflow::new('Checkout')
                ->run(static fn (WorkflowContext $ctx, string $orderId): string => "started {$orderId}")
                ->sharedHandler('approve', static function (WorkflowSharedContext $ctx, string $orderId): array {
                    return ['context' => Contexts::interfaceOf($ctx), 'key' => $ctx->key(), 'orderId' => $orderId];
                }),
        ]);

        $output = $registry->invoke('Checkout', 'approve', '"order-123"', $this->context(), 'checkout-1');

        self::assertSame([
            'context' => WorkflowSharedContext::class,
            'key' => 'checkout-1',
            'orderId' => 'order-123',
        ], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testReflectedWorkflowHandlersReceiveWorkflowContexts(): void
    {
        $registry = new ServiceRegistry([
            ServiceReflector::fromObject(new ServiceRegistryReflectedWorkflowFixture()),
        ]);

        $runOutput = $registry->invoke('ReflectedCheckout', 'run', '"order-123"', $this->context(), 'checkout-1');
        self::assertSame([
            'context' => WorkflowContext::class,
            'key' => 'checkout-1',
            'orderId' => 'order-123',
        ], json_decode($runOutput, true, flags: JSON_THROW_ON_ERROR));

        $approveOutput = $registry->invoke('ReflectedCheckout', 'approve', '"order-123"', $this->context(), 'checkout-1');
        self::assertSame([
            'context' => WorkflowSharedContext::class,
            'key' => 'checkout-1',
            'orderId' => 'order-123',
        ], json_decode($approveOutput, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testWorkflowInvocationRequiresKey(): void
    {
        $registry = new ServiceRegistry([
            Workflow::new('Checkout')->run(static fn (WorkflowContext $ctx, string $orderId): string => "started {$orderId}"),
        ]);

        try {
            $registry->invoke('Checkout', 'run', '"order-123"', $this->context(), '');
            self::fail('Expected missing Workflow key to throw.');
        } catch (TerminalError $e) {
            self::assertSame('Workflow invocation key is required', $e->getMessage());
            self::assertSame(400, $e->statusCode());
        }
    }

    private function context(int $randomSeed = 0): InvocationAttempt
    {
        return new InvocationAttempt(ScriptedInvocation::unused(), $randomSeed);
    }
}
