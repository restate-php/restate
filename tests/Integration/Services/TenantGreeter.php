<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Services;

use Restate\Attributes as Restate;
use Restate\Context;

/**
 * A service class the integration endpoint instantiates per invocation attempt with a factory that reads the
 * invocation's `x-tenant` header (HandlerFactoryTest).
 */
#[Restate\Service('TenantGreeter')]
final class TenantGreeter
{
    private readonly string $instance;

    public function __construct(private readonly string $tenant)
    {
        $this->instance = bin2hex(random_bytes(8));
    }

    /**
     * The run records the instance of the attempt that executed it; the result names the instance of the attempt that
     * returns. In request/response mode the run's attempt suspends until Restate acknowledges it, so they differ.
     *
     * @return array{tenant: string, greeting: string, runInstance: string, returnInstance: string}
     */
    #[Restate\Handler]
    public function greet(Context $ctx, string $name): array
    {
        $runInstance = $ctx->run('instance', fn (): string => $this->instance);

        return ['tenant' => $this->tenant, 'greeting' => "Hello {$name}", 'runInstance' => $runInstance, 'returnInstance' => $this->instance];
    }
}
