<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use JsonException;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\Internal\InvocationAttempt;
use Restate\JsonSerde;
use Restate\Payload;
use Restate\Service;
use Restate\Internal\ServiceRegistry;
use Restate\TerminalError;
use Restate\Tests\Support\ScriptedInvocation;

final class ServiceRegistryErrorTest extends TestCase
{
    public function testMalformedJsonInputIsATerminal400(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): string => $name),
        ]);

        $this->expectException(TerminalError::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage('Invalid JSON payload: Syntax error');

        $registry->invoke('Greeter', 'greet', '{bad-json', $this->context());
    }

    public function testMalformedInputForCustomJsonSerdeIsATerminal400(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler('greet', static fn (Context $ctx, mixed $request): string => 'unused', input: Payload::custom(JsonSerde::default())),
        ]);

        $this->expectException(TerminalError::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage('Invalid JSON payload: Syntax error');

        $registry->invoke('Greeter', 'greet', '{bad-json', $this->context());
    }

    public function testJsonExceptionFromHandlerCodeIsNotATerminalFailure(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Greeter')->handler('greet', static fn (Context $ctx, string $name): mixed => json_decode('{bad-json', flags: JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(JsonException::class);

        $registry->invoke('Greeter', 'greet', '"Mary"', $this->context());
    }

    public function testTerminalErrorBubblesForEndpointConversion(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Payments')->handler('charge', static function (Context $ctx, array $request): string {
                throw new TerminalError('Payment declined', 400);
            }),
        ]);

        $this->expectException(TerminalError::class);
        $this->expectExceptionMessage('Payment declined');

        $registry->invoke('Payments', 'charge', '{"amount":100}', $this->context());
    }

    private function context(): InvocationAttempt
    {
        return new InvocationAttempt(ScriptedInvocation::unused(), 0);
    }
}
