<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\Internal\InvocationAttempt;
use Restate\Payload;
use Restate\Service;
use Restate\Internal\ServiceRegistry;
use Restate\TerminalError;
use Restate\Tests\Support\ScriptedInvocation;

enum DtoRuntimeRole: string
{
    case Admin = 'admin';
    case Member = 'member';
}

final readonly class DtoRuntimeProfile
{
    public function __construct(public string $displayName)
    {
    }
}

final readonly class DtoRuntimeCreateUser
{
    public function __construct(
        public string $name,
        public ?string $email,
        public DtoRuntimeRole $role = DtoRuntimeRole::Member,
        public ?DtoRuntimeProfile $profile = null,
    ) {
    }
}

final readonly class DtoRuntimeCreatedUser
{
    public function __construct(
        public string $id,
        public DtoRuntimeRole $role,
    ) {
    }
}

final class DtoRuntimeMutableUser
{
    public string $name;
    public ?int $age = null;
}

final readonly class DtoRuntimeUnsupportedArray
{
    /** @param array<string, mixed> $items */
    public function __construct(public array $items)
    {
    }
}

final readonly class DtoRuntimeUnionA
{
    public function __construct(public string $name)
    {
    }
}

final readonly class DtoRuntimeUnionB
{
    public function __construct(public string $name)
    {
    }
}

final class DtoRuntimeMappingTest extends TestCase
{
    public function testHydratesConstructorDtoInputAndNormalizesDtoOutput(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler(
                'create',
                static function (Context $ctx, DtoRuntimeCreateUser $user): DtoRuntimeCreatedUser {
                    self::assertSame('Ada', $user->name);
                    self::assertNull($user->email);
                    self::assertSame(DtoRuntimeRole::Admin, $user->role);
                    self::assertInstanceOf(DtoRuntimeProfile::class, $user->profile);
                    self::assertSame('Ada Lovelace', $user->profile->displayName);

                    return new DtoRuntimeCreatedUser('user-1', $user->role);
                },
            ),
        ]);

        $output = $registry->invoke('Users', 'create', json_encode([
            'name' => 'Ada',
            'email' => null,
            'role' => 'admin',
            'profile' => ['displayName' => 'Ada Lovelace'],
            'ignored' => true,
        ], JSON_THROW_ON_ERROR), $this->context());

        self::assertSame(['id' => 'user-1', 'role' => 'admin'], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testHydratesMutablePropertyDtoInputAndKeepsDefaultNullableProperty(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler(
                'update',
                static fn (Context $ctx, DtoRuntimeMutableUser $user): array => [
                    'name' => $user->name,
                    'age' => $user->age,
                ],
            ),
        ]);

        $output = $registry->invoke('Users', 'update', '{"name":"Ada"}', $this->context());

        self::assertSame(['name' => 'Ada', 'age' => null], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testMissingRequiredDtoFieldFailsAsBadRequest(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler('create', static fn (Context $ctx, DtoRuntimeCreateUser $user): array => []),
        ]);

        try {
            $registry->invoke('Users', 'create', '{"email":null}', $this->context());
            self::fail('Expected missing DTO field to fail.');
        } catch (TerminalError $e) {
            self::assertSame(400, $e->statusCode());
            self::assertSame('Invalid JSON value at $.name: required field is missing.', $e->getMessage());
        }
    }

    public function testEmptyJsonObjectDtoInputFailsOnMissingRequiredField(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler('create', static fn (Context $ctx, DtoRuntimeCreateUser $user): array => []),
        ]);

        try {
            $registry->invoke('Users', 'create', '{}', $this->context());
            self::fail('Expected empty JSON object DTO input to fail on a missing field.');
        } catch (TerminalError $e) {
            self::assertSame(400, $e->statusCode());
            self::assertSame('Invalid JSON value at $.name: required field is missing.', $e->getMessage());
        }
    }

    public function testJsonListDtoInputFailsAsNonObject(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler('create', static fn (Context $ctx, DtoRuntimeCreateUser $user): array => []),
        ]);

        try {
            $registry->invoke('Users', 'create', '["Ada"]', $this->context());
            self::fail('Expected JSON list DTO input to fail.');
        } catch (TerminalError $e) {
            self::assertSame(400, $e->statusCode());
            self::assertSame('Invalid JSON value at $: expected object, got array.', $e->getMessage());
        }
    }

    public function testEmptyJsonListDtoInputFailsAsNonObject(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler('create', static fn (Context $ctx, DtoRuntimeCreateUser $user): array => []),
        ]);

        try {
            $registry->invoke('Users', 'create', '[]', $this->context());
            self::fail('Expected empty JSON list DTO input to fail.');
        } catch (TerminalError $e) {
            self::assertSame(400, $e->statusCode());
            self::assertSame('Invalid JSON value at $: expected object, got array.', $e->getMessage());
        }
    }

    public function testInvalidBackedEnumInputFailsAsBadRequest(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler('create', static fn (Context $ctx, DtoRuntimeCreateUser $user): array => []),
        ]);

        try {
            $registry->invoke('Users', 'create', '{"name":"Ada","email":null,"role":"owner"}', $this->context());
            self::fail('Expected invalid enum input to fail.');
        } catch (TerminalError $e) {
            self::assertSame(400, $e->statusCode());
            self::assertSame('Invalid JSON value at $.role: expected one of admin, member, got string.', $e->getMessage());
        }
    }

    public function testExplicitJsonPayloadStillAllowsDtoMapping(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler(
                'explicit',
                static fn (Context $ctx, DtoRuntimeCreateUser $user): string => $user->name,
                Payload::json(['type' => 'object'], required: true),
            ),
        ]);

        self::assertSame('"Ada"', $registry->invoke('Users', 'explicit', '{"name":"Ada","email":null}', $this->context()));
    }

    public function testUnsupportedRequiredDtoInputFailsAsServerError(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler('unsupported', static fn (Context $ctx, DtoRuntimeUnsupportedArray $input): array => []),
        ]);

        try {
            $registry->invoke('Users', 'unsupported', '{"items":[]}', $this->context());
            self::fail('Expected unsupported DTO input to fail.');
        } catch (TerminalError $e) {
            self::assertSame(500, $e->statusCode());
            self::assertSame('DTO mapping is not available for Users/unsupported input.', $e->getMessage());
        }
    }

    public function testUnsupportedDtoOutputFailsAsServerError(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler('unsupportedOutput', static fn (Context $ctx): DtoRuntimeUnsupportedArray => new DtoRuntimeUnsupportedArray([])),
        ]);

        try {
            $registry->invoke('Users', 'unsupportedOutput', '', $this->context());
            self::fail('Expected unsupported DTO output to fail.');
        } catch (TerminalError $e) {
            self::assertSame(500, $e->statusCode());
            self::assertSame('DTO mapping is not available for Users/unsupportedOutput output.', $e->getMessage());
        }
    }

    public function testAmbiguousDtoUnionInputFailsAsServerError(): void
    {
        $registry = new ServiceRegistry([
            Service::new('Users')->handler('ambiguous', static fn (Context $ctx, DtoRuntimeUnionA|DtoRuntimeUnionB $input): array => []),
        ]);

        try {
            $registry->invoke('Users', 'ambiguous', '{"name":"Ada"}', $this->context());
            self::fail('Expected ambiguous DTO union input to fail.');
        } catch (TerminalError $e) {
            self::assertSame(500, $e->statusCode());
            self::assertSame('DTO mapping is not available for Users/ambiguous input.', $e->getMessage());
        }
    }

    private function context(): InvocationAttempt
    {
        return new InvocationAttempt(ScriptedInvocation::unused());
    }
}
