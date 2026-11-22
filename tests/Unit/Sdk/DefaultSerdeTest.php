<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\Internal\Discovery;
use Restate\Internal\InvocationAttempt;
use Restate\JsonMappingException;
use Restate\JsonPayloadOptions;
use Restate\JsonValueMapper;
use Restate\JsonValueMappingContext;
use Restate\Payload;
use Restate\Serde;
use Restate\Service;
use Restate\Internal\ServiceRegistry;
use Restate\Tests\Support\FakeProtocolVersions;
use Restate\Tests\Support\ScriptedInvocation;

final class DefaultSerdeTest extends TestCase
{
    public function testEndpointDefaultSerdeAppliesToInferredHandlerInputAndOutput(): void
    {
        $serde = new DefaultSerdeEnvelopeSerde('endpoint', 'application/x-endpoint-default');
        $registry = new ServiceRegistry([
            Service::new('Users')->handler(
                'echo',
                static fn (Context $ctx, array $payload): array => ['seen' => $payload['name']],
            ),
        ], defaultSerde: $serde);

        self::assertSame('endpoint:{"seen":"Ada"}', $registry->invoke('Users', 'echo', 'endpoint:{"name":"Ada"}', $this->context()));
    }

    public function testServiceDefaultSerdeOverridesEndpointDefaultSerde(): void
    {
        $endpointSerde = new DefaultSerdeEnvelopeSerde('endpoint', 'application/x-endpoint-default');
        $serviceSerde = new DefaultSerdeEnvelopeSerde('service', 'application/x-service-default');
        $registry = new ServiceRegistry([
            Service::new('Users')
                ->defaultSerde($serviceSerde)
                ->handler(
                    'echo',
                    static fn (Context $ctx, array $payload): array => ['seen' => $payload['name']],
                ),
        ], defaultSerde: $endpointSerde);

        self::assertSame('service:{"seen":"Ada"}', $registry->invoke('Users', 'echo', 'service:{"name":"Ada"}', $this->context()));
    }

    public function testExplicitJsonPayloadOverridesDefaultSerdeAndKeepsJsonMapping(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler(
                'upper',
                static fn (Context $ctx, string $name): string => strtoupper($name),
                Payload::json(),
                Payload::json(),
            ),
        ], defaultSerde: new DefaultSerdeEnvelopeSerde('endpoint', 'application/x-endpoint-default'));

        self::assertSame('"ADA"', $registry->invoke('Users', 'upper', '"Ada"', $this->context()));
    }

    public function testCustomDefaultSerdeBypassesJsonValueMapping(): void
    {
        $mapper = new DefaultSerdeFailIfCalledMapper();
        $registry = new ServiceRegistry(
            [
                Service::new('Users')->handler(
                    'echo',
                    static fn (Context $ctx, array $payload): array => ['seen' => $payload['name']],
                ),
            ],
            JsonPayloadOptions::new()->withValueMapper($mapper),
            defaultSerde: new DefaultSerdeEnvelopeSerde('endpoint', 'application/x-endpoint-default'),
        );

        self::assertSame('endpoint:{"seen":"Ada"}', $registry->invoke('Users', 'echo', 'endpoint:{"name":"Ada"}', $this->context()));
        self::assertFalse($mapper->called);
    }

    public function testDiscoveryUsesInferredDefaultSerdeContentTypeWithoutJsonSchema(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler(
                'echo',
                static fn (Context $ctx, array $payload): array => ['seen' => $payload['name']],
            ),
        ], defaultSerde: new DefaultSerdeEnvelopeSerde('endpoint', 'application/x-endpoint-default'));

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE), true, flags: JSON_THROW_ON_ERROR);
        $input = $manifest['services'][0]['handlers'][0]['input'];
        $output = $manifest['services'][0]['handlers'][0]['output'];

        self::assertSame(['required' => false, 'contentType' => 'application/x-endpoint-default'], $input);
        self::assertSame(['setContentTypeIfEmpty' => false, 'contentType' => 'application/x-endpoint-default'], $output);
    }

    private function context(): InvocationAttempt
    {
        return new InvocationAttempt(ScriptedInvocation::unused());
    }
}

final readonly class DefaultSerdeEnvelopeSerde implements Serde
{
    public function __construct(private string $prefix, private string $contentType)
    {
    }

    #[\Override]
    public function serialize(mixed $value): string
    {
        return $this->prefix . ':' . json_encode($value, JSON_THROW_ON_ERROR);
    }

    #[\Override]
    public function deserialize(string $bytes): mixed
    {
        $prefix = $this->prefix . ':';
        if (!str_starts_with($bytes, $prefix)) {
            throw new \InvalidArgumentException("Expected {$this->prefix} bytes.");
        }

        return json_decode(substr($bytes, strlen($prefix)), true, flags: JSON_THROW_ON_ERROR);
    }

    #[\Override]
    public function contentType(): string
    {
        return $this->contentType;
    }
}

final class DefaultSerdeFailIfCalledMapper implements JsonValueMapper
{
    public bool $called = false;

    #[\Override]
    public function supports(JsonValueMappingContext $context): bool
    {
        $this->called = true;

        return true;
    }

    #[\Override]
    public function hydrate(mixed $value, JsonValueMappingContext $context): mixed
    {
        throw JsonMappingException::at($context->path, 'mapper should not be called');
    }

    #[\Override]
    public function normalize(mixed $value, JsonValueMappingContext $context): mixed
    {
        throw JsonMappingException::at($context->path, 'mapper should not be called');
    }
}
